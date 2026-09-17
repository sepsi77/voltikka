<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

use App\Enums\MeteringType;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\CurrentSourcePromotionEvidence;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetPremiumCandidate;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\PromotionTermsAssessment;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentNormalCandidateExtractor;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Collection;

/** Request-local canonical evidence only. Never reads or writes the stored premium dataset. */
class CurrentPremiumEvidenceLoader
{
    private array $evidence = [];

    private array $identities = [];

    public function __construct(
        private readonly CanonicalContractPriceCalculator $calculator,
        private readonly MarketReferenceCurveProvider $curve,
        private readonly ResetEstimatorSettings $settings = new ResetEstimatorSettings,
        private readonly float $vatMultiplier = 1.255,
    ) {}

    public function resetMemoization(): void
    {
        $this->evidence = $this->identities = [];
    }

    /** @return array<string, PremiumEstimate|null> */
    public function forCandidates(array $candidates, Collection $contracts, array $anchors, CarbonImmutable $asOf, array $resetCandidates = []): array
    {
        $models = $contracts->keyBy('id');
        $needed = [];
        foreach ($candidates + $resetCandidates as $id => $candidate) {
            $company = $models->get($id)?->company_name;
            if (! is_string($company) || trim($company) === '') {
                continue;
            }
            if ($candidate instanceof ResetPremiumCandidate) {
                if ($this->resetReference($candidate, $asOf) !== null || ! $this->hasCurrentCurve($asOf, $candidate->tailMonthKeys)) {
                    continue;
                }
            } else {
                $start = ($anchors[$id] ?? null)?->startedAt;
                if ($start !== null && $this->curve->referencePrice($start->min($asOf), $start->startOfMonth(), ['month']) !== null) {
                    continue;
                }
                if (! $this->hasCurrentCurve($asOf)) {
                    continue;
                }
            }
            $needed[$id] = $candidate;
        }
        if ($needed === []) {
            return [];
        }
        $key = $asOf->toDateString();
        if (! array_key_exists($key, $this->evidence)) {
            $this->load($asOf);
        }
        $missingIds = array_diff(array_keys($needed), array_keys($this->identities[$key]));
        if ($missingIds !== []) {
            $this->identities[$key] += ElectricityContract::getLineageIdentitiesByContractIds($missingIds);
        }
        $result = [];
        foreach ($needed as $id => $candidate) {
            $result[$id] = (new ForwardPremiumResolver)->resolve(new PremiumTarget(
                (string) $id,
                $this->identities[$key][$id]['key'],
                $models->get($id)->company_name,
                $this->compatibility($candidate),
                $asOf,
            ), $this->evidence[$key]);
        }

        return $result;
    }

    private function hasCurrentCurve(CarbonImmutable $asOf, ?array $monthKeys = null): bool
    {
        $trade = $this->curve->tradeDate($asOf);
        if ($trade === null || ! $trade->lt($asOf) || $trade->diffInDays($asOf) > $this->settings->maxCurveAgeDays) {
            return false;
        }
        if ($monthKeys === null) {
            $monthKeys = [];
            $end = $asOf->addMonthsNoOverflow(12);
            for ($month = $asOf->startOfMonth()->addMonth(); $month->lt($end); $month = $month->addMonth()) {
                $monthKeys[] = $month->format('Y-m');
            }
        }
        foreach ($monthKeys as $key) {
            $month = CarbonImmutable::parse($key.'-01', 'Europe/Helsinki');
            $point = $this->curve->forwardPriceForMonth($asOf, $month);
            if ($point === null || ! is_finite((float) $point['price_cents_per_kwh'])) {
                return false;
            }
        }

        return true;
    }

