<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted;

use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationProfile;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;

/** Read-only observed energy episodes. An anchor is a proxy, not a seller hedge date. */
class CurrentPriceEpisodeResolver
{
    public function __construct(private readonly ?CanonicalContractPriceCalculator $calculator = null) {}

    /**
     * @param  array<string, SupplierAdjustedCandidate>  $candidates
     * @return array<string, PriceEpisodeAnchor>
     */
    public function resolve(array $candidates, ?CarbonInterface $asOf = null): array
    {
        if ($candidates === []) {
            return [];
        }
        $asOf = ($asOf === null ? CarbonImmutable::now('Europe/Helsinki') : CarbonImmutable::instance($asOf)->setTimezone('Europe/Helsinki'))->startOfDay();
        $lineages = ElectricityContract::getReplacementLineageIdsByContractIds(array_keys($candidates));
        $ids = collect($lineages)->flatten()->unique()->values()->all();
        $snapshots = DB::table('contract_price_snapshots')
            ->whereIn('contract_id', $ids)
            ->where('snapshot_date', '<=', $asOf->toDateString())
            ->orderBy('snapshot_date')
            ->get(['contract_id', 'snapshot_date', 'pricing_basis', 'energy_price_cents_per_kwh', 'metering', 'pricing_model'])
            ->groupBy('contract_id');
        $observations = DB::table('contract_source_observations as observations')
            ->leftJoin('contract_source_snapshots as sources', function ($join): void {
                $join->on('sources.id', '=', 'observations.source_snapshot_id')->on('sources.contract_id', '=', 'observations.contract_id');
            })
            ->join('electricity_contracts as contracts', 'contracts.id', '=', 'observations.contract_id')
            ->whereIn('observations.contract_id', $ids)
            ->where('observations.first_observed_at', '<=', $asOf->endOfDay()->utc()->format('Y-m-d H:i:s'))
            ->orderBy('observations.first_observed_at')
            ->get(['observations.id', 'observations.contract_id', 'observations.source_snapshot_id', 'observations.first_observed_at', 'observations.last_observed_at', 'sources.source_payload', 'contracts.current_source_observation_id', 'contracts.published_interpretation_id'])
            ->groupBy('contract_id');
        $interpretations = DB::table('contract_interpretations')
            ->whereIn('contract_id', $ids)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $asOf->endOfDay()->utc()->format('Y-m-d H:i:s'))
            ->get(['id', 'contract_id', 'source_snapshot_id', 'analysis_source_observation_id', 'status', 'output', 'validation_errors', 'completed_at', 'published_at', 'schema_version', 'prompt_version', 'validator_version'])
            ->groupBy('source_snapshot_id');

