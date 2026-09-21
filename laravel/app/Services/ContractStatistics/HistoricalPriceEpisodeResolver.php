<?php

namespace App\Services\ContractStatistics;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HistoricalPriceEpisodeResolver
{
    private const TIMEZONE = 'Europe/Helsinki';

    /**
     * @param  array<string, SupplierAdjustedCandidate>  $candidates
     * @param  ContractPriceBasis|array<string, ContractPriceBasis>|null  $explicitHistoricalBasis
     * @return array<string, PriceEpisodeAnchor>
     */
    public function resolve(
        CarbonInterface $targetDate,
        array $candidates,
        ContractPriceBasis|array|null $explicitHistoricalBasis = null,
        AnnualCostMethodVersion $methodVersion = AnnualCostMethodVersion::AsOf,
    ): array {
        if ($candidates === []) {
            return [];
        }

        $target = CarbonImmutable::instance($targetDate)
            ->setTimezone(self::TIMEZONE)
            ->startOfDay();
        $targetDateString = $target->toDateString();
        if ($methodVersion === AnnualCostMethodVersion::AsOfV3) {
            return $this->resolveObservedProxies($target, $candidates, $explicitHistoricalBasis);
        }

        $rowsByContract = DB::table('contract_price_snapshots')
            ->whereIn('contract_id', array_keys($candidates))
            ->whereDate('snapshot_date', '<=', $targetDateString)
            ->orderBy('contract_id')
            ->orderBy('snapshot_date')
            ->get([
                'contract_id',
                'snapshot_date',
                'pricing_basis',
                'energy_price_cents_per_kwh',
                'monthly_fee_eur',
            ])
            ->groupBy('contract_id');

        $resolved = [];
        foreach ($candidates as $contractId => $candidate) {
            $contractRows = $rowsByContract->get($contractId, collect())->values();
            $candidateBasis = is_array($explicitHistoricalBasis)
                ? ($explicitHistoricalBasis[$contractId] ?? null)
                : $explicitHistoricalBasis;
            [$basis, $targetRow, $failureFlags] = $this->selectTargetEvidence(
                $contractRows,
                $candidate,
                $targetDateString,
                $candidateBasis,
            );

            if ($basis === null || $targetRow === null) {
                $resolved[$contractId] = $this->missing($failureFlags);

                continue;
            }

            $basisRows = $contractRows
                ->where('pricing_basis', $basis->value)
                ->values();
            $resolved[$contractId] = $this->resolveRun($basisRows, $candidate, $target, $basis);
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{0: ?ContractPriceBasis, 1: ?object, 2: list<string>}
     */
    private function selectTargetEvidence(
        Collection $rows,
        SupplierAdjustedCandidate $candidate,
        string $targetDate,
        ?ContractPriceBasis $explicitHistoricalBasis,
    ): array {
        $targetRows = $rows->filter(
            fn (object $row): bool => $this->date($row)->toDateString() === $targetDate
        )->values();

        $observed = $targetRows->first(
            fn (object $row): bool => $row->pricing_basis === ContractPriceBasis::ObservedSellerData->value
                && $this->matches($row, $candidate)
        );
        if ($observed !== null) {
            return [ContractPriceBasis::ObservedSellerData, $observed, []];
        }

        if ($explicitHistoricalBasis === null) {
            if ($targetRows->isEmpty()) {
                return [null, null, ['missing_target_date_snapshot']];
            }
            if ($targetRows->contains(
                fn (object $row): bool => $row->pricing_basis === ContractPriceBasis::ObservedSellerData->value
            )) {
                return [null, null, ['target_snapshot_price_mismatch']];
            }

            return [null, null, ['historical_snapshot_basis_not_explicit']];
        }

        $basisRow = $targetRows->first(
            fn (object $row): bool => $row->pricing_basis === $explicitHistoricalBasis->value
        );
        if ($basisRow === null) {
            return [null, null, ['missing_target_date_snapshot_for_explicit_basis']];
        }
        if (! $this->matches($basisRow, $candidate)) {
            return [null, null, ['target_snapshot_price_mismatch']];
        }

        return [$explicitHistoricalBasis, $basisRow, []];
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function resolveRun(
        Collection $rows,
        SupplierAdjustedCandidate $candidate,
        CarbonImmutable $target,
        ContractPriceBasis $basis,
    ): PriceEpisodeAnchor {
        $targetIndex = $rows->search(
            fn (object $row): bool => $this->date($row)->equalTo($target)
        );
        if ($targetIndex === false) {
            return $this->missing(['missing_target_date_snapshot_for_selected_basis']);
        }

        $runStart = $target;
        $index = (int) $targetIndex;

        while ($index > 0) {
            $preceding = $rows[$index - 1];
            $precedingDate = $this->date($preceding);
            if (! $precedingDate->addDay()->equalTo($runStart)) {
                return $this->missing([
                    'left_censored_price_episode',
                    'calendar_gap_before_matching_run',
                ]);
            }

            if (! $this->matches($preceding, $candidate)) {
                return new PriceEpisodeAnchor(
                    startedAt: $runStart,
                    evidenceBasis: $basis === ContractPriceBasis::ObservedSellerData
                        ? PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun
                        : PriceEpisodeEvidenceBasis::CanonicalSnapshotRun,
                    flags: ['preceding_calendar_snapshot_proves_price_change'],
                );
            }

            $runStart = $precedingDate;
            $index--;
        }

        return $this->missing([
            'left_censored_price_episode',
            'dataset_boundary_before_matching_run',
        ]);
    }

    /**
     * Exact-contract dated evidence only. Current replacement/publication pointers are not dated proof.
     *
     * @param  array<string, SupplierAdjustedCandidate>  $candidates
     * @return array<string, PriceEpisodeAnchor>
     */
    private function resolveObservedProxies(CarbonImmutable $target, array $candidates, ContractPriceBasis|array|null $explicitBasis): array
    {
        $rows = DB::table('contract_price_snapshots')
            ->whereIn('contract_id', array_keys($candidates))
            ->whereDate('snapshot_date', '<=', $target->toDateString())
            ->orderBy('snapshot_date')->get(['contract_id', 'snapshot_date', 'pricing_basis', 'energy_price_cents_per_kwh', 'has_discount']);
        $observations = DB::table('contract_source_observations')
            ->whereIn('contract_id', array_keys($candidates))
            ->where('first_observed_at', '<=', $target->endOfDay()->utc()->format('Y-m-d H:i:s'))
            ->orderBy('first_observed_at')->get(['contract_id', 'first_observed_at', 'last_observed_at']);
        $events = [];
        $firstSource = [];
        foreach ($rows as $row) {
            $events[$this->date($row)->toDateString()][$row->contract_id] = true;
        }
        $componentDates = DB::table('price_components')
            ->whereIn('electricity_contract_id', array_keys($candidates))
            ->whereDate('price_date', '<=', $target->toDateString())
            ->select('electricity_contract_id', DB::raw('DATE(price_date) as evidence_date'))->distinct()->get();
        foreach ($componentDates as $row) {
            $events[$row->evidence_date][$row->electricity_contract_id] = true;
        }
        foreach ($observations as $observation) {
            $first = CarbonImmutable::parse($observation->first_observed_at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay();
            $last = CarbonImmutable::parse($observation->last_observed_at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay();
            $firstSource[$observation->contract_id] ??= $first->toDateString();
            $boundaries = [$first, $last];
            $after = $last->addDay();
            if ($after <= $target && $observations->contains(function ($other) use ($observation, $after): bool {
                return $other->contract_id === $observation->contract_id
                    && CarbonImmutable::parse($other->first_observed_at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay() <= $after
                    && CarbonImmutable::parse($other->last_observed_at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay() >= $after;
            })) {
                $boundaries[] = $after;
            }
            foreach ($boundaries as $event) {
                if ($event <= $target) {
                    $events[$event->toDateString()][$observation->contract_id] = true;
                }
            }
        }
        $events[$target->toDateString()] = array_fill_keys(array_keys($candidates), true);
        ksort($events);
        $evidence = app(AsOfAnnualCostEvidenceResolver::class)->resolveForDates(
            array_keys($events), AnnualCostMethodVersion::AsOfV3, array_keys($candidates), preferObservedSnapshots: true,
        );
        $calculator = app(CanonicalContractPriceCalculator::class);
        $snapshots = $rows->groupBy(fn ($row) => $row->contract_id.'|'.$this->date($row)->toDateString());
        $resolved = [];
        foreach ($candidates as $id => $candidate) {
            $start = null;
            $previous = null;
            $basis = PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun;
            $flags = ['price_episode_observed_proxy', 'price_episode_left_censored', 'historical_exact_contract_only'];
            $allowedBasis = is_array($explicitBasis) ? ($explicitBasis[$id] ?? null) : $explicitBasis;
            foreach ($events as $date => $contracts) {
                if (! isset($contracts[$id])) {
                    continue;
                }
                $item = $evidence[$date][$id] ?? null;
                $signature = null;
                $dailyBasis = PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun;
                if ($item !== null && $item->exclusionReason === null && ! $item->householdAudienceConflict
                    && ($item->pricingBasis === ContractPriceBasis::ObservedSellerData || $item->pricingBasis === $allowedBasis)) {
                    if ($item->canonicalData !== null
                        && ($item->sourceEvidenceIds['source_snapshot_id'] !== null
                            || ! isset($firstSource[$id]) || $date < $firstSource[$id])) {
                        $signature = $calculator->supplierAdjustedCandidate($id, $item->canonicalData,
                            new ContractContext($item->pricingModel, $item->contractType, $item->metering, $item->fixedTimeRange, 'Household'),
                            comparisonDate: $item->date, policy: ComparisonPolicy::Current);
                        $dailyBasis = $item->sourceEvidenceIds['source_snapshot_id'] !== null
                            ? PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun : PriceEpisodeEvidenceBasis::CanonicalSnapshotRun;
                    } elseif ((! isset($firstSource[$id]) || $date < $firstSource[$id])
                        && in_array('historical_canonical_omitted_no_covering_current_builder_episode', $item->provenanceFlags, true)
                        && $item->metering === 'General' && $item->pricingModel === 'FixedPrice'
                        && $item->contractType === 'OpenEnded') {
                        $selected = $snapshots->get($id.'|'.$date, collect())->where('pricing_basis', $item->pricingBasis->value);
                        $row = $selected->count() === 1 ? $selected->first() : null;
                        if ($row !== null && ! $row->has_discount && $row->energy_price_cents_per_kwh !== null
                            && is_finite((float) $row->energy_price_cents_per_kwh)) {
                            $signature = new SupplierAdjustedCandidate($id, (float) $row->energy_price_cents_per_kwh, 0, metering: 'General');
                            $dailyBasis = $item->pricingBasis === ContractPriceBasis::ObservedSellerData
                                ? PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun : PriceEpisodeEvidenceBasis::CanonicalSnapshotRun;
                        }
                    }
                }
                if ($signature === null || ! $candidate->hasSameEnergySignature($signature)) {
                    $start = null;
                    $flags = ['price_episode_observed_proxy', 'price_episode_left_censored', 'historical_exact_contract_only',
                        'prior_energy_evidence_changed_unknown_or_conflicting'];
                } else {
                    if ($start === null) {
                        $start = CarbonImmutable::parse($date, self::TIMEZONE);
                        $basis = $dailyBasis;
                    } elseif ($previous !== null && $previous->addDay()->toDateString() !== $date) {
                        $flags[] = 'calendar_gap_within_observed_price_episode';
                    }
                    if ($item->sourceEvidenceIds['historical_interpretation_id'] !== null) {
                        $flags[] = 'price_episode_uses_dedicated_historical_interpretation';
                    }
                    if ($item->sourceInterpretationProvenance?->retrospective) {
                        $flags[] = 'price_episode_uses_retrospective_exact_source_interpretation';
                    }
                }
                $previous = CarbonImmutable::parse($date, self::TIMEZONE);
            }
            $resolved[$id] = $start === null ? $this->missing($flags)
                : new PriceEpisodeAnchor($start, $basis, array_values(array_unique($flags)));
        }

        return $resolved;
    }

    private function matches(object $row, SupplierAdjustedCandidate $candidate): bool
    {
        if ($row->energy_price_cents_per_kwh === null) {
            return false;
        }

        return abs((float) $row->energy_price_cents_per_kwh - $candidate->currentEnergyPriceCentsPerKwh) <= 0.0001
            && abs((float) ($row->monthly_fee_eur ?? 0.0) - $candidate->monthlyFeeEur) <= 0.0001;
    }

    private function date(object $row): CarbonImmutable
    {
        return CarbonImmutable::parse($row->snapshot_date, self::TIMEZONE)->startOfDay();
    }

    /** @param list<string> $flags */
    private function missing(array $flags): PriceEpisodeAnchor
    {
        return new PriceEpisodeAnchor(
            startedAt: null,
            evidenceBasis: PriceEpisodeEvidenceBasis::Missing,
            flags: array_values(array_unique(['missing_price_episode_anchor', ...$flags])),
        );
    }
}