    private function load(CarbonImmutable $asOf): void
    {
        $key = $asOf->toDateString();
        $peers = ElectricityContract::query()->active()
            ->join('contract_source_observations as premium_observation', 'premium_observation.id', '=', 'electricity_contracts.current_source_observation_id')
            ->join('contract_interpretations as premium_publication', 'premium_publication.id', '=', 'electricity_contracts.published_interpretation_id')
            ->whereIn('electricity_contracts.contract_type', ['OpenEnded', 'FixedTerm'])
            ->whereIn('electricity_contracts.pricing_model', ['FixedPrice', 'Hybrid'])
            ->whereIn('electricity_contracts.metering', ['General', 'Time', 'Season'])
            ->where('premium_observation.first_observed_at', '<=', $asOf->endOfDay()->utc()->format('Y-m-d H:i:s'))
            ->where('premium_publication.completed_at', '<=', $asOf->endOfDay()->utc()->format('Y-m-d H:i:s'))
            ->get([...array_map(fn ($column) => 'electricity_contracts.'.$column, [
                'id', 'company_name', 'pricing_model', 'contract_type', 'fixed_time_range', 'metering', 'target_group',
                'canonical_pricing', 'canonical_calculation', 'canonical_source_consistency',
                'current_source_observation_id', 'published_interpretation_id',
            ]), 'premium_observation.last_observed_at as premium_last_observed_at']);
        $observedDates = [];
        $proof = (new CurrentSourcePromotionEvidence)->forContracts($peers, $asOf);
        $candidates = [];
        foreach ($peers as $peer) {
            if (! is_string($peer->company_name) || trim($peer->company_name) === '' || ! ($proof[$peer->id]['valid'] ?? false)
                || (($proof[$peer->id]['energy_rules_required'] ?? false) && ! ($proof[$peer->id]['energy_rules_valid'] ?? false))) {
                continue;
            }
            try {
                $timestamp = $peer->premium_last_observed_at;
                if (! is_string($timestamp) || $timestamp === '') {
                    continue;
                }
                $observed = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp, 'UTC');
                if (! $observed || $observed->format('Y-m-d H:i:s') !== $timestamp) {
                    continue;
                }
                $observedDates[$peer->id] = $observed->setTimezone('Europe/Helsinki')->startOfDay()->min($asOf);
                $data = (new CanonicalPricingParser)->parse($peer->canonical_pricing, $peer->canonical_calculation, $peer->canonical_source_consistency, withEnergyRules: $proof[$peer->id]['energy_rules_valid'] ?? false)
                    ->withComparisonEvidence(sourceCampaignEnergyRates: $proof[$peer->id]['rates']);
                if ((new PromotionTermsAssessment)->isIncomplete($data, $asOf, $asOf->addMonthsNoOverflow(12))) {
                    continue;
                }
                $context = ContractContext::fromContract($peer);
                if (EnergyRulePlan::hasKnownRules($data)) {
                    $candidate = (new CurrentNormalCandidateExtractor)->candidate((string) $peer->id, $data, $context, $asOf);
                    if ($candidate === null && $data->recurringSchedule->isActiveReset()) {
                        $target = $this->calculator->normalEnergyTargetData($data, $context, $asOf);
                        $candidate = $target === null ? null : $this->calculator->resetPremiumCandidate((string) $peer->id, $target, $context, $asOf);
                    }
                } else {
                    $candidate = $this->calculator->supplierAdjustedCandidate((string) $peer->id, $data, $context, $asOf)
                        ?? $this->calculator->resetPremiumCandidate((string) $peer->id, $data, $context, $asOf);
                }
                if ($candidate !== null) {
                    $candidates[$peer->id] = $candidate;
                }
            } catch (CanonicalPricingParseException|InvalidFormatException) {
                continue;
            }
        }
        $anchors = (new CurrentPriceEpisodeResolver($this->calculator))->resolve(
            array_filter($candidates, fn ($candidate) => $candidate instanceof SupplierAdjustedCandidate), $asOf,
        );
        $this->identities[$key] = ElectricityContract::getLineageIdentitiesByContractIds(array_keys($candidates));
        $this->evidence[$key] = [];
        foreach ($peers as $peer) {
            $candidate = $candidates[$peer->id] ?? null;
            $start = ($anchors[$peer->id] ?? null)?->startedAt;
            $observed = $observedDates[$peer->id] ?? null;
            if ($candidate instanceof ResetPremiumCandidate && $observed !== null) {
                try {
                    $observation = $this->resetObservation($candidate, $peer, $observed, $asOf);
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
                if ($observation !== null) {
                    $this->evidence[$key][] = $observation;
                }

                continue;
            }
            if ($candidate === null || $start === null || $observed === null || $start->gt($observed)) {
                continue;
            }
            $reference = $this->curve->referencePrice($start->min($asOf), $start->startOfMonth(), ['month']);
            $trade = isset($reference['trade_date']) ? CarbonImmutable::parse($reference['trade_date'], 'Europe/Helsinki')->startOfDay() : null;
            if ($reference === null || $trade === null || ! $trade->lt($start) || ! $trade->lt($asOf)
                || ! is_finite((float) $reference['price_cents_per_kwh'])) {
                continue;
            }
            $referenceRate = $reference['price_cents_per_kwh'] * ($candidate->includesVat ? 1.0 : 1 / $this->vatMultiplier);
            $rates = $candidate->normalizedEnergyRates();
            $this->evidence[$key][] = new PremiumObservation(
                $this->identities[$key][$peer->id]['key'], $peer->company_name, $this->compatibility($candidate),
                array_map(fn ($rate) => $rate - $referenceRate, $rates),
                json_encode([$candidate->metering, $candidate->includesVat, $candidate->pricingMechanism, $rates], JSON_THROW_ON_ERROR),
                $observed, $trade, $start, $observed, $start->startOfMonth(), $start->endOfMonth()->startOfDay(),
                'canonical_current_energy_episode_proxy;target_audience_vat_normalized_unknown_assumed;observation='.$peer->current_source_observation_id.';publication='.$peer->published_interpretation_id,
                pricingDate: $start, referencePeriodProxy: true,
            );
        }
    }