        $historicalTariffs = $this->historicalTariffs($candidates, $lineages, $observations, $asOf);
        $resolved = [];
        foreach ($candidates as $id => $candidate) {
            $events = [];
            $intervals = [];
            $rawEvents = [];
            foreach ($lineages[$id] as $carrierId) {
                $carrierObservations = $observations->get($carrierId, collect());
                $firstSourceDate = null;
                foreach ($carrierObservations as $observation) {
                    $start = $this->observationDate($observation->first_observed_at);
                    $end = $observation->last_observed_at === null ? $start : $this->observationDate($observation->last_observed_at)->min($asOf);
                    $firstSourceDate ??= $start->toDateString();
                    $evidence = $this->sourceCandidate($observation, $interpretations->get($observation->source_snapshot_id, collect())->all(), $candidate->normalTariffEvidence, $asOf, (string) $carrierId === (string) $id);
                    $intervals[] = ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'candidate' => $evidence,
                        'current' => (string) $carrierId === (string) $id && (int) $observation->current_source_observation_id === (int) $observation->id];
                    $events[$start->toDateString()] ??= [];
                    $events[$end->toDateString()] ??= [];
                }
                if (! $candidate->normalTariffEvidence && in_array($candidate->metering, ['Time', 'Season'], true)) {
                    foreach ($historicalTariffs[$carrierId] ?? [] as $date => $rows) {
                        $rawEvents[$date][$carrierId] = $this->historicalTariffCandidate($rows, $candidate);
                        $events[$date] ??= [];
                    }
                }
                foreach ($snapshots->get($carrierId, collect()) as $snapshot) {
                    $date = substr((string) $snapshot->snapshot_date, 0, 10);
                    // Once immutable chronology starts, gaps and invalid output cannot reopen raw evidence.
                    if ($candidate->normalTariffEvidence || ($firstSourceDate !== null && $date >= $firstSourceDate)) {
                        continue;
                    }
                    $events[$date][] = $snapshot;
                }
            }
            // Inclusive ends can remove conflicting evidence while another source still covers the next day.
            foreach ($intervals as $interval) {
                $nextDate = CarbonImmutable::parse($interval['end'], 'Europe/Helsinki')->addDay()->toDateString();
                if ($nextDate <= $asOf->toDateString()
                    && collect($intervals)->contains(fn (array $other): bool => $other['start'] <= $nextDate && $other['end'] >= $nextDate)) {
                    $events[$nextDate] ??= [];
                }
            }
            ksort($events);
            $start = null;
            $previous = null;
            $flags = ['price_episode_observed_proxy', 'price_episode_left_censored'];
            $basis = PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun;
            $rawStart = false;
            foreach ($events as $date => $dailySnapshots) {
                $covering = array_values(array_filter($intervals, fn (array $interval): bool => $interval['start'] <= $date && $interval['end'] >= $date));
                $dailyBasis = PriceEpisodeEvidenceBasis::CanonicalSnapshotRun;
                if ($covering !== []) {
                    $evidence = array_column($covering, 'candidate');
                    $dailyBasis = PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun;
                } elseif (isset($rawEvents[$date])) {
                    $evidence = array_values($rawEvents[$date]);
                    foreach ($dailySnapshots as $snapshot) {
                        if (! array_key_exists($snapshot->contract_id, $rawEvents[$date])) {
                            $evidence[] = null;
                        }
                    }
                    $dailyBasis = PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun;
                } else {
                    $known = collect($dailySnapshots)->filter(fn ($row): bool => $row->energy_price_cents_per_kwh !== null);
                    $observed = $known->where('pricing_basis', 'observed_seller_data');
                    $selected = $observed->isNotEmpty() ? $observed : $known;
                    $evidence = $selected->map(fn ($row) => $this->snapshotCandidate($row))->all();
                    $dailyBasis = $observed->isNotEmpty() ? PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun : PriceEpisodeEvidenceBasis::CanonicalSnapshotRun;
                }
                $matches = $evidence !== [] && collect($evidence)->every(fn ($item): bool => $item !== null && $candidate->hasSameEnergySignature($item));
                if (! $matches) {
                    $start = null;
                    $knownEvidence = array_values(array_filter($evidence, fn ($item): bool => $item !== null));
                    $reason = count($knownEvidence) !== count($evidence) || $evidence === []
                        ? 'unknown_or_conflicting_energy_evidence'
                        : (collect($knownEvidence)->every(fn ($item): bool => $knownEvidence[0]->hasSameEnergySignature($item))
                            ? 'different_observed_energy_signature'
                            : 'conflicting_energy_signatures');
                    $flags = ['price_episode_observed_proxy', 'price_episode_left_censored', 'prior_energy_evidence_changed_unknown_or_conflicting', $reason];
                    $previous = $date;

                    continue;
                }
                if ($start === null) {
                    $start = $date;
                    $basis = $dailyBasis;
                    $rawStart = $covering === [] && isset($rawEvents[$date]);
                    if ($rawStart) {
                        $flags[] = 'observed_full_tariff_energy_evidence';
                    }
                } else {
                    $continuouslyObserved = collect($intervals)->contains(fn (array $interval): bool => $interval['start'] <= $previous && $interval['end'] >= $date && $interval['candidate'] !== null && $candidate->hasSameEnergySignature($interval['candidate']));
                    if (! $continuouslyObserved && CarbonImmutable::parse($previous)->addDay()->toDateString() < $date) {
                        $flags[] = 'price_episode_observation_gap';
                        if ($candidate->normalTariffEvidence) {
                            $start = $date;
                        }
                    }
                    if ($rawStart) {
                        if ($dailyBasis === PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun) {
                            $flags[] = 'canonical_source_energy_continuation';
                        }
                    } elseif ($dailyBasis === PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun || $basis === PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun) {
                        $basis = PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun;
                    } elseif ($dailyBasis === PriceEpisodeEvidenceBasis::CanonicalSnapshotRun) {
                        $basis = $dailyBasis;
                    }
                }
                $previous = $date;
            }
            if ($previous !== null && $previous < $asOf->toDateString()) {
                $flags[] = 'price_episode_right_observation_gap';
                if ($candidate->normalTariffEvidence
                    && ! collect($intervals)->contains(fn (array $interval): bool => $interval['current'] && $interval['end'] === $previous
                        && $interval['candidate'] !== null && $candidate->hasSameEnergySignature($interval['candidate']))) {
                    $start = null;
                }
            }
            $resolved[$id] = new PriceEpisodeAnchor(
                $start === null ? null : CarbonImmutable::parse($start, 'Europe/Helsinki')->startOfDay(),
                $start === null ? PriceEpisodeEvidenceBasis::Missing : $basis,
                array_values(array_unique($start === null ? [...$flags, 'missing_price_episode_anchor', 'full_energy_signature_not_proven'] : $flags)),
            );
        }

        return $resolved;
    }

    /** One compact, date-bounded query only for requested multi-rate lineages. */
    private function historicalTariffs(array $candidates, array $lineages, $observations, CarbonImmutable $asOf): array
    {
        $ids = [];
        foreach ($candidates as $id => $candidate) {
            if (! $candidate->normalTariffEvidence && in_array($candidate->metering, ['Time', 'Season'], true)) {
                foreach ($lineages[$id] as $carrierId) {
                    $ids[$carrierId] = $carrierId;
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('price_components as components')
            ->leftJoin('contract_price_snapshots as identity', function ($join): void {
                $join->on('identity.contract_id', '=', 'components.electricity_contract_id')
                    ->on('identity.snapshot_date', '=', 'components.price_date')
                    ->where('identity.pricing_basis', '=', 'observed_seller_data');
            })
            ->where('components.price_date', '<=', $asOf->toDateString())
            ->where(function ($query) use ($ids, $observations): void {
                foreach ($ids as $id) {
                    $first = $observations->get($id, collect())->first();
                    $query->orWhere(function ($carrier) use ($id, $first): void {
                        $carrier->where('components.electricity_contract_id', $id);
                        if ($first !== null) {
                            $carrier->where('components.price_date', '<', $this->observationDate($first->first_observed_at)->toDateString());
                        }
                    });
                }
            })
            ->whereIn('components.price_component_type', ['DayTime', 'NightTime', 'SeasonalWinter', 'SeasonalWinterDay', 'SeasonalOther', 'General'])
            ->orderBy('components.price_date')
            ->get(['components.electricity_contract_id', 'components.price_date', 'components.price_component_type', 'components.price', 'components.payment_unit',
                'components.has_discount', 'components.discount_value', 'components.discount_is_percentage', 'components.discount_type',
                'components.discount_discount_n_first_kwh', 'components.discount_discount_n_first_months', 'components.discount_discount_until_date',
                'identity.metering', 'identity.pricing_model', 'identity.contract_type']);
        $result = [];
        foreach ($rows as $row) {
            $result[$row->electricity_contract_id][substr((string) $row->price_date, 0, 10)][] = $row;
        }

        return $result;
    }

    /** Historical household snapshot membership proves the VAT convention, not current VAT metadata. */
    private function historicalTariffCandidate(array $rows, SupplierAdjustedCandidate $selected): ?SupplierAdjustedCandidate
    {
        // Observed statistics include Household/Both/null only. They cannot prove Company VAT0 history.
        if (! $selected->includesVat || $selected->pricingMechanism !== 'FixedPrice') {
            return null;
        }
        $expected = $selected->metering === 'Time'
            ? ['energy_day', 'energy_night'] : ['energy_seasonal_other', 'energy_seasonal_winter'];
        $rates = [];
        foreach ($rows as $row) {
            if ($row->metering !== $selected->metering || $row->pricing_model !== 'FixedPrice' || $row->contract_type !== 'OpenEnded'
                || ! in_array($row->payment_unit, ['CentPerKilowattHour', 'CentPerKiwattHour', 'CentPerKiloWattHour', 'c/kWh'], true)
                || ! is_numeric($row->price) || ! is_finite((float) $row->price) || (float) $row->price < 0) {
                return null;
            }
            // Relative customer windows and residual promo metadata cannot prove a dated tariff.
            if (! $this->hasNoHistoricalDiscount($row)) {
                return null;
            }
            $type = match ($row->price_component_type) {
                'DayTime' => 'energy_day',
                'NightTime' => 'energy_night',
                'SeasonalWinter', 'SeasonalWinterDay' => 'energy_seasonal_winter',
                'SeasonalOther' => 'energy_seasonal_other',
                default => null,
            };
            if (! in_array($type, $expected, true) || (isset($rates[$type]) && $rates[$type] !== (float) $row->price)) {
                return null;
            }
            $rates[$type] = (float) $row->price;
        }
        ksort($rates);
        if (array_keys($rates) !== $expected) {
            return null;
        }

        return new SupplierAdjustedCandidate((string) $rows[0]->electricity_contract_id, 0, 0, $rates, $selected->metering);
    }

    private function hasNoHistoricalDiscount(object $row): bool
    {
        if (! in_array($row->discount_type, [null, 'NoDiscount'], true)
            || $row->discount_discount_until_date !== null
            || ! in_array($row->has_discount, [null, false, 0, '0'], true)
            || ! in_array($row->discount_is_percentage, [null, false, 0, '0'], true)) {
            return false;
        }
        foreach ([$row->discount_value, $row->discount_discount_n_first_kwh, $row->discount_discount_n_first_months] as $value) {
            if ($value !== null && (! is_numeric($value) || ! is_finite((float) $value) || (float) $value !== 0.0)) {
                return false;
            }
        }

        return true;
    }

    /** General statistics are exact singleton energy evidence; multi-rate averages are not. */
    private function snapshotCandidate(object $row): ?SupplierAdjustedCandidate
    {
        if ($row->metering !== 'General' || $row->pricing_model !== 'FixedPrice'
            || $row->energy_price_cents_per_kwh === null || ! is_finite((float) $row->energy_price_cents_per_kwh)) {
            return null;
        }

        return new SupplierAdjustedCandidate((string) $row->contract_id, (float) $row->energy_price_cents_per_kwh, 0, metering: 'General');
    }

    /** @param list<object> $rows */
    private function sourceCandidate(object $observation, array $rows, bool $normalTariffEvidence = false, ?CarbonImmutable $asOf = null, bool $currentCarrier = true): ?SupplierAdjustedCandidate
    {
        $payload = json_decode((string) $observation->source_payload, true);
        $details = $payload['Details'] ?? [];
        if (! is_array($details)
            || ! is_string($details['PricingModel'] ?? null)
            || ! is_string($details['ContractType'] ?? null)
            || ! is_string($details['Metering'] ?? null)
            || (isset($details['TargetGroup']) && ! is_string($details['TargetGroup']))) {
            return null;
        }
        $context = new ContractContext($details['PricingModel'], $details['ContractType'], $details['Metering'], null, $details['TargetGroup'] ?? null);
        $candidate = null;
        foreach ($rows as $row) {
            // Successful analysis stores SQL NULL; JSON null and [] are also empty success.
            if ($row->validation_errors !== null) {
                try {
                    $errors = json_decode((string) $row->validation_errors, false, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if ($errors !== null && $errors !== []) {
                    continue;
                }
            }
            if ((string) $row->contract_id !== (string) $observation->contract_id
                || ! in_array($row->status, ['published', 'superseded'], true)
                || ($row->analysis_source_observation_id !== null && (int) $row->analysis_source_observation_id !== (int) $observation->id)) {
                continue;
            }
            if ((int) $observation->current_source_observation_id === (int) $observation->id
                && ((int) $observation->published_interpretation_id !== (int) $row->id || $row->status !== 'published')) {
                continue;
            }
            $output = json_decode((string) $row->output, true);
            if (! is_array($output['pricing'] ?? null) || ! is_array($output['calculation'] ?? null)
                || ! is_array($output['source_consistency'] ?? null)) {
                return null;
            }
            try {
                $withRules = false;
                if ($normalTariffEvidence) {
                    $profile = ContractInterpretationProfile::stored($row->schema_version, $row->prompt_version, $row->validator_version);
                    $withRules = $profile->schemaVersion === 'schema-v5';
                    if ($withRules) {
                        if ($row->published_at === null
                            || CarbonImmutable::parse($row->published_at, 'UTC')->lt(CarbonImmutable::parse($observation->first_observed_at, 'UTC'))
                            || ($observation->last_observed_at !== null && CarbonImmutable::parse($observation->last_observed_at, 'UTC')->lt(CarbonImmutable::parse($observation->first_observed_at, 'UTC')))
                            || CarbonImmutable::parse($row->published_at, 'UTC')->gt($asOf->endOfDay()->utc())
                            || CarbonImmutable::parse($row->published_at, 'UTC')->lt(CarbonImmutable::parse($row->completed_at, 'UTC'))) {
                            return null;
                        }
                        $snapshot = new ContractSourceSnapshot(['contract_id' => $observation->contract_id, 'source_payload' => $payload]);
                        $input = (new ContractInterpretationInputBuilder)->build($snapshot, $this->observationDate($observation->first_observed_at), $profile);
                        if ((new ContractInterpretationValidator)->validate($output, $input, $profile) !== []) {
                            return null;
                        }
                    }
                }
                $data = (new CanonicalPricingParser)->parse($output['pricing'] ?? null, $output['calculation'] ?? null, $output['source_consistency'] ?? null, withEnergyRules: $withRules);
                // The shared candidate proves current coverage and every full energy map.
                // A later absolute fee boundary alone is not a later energy observation.
                $next = $withRules
                    ? (new CurrentNormalCandidateExtractor)->candidate((string) $observation->contract_id, $data, $context, $this->observationDate($observation->first_observed_at))
                    : ($this->calculator ?? app(CanonicalContractPriceCalculator::class))->supplierAdjustedCandidate(
                        (string) $observation->contract_id, $data, $context, $this->observationDate($observation->first_observed_at),
                    );
                if ($withRules && $next !== null) {
                    $last = $observation->last_observed_at === null
                        ? $this->observationDate($observation->first_observed_at)
                        : $this->observationDate($observation->last_observed_at)->min($asOf);
                    $lastCandidate = (new CurrentNormalCandidateExtractor)->candidate((string) $observation->contract_id, $data, $context, $last);
                    if ($lastCandidate === null || ! $next->hasSameEnergySignature($lastCandidate)) {
                        return null;
                    }
                }
                // An unchanged pointed publication remains current after midnight. Its dated
                // observation stays the anchor, but the normal scope must still apply today.
                if ($normalTariffEvidence && $currentCarrier && $next !== null
                    && (int) $observation->current_source_observation_id === (int) $observation->id) {
                    $current = $withRules
                        ? (new CurrentNormalCandidateExtractor)->candidate((string) $observation->contract_id, $data, $context, $asOf)
                        : ($this->calculator ?? app(CanonicalContractPriceCalculator::class))->supplierAdjustedCandidate((string) $observation->contract_id, $data, $context, $asOf);
                    if ($current === null || ! $next->hasSameEnergySignature($current)) {
                        return null;
                    }
                }
            } catch (CanonicalPricingParseException|InvalidFormatException|\InvalidArgumentException|\TypeError) {
                return null;
            }
            if ($next === null || ($candidate !== null && ! $candidate->hasSameEnergySignature($next))) {
                return null;
            }
            $candidate = $next;
        }

        return $candidate;
    }

    private function observationDate(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone('Europe/Helsinki')->startOfDay();
    }
}
