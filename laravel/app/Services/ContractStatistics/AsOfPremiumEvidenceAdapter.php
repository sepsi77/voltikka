<?php

namespace App\Services\ContractStatistics;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\ForwardPremium\ForwardPremiumResolver;
use App\Services\CanonicalPricing\ForwardPremium\PremiumCompatibility;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
use App\Services\CanonicalPricing\ForwardPremium\PremiumObservation;
use App\Services\CanonicalPricing\ForwardPremium\PremiumTarget;
use App\Services\CanonicalPricing\ForwardPremium\PremiumVatBasis;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetPremiumCandidate;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;

/** One date-local universe, shared by all consumptions. No current identity or publication reads. */
class AsOfPremiumEvidenceAdapter
{
    public function __construct(
        private readonly CanonicalContractPriceCalculator $calculator,
        private readonly HistoricalPriceEpisodeResolver $anchors,
        private readonly MarketReferenceCurveProvider $curve,
        private readonly float $vatMultiplier = 1.255,
    ) {}

    /** @param array<string, AsOfAnnualCostEvidence> $universe */
    public function resolve(array $universe, CarbonImmutable $target): array
    {
        $suppliers = $resets = $bases = $items = [];
        foreach ($universe as $item) {
            if (! $item->date->equalTo($target) || $item->canonicalData === null
                || $item->exclusionReason !== null || $item->householdAudienceConflict) {
                continue;
            }
            $context = new ContractContext($item->pricingModel, $item->contractType, $item->metering, $item->fixedTimeRange, targetGroup: 'Household');
            $supplier = $this->calculator->supplierAdjustedCandidate($item->contractId, $item->canonicalData, $context, $target, ComparisonPolicy::Current);
            $reset = $this->calculator->resetPremiumCandidate($item->contractId, $item->canonicalData, $context, $target);
            if ($supplier !== null) {
                $suppliers[$item->contractId] = $supplier;
            }
            if ($reset !== null) {
                $resets[$item->contractId] = $reset;
            }
            $bases[$item->contractId] = $item->pricingBasis;
            $items[$item->contractId] = $item;
        }
        $anchors = $this->anchors->resolve($target, $suppliers, $bases, AnnualCostMethodVersion::AsOfV3);
        $observations = [];
        foreach ($suppliers + $resets as $id => $candidate) {
            $item = $items[$id];
            if (trim($item->companyName ?? '') === '') {
                continue;
            }
            $reset = $candidate instanceof ResetPremiumCandidate;
            $start = $reset ? $candidate->currentPeriodStart : ($anchors[$id] ?? null)?->startedAt;
            if ($start === null || (! $reset && $start->gt($target))) {
                continue;
            }
            $bound = $start->min($target);
            $month = $reset ? $candidate->anchorPeriodMonth : $start->startOfMonth();
            $reference = $this->curve->referencePrice($bound, $month, $reset ? $candidate->referenceKindPreference() : ['month']);
            if ($reference === null || ! is_numeric($reference['price_cents_per_kwh'] ?? null)
                || ! is_finite((float) $reference['price_cents_per_kwh']) || ! is_string($reference['trade_date'] ?? null)) {
                continue;
            }
            try {
                $trade = CarbonImmutable::createFromFormat('!Y-m-d', $reference['trade_date'], 'Europe/Helsinki');
            } catch (\InvalidArgumentException|\ValueError) {
                continue;
            }
            if ($trade === false || $trade->toDateString() !== $reference['trade_date']
                || ! $trade->lt($bound) || ! $trade->lt($target)) {
                continue;
            }
            $deliveryStart = $reset && $candidate->cadence !== 'monthly' ? $month->startOfQuarter() : $month->startOfMonth();
            $deliveryEnd = $reset && $candidate->cadence !== 'monthly' ? $deliveryStart->endOfQuarter()->startOfDay() : $deliveryStart->endOfMonth()->startOfDay();
            $end = $reset ? $candidate->tailStart->subDay() : $target;
            $proxy = ! $reset || in_array($candidate->cadence, ['seasonal', 'other'], true)
                || ! $start->eq($deliveryStart) || ! $end->eq($deliveryEnd);
            $referenceRate = $reference['price_cents_per_kwh'] * ($candidate->includesVat ? 1.0 : 1 / $this->vatMultiplier);
            $rates = $candidate->normalizedEnergyRates();
            try {
                $observations[] = new PremiumObservation(
                    (string) $id, $item->companyName, $this->compatibility($candidate),
                    array_map(fn ($rate) => $rate - $referenceRate, $rates),
                    json_encode([$candidate->metering, $candidate->includesVat, $reset ? $candidate->cadence : $candidate->pricingMechanism, $rates], JSON_THROW_ON_ERROR),
                    $target, $trade, $start, $end, $deliveryStart, $deliveryEnd,
                    json_encode(['source_evidence_ids' => $item->sourceEvidenceIds,
                        'source_interpretation' => $item->sourceInterpretationProvenance?->toArray(),
                        'flags' => $item->provenanceFlags, 'anchor_flags' => ($anchors[$id] ?? null)?->flags,
                        'reference_kind' => $reference['kind'] ?? null], JSON_THROW_ON_ERROR),
                    pricingDate: $bound, referencePeriodProxy: $proxy,
                );
            } catch (\InvalidArgumentException) {
                // Unsupported reference periods or incomplete/non-finite buckets are not zero premiums.
                continue;
            }
        }
        $premiums = [];
        foreach ($suppliers + $resets as $id => $candidate) {
            if (trim($items[$id]->companyName ?? '') !== '') {
                $premiums[$id] = (new ForwardPremiumResolver)->resolve(new PremiumTarget(
                    (string) $id, (string) $id, $items[$id]->companyName, $this->compatibility($candidate), $target,
                ), $observations);
            }
        }

        return ['anchors' => $anchors, 'premiums' => $premiums];
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