    private function resetReference(ResetPremiumCandidate $candidate, CarbonImmutable $asOf): ?array
    {
        $bound = $candidate->currentPeriodStart->min($asOf);
        $reference = $this->curve->referencePrice($bound, $candidate->anchorPeriodMonth, $candidate->referenceKindPreference());
        if ($reference === null || ! is_finite((float) $reference['price_cents_per_kwh'])
            || ! is_string($reference['trade_date'] ?? null)) {
            return null;
        }
        $trade = CarbonImmutable::parse($reference['trade_date'], 'Europe/Helsinki')->startOfDay();

        return $trade->lt($bound) ? $reference : null;
    }

    private function resetObservation(ResetPremiumCandidate $candidate, ElectricityContract $peer, CarbonImmutable $observed, CarbonImmutable $asOf): ?PremiumObservation
    {
        $reference = $this->resetReference($candidate, $asOf);
        if ($reference === null) {
            return null;
        }
        $trade = CarbonImmutable::parse($reference['trade_date'], 'Europe/Helsinki')->startOfDay();
        $pricingDate = $candidate->currentPeriodStart->min($asOf);
        if (! $trade->lt($pricingDate)) {
            return null;
        }
        $deliveryStart = $candidate->cadence === 'monthly'
            ? $candidate->anchorPeriodMonth->startOfMonth() : $candidate->anchorPeriodMonth->startOfQuarter();
        $deliveryEnd = $candidate->cadence === 'monthly'
            ? $deliveryStart->endOfMonth()->startOfDay() : $deliveryStart->endOfQuarter()->startOfDay();
        $periodEnd = $candidate->tailStart->subDay();
        $proxy = in_array($candidate->cadence, ['seasonal', 'other'], true)
            || ! $candidate->currentPeriodStart->eq($deliveryStart) || ! $periodEnd->eq($deliveryEnd);
        $referenceRate = $reference['price_cents_per_kwh'] * ($candidate->includesVat ? 1.0 : 1 / $this->vatMultiplier);
        $rates = $candidate->normalizedEnergyRates();

        return new PremiumObservation(
            $this->identities[$asOf->toDateString()][$peer->id]['key'], $peer->company_name, $this->compatibility($candidate),
            array_map(fn ($rate) => $rate - $referenceRate, $rates),
            json_encode([$candidate->metering, $candidate->includesVat, $candidate->cadence, $rates], JSON_THROW_ON_ERROR),
            $observed, $trade, $candidate->currentPeriodStart, $periodEnd, $deliveryStart, $deliveryEnd,
            'canonical_current_reset;reference_kind='.$reference['kind'].';target_audience_vat_normalized_unknown_assumed;observation='.$peer->current_source_observation_id.';publication='.$peer->published_interpretation_id,
            pricingDate: $pricingDate, referencePeriodProxy: $proxy,
        );
    }

    private function compatibility(SupplierAdjustedCandidate|ResetPremiumCandidate $candidate): PremiumCompatibility
    {
        $hybrid = $candidate->pricingMechanism === 'Hybrid';
        $family = $candidate instanceof ResetPremiumCandidate
            ? ($hybrid ? PremiumFamily::MarketResetHybridBase : PremiumFamily::MarketReset)
            : ($hybrid ? PremiumFamily::SupplierAdjustedHybridBase : PremiumFamily::SupplierAdjusted);

        return new PremiumCompatibility(
            $family, MeteringType::from($candidate->metering),
            $candidate->includesVat ? PremiumVatBasis::Included : PremiumVatBasis::Excluded,
            array_map(fn ($bucket) => ComponentType::from($bucket), array_keys($candidate->normalizedEnergyRates())),
            $candidate instanceof ResetPremiumCandidate ? $candidate->cadence : null,
        );
    }
}
