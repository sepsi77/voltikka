<?php

namespace App\Services\ContractStatistics;

use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
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
    ): array {
        if ($candidates === []) {
            return [];
        }

        $target = CarbonImmutable::instance($targetDate)
            ->setTimezone(self::TIMEZONE)
            ->startOfDay();
        $targetDateString = $target->toDateString();

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
