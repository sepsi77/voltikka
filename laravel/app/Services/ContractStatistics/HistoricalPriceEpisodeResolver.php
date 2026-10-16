<?php

namespace App\Services\ContractStatistics;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
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
            return $this->resolveObservedProxies($target, $candidates);
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
     * The event model follows CurrentPriceEpisodeResolver: before the first immutable source
     * observation, dated snapshots (General) and raw component tariffs (Time/Season) are the
     * evidence; after it, only source observation boundaries are events and each observation
     * carries its own exact-source v3 signature. A day without a snapshot inside an observation
     * is therefore not an evidence break. The only remaining difference is the dated identity:
     * no replacement lineage and the latest target-validated exact-source interpretation.
     *
     * @param  array<string, SupplierAdjustedCandidate>  $candidates
     * @return array<string, PriceEpisodeAnchor>
     */
    private function resolveObservedProxies(CarbonImmutable $target, array $candidates): array
    {
        $ids = array_map('strval', array_keys($candidates));
        $sources = app(AsOfAnnualCostEvidenceResolver::class)->sourceObservationEvidence($target, $ids);
        $snapshots = DB::table('contract_price_snapshots')
            ->whereIn('contract_id', $ids)
            ->whereDate('snapshot_date', '<=', $target->toDateString())
            ->orderBy('snapshot_date')
            ->get(['contract_id', 'snapshot_date', 'pricing_basis', 'energy_price_cents_per_kwh', 'metering', 'pricing_model'])
            ->groupBy('contract_id');
        $raw = app(CurrentPriceEpisodeResolver::class);
        $firstObservations = collect($sources)->map(fn (array $intervals) => collect([(object) ['first_observed_at' => $intervals[0]['first_observed_at']]]));
        $tariffs = $raw->historicalTariffs($candidates, array_combine($ids, array_map(fn (string $id): array => [$id], $ids)), $firstObservations, $target);
        $calculator = app(CanonicalContractPriceCalculator::class);
        $resolved = [];
        foreach ($candidates as $id => $candidate) {
            $id = (string) $id;
            $intervals = [];
            $events = [];
            foreach ($sources[$id] ?? [] as $source) {
                $signature = null;
                if ($source['data'] !== null) {
                    $details = $source['details'];
                    $signature = $calculator->supplierAdjustedCandidate($id, $source['data'], new ContractContext(
                        (string) ($details['PricingModel'] ?? ''), (string) ($details['ContractType'] ?? ''),
                        is_string($details['Metering'] ?? null) ? $details['Metering'] : null,
                        is_string($details['FixedTimeRange'] ?? null) ? $details['FixedTimeRange'] : null, 'Household',
                    ), $source['start'], ComparisonPolicy::Current);
                }
                $intervals[] = ['start' => $source['start']->toDateString(), 'end' => $source['end']->toDateString(),
                    'candidate' => $signature, 'provenance' => $source['provenance'], 'flags' => $source['flags']];
                $events[$source['start']->toDateString()] ??= [];
                $events[$source['end']->toDateString()] ??= [];
            }
            $firstSource = $intervals[0]['start'] ?? null;
            $rawEvents = [];
            if (in_array($candidate->metering, ['Time', 'Season'], true)) {
                foreach ($tariffs[$id] ?? [] as $date => $rows) {
                    $rawEvents[$date] = $raw->historicalTariffCandidate($rows, $candidate);
                    $events[$date] ??= [];
                }
            }
            foreach ($snapshots->get($id, collect()) as $snapshot) {
                $date = substr((string) $snapshot->snapshot_date, 0, 10);
                // Once immutable chronology starts, gaps and invalid output cannot reopen raw evidence.
                if ($firstSource === null || $date < $firstSource) {
                    $events[$date][] = $snapshot;
                }
            }
            // Inclusive ends can remove conflicting evidence while another source still covers the next day.
            foreach ($intervals as $interval) {
                $next = CarbonImmutable::parse($interval['end'], self::TIMEZONE)->addDay()->toDateString();
                if ($next <= $target->toDateString()
                    && collect($intervals)->contains(fn (array $other): bool => $other['start'] <= $next && $other['end'] >= $next)) {
                    $events[$next] ??= [];
                }
            }
            ksort($events);

            $start = null;
            $previous = null;
            $flags = ['price_episode_observed_proxy', 'price_episode_left_censored', 'historical_exact_contract_only'];
            $basis = PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun;
            $rawStart = false;
            foreach ($events as $date => $dailySnapshots) {
                $covering = array_values(array_filter($intervals, fn (array $interval): bool => $interval['start'] <= $date && $interval['end'] >= $date));
                if ($covering !== []) {
                    $evidence = array_column($covering, 'candidate');
                    $dailyBasis = PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun;
                } elseif (array_key_exists($date, $rawEvents)) {
                    $evidence = [$rawEvents[$date]];
                    $dailyBasis = PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun;
                } else {
                    $known = collect($dailySnapshots)->filter(fn ($row): bool => $row->energy_price_cents_per_kwh !== null);
                    $observed = $known->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value);
                    $selected = $observed->isNotEmpty() ? $observed : $known;
                    $evidence = $selected->map(fn ($row) => $raw->snapshotCandidate($row))->values()->all();
                    $dailyBasis = $observed->isNotEmpty() ? PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun : PriceEpisodeEvidenceBasis::CanonicalSnapshotRun;
                }
                $matches = $evidence !== [] && collect($evidence)->every(fn ($item): bool => $item !== null && $candidate->hasSameEnergySignature($item));
                if (! $matches) {
                    $start = null;
                    $flags = ['price_episode_observed_proxy', 'price_episode_left_censored', 'historical_exact_contract_only',
                        'prior_energy_evidence_changed_unknown_or_conflicting'];
                    $previous = $date;

                    continue;
                }
                if ($start === null) {
                    $start = $date;
                    $basis = $dailyBasis;
                    $rawStart = $covering === [] && array_key_exists($date, $rawEvents);
                    if ($rawStart) {
                        $flags[] = 'observed_full_tariff_energy_evidence';
                    }
                } else {
                    $continuouslyObserved = collect($intervals)->contains(fn (array $interval): bool => $interval['start'] <= $previous
                        && $interval['end'] >= $date && $interval['candidate'] !== null && $candidate->hasSameEnergySignature($interval['candidate']));
                    if (! $continuouslyObserved && CarbonImmutable::parse($previous, self::TIMEZONE)->addDay()->toDateString() < $date) {
                        $flags[] = 'price_episode_observation_gap';
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
                foreach ($covering as $interval) {
                    if ($interval['provenance']?->retrospective) {
                        $flags[] = 'price_episode_uses_retrospective_exact_source_interpretation';
                    }
                }
                $previous = $date;
            }
            if ($previous !== null && $previous < $target->toDateString()) {
                $flags[] = 'price_episode_right_observation_gap';
            }
            $resolved[$id] = $start === null ? $this->missing($flags)
                : new PriceEpisodeAnchor(CarbonImmutable::parse($start, self::TIMEZONE)->startOfDay(), $basis, array_values(array_unique($flags)));
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
