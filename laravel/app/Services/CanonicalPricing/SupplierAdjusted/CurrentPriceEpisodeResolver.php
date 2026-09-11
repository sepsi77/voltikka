<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted;

use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Resolves current supplier-price episode anchors in batches. It never runs in the calculator. */
class CurrentPriceEpisodeResolver
{
    /**
     * @param  array<string, SupplierAdjustedCandidate>  $candidates
     * @return array<string, PriceEpisodeAnchor>
     */
    public function resolve(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $ids = array_keys($candidates);
        $rows = DB::table('contract_price_snapshots')
            ->whereIn('contract_id', $ids)
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
            $anchor = $this->latestMatchingRun($rows->get($contractId, collect())->all(), $candidate);
            if ($anchor !== null) {
                $resolved[$contractId] = $anchor;
            }
        }

        $unresolved = array_values(array_diff($ids, array_keys($resolved)));
        if ($unresolved !== []) {
            $sourceRows = DB::table('electricity_contracts as contracts')
                ->join('contract_source_observations as observations', function ($join): void {
                    $join->on('observations.id', '=', 'contracts.current_source_observation_id')
                        ->on('observations.contract_id', '=', 'contracts.id');
                })
                ->join('contract_interpretations as interpretations', function ($join): void {
                    $join->on('interpretations.id', '=', 'contracts.published_interpretation_id')
                        ->on('interpretations.contract_id', '=', 'contracts.id')
                        ->on('interpretations.source_snapshot_id', '=', 'observations.source_snapshot_id');
                })
                ->whereIn('contracts.id', $unresolved)
                ->where('interpretations.status', 'published')
                ->get(['contracts.id as contract_id', 'observations.first_observed_at']);

            foreach ($sourceRows as $row) {
                $resolved[(string) $row->contract_id] = new PriceEpisodeAnchor(
                    startedAt: CarbonImmutable::parse($row->first_observed_at, 'Europe/Helsinki')->startOfDay(),
                    evidenceBasis: PriceEpisodeEvidenceBasis::CurrentSourceObservation,
                    flags: ['source_observation_snapshot_matches_published_interpretation'],
                );
            }
        }

        foreach ($ids as $contractId) {
            $resolved[$contractId] ??= PriceEpisodeAnchor::missing();
        }

        return $resolved;
    }

    /** @param list<object> $rows */
    private function latestMatchingRun(array $rows, SupplierAdjustedCandidate $candidate): ?PriceEpisodeAnchor
    {
        $runStart = null;
        $previousDate = null;
        $observedRun = true;

        foreach (collect($rows)->groupBy('snapshot_date') as $snapshotDate => $dailyRows) {
            $date = CarbonImmutable::parse($snapshotDate, 'Europe/Helsinki')->startOfDay();
            // Preference is local to one date. Unknown rates cannot override known evidence.
            $known = $dailyRows->filter(fn ($row): bool => $row->energy_price_cents_per_kwh !== null);
            $observed = $known->where('pricing_basis', 'observed_seller_data');
            $evidence = $observed->isNotEmpty() ? $observed : $known;
            if ($evidence->isEmpty() || ! $evidence->every(fn ($row): bool => $this->matches($row, $candidate))) {
                $runStart = null;
                $previousDate = $date;

                continue;
            }

            if ($runStart === null || $previousDate === null || ! $previousDate->addDay()->equalTo($date)) {
                $runStart = $date;
                $observedRun = true;
            }
            $observedRun = $observedRun && $evidence->every(fn ($row): bool => $row->pricing_basis === 'observed_seller_data');
            $previousDate = $date;
        }

        return $runStart === null ? null : new PriceEpisodeAnchor(
            startedAt: $runStart,
            evidenceBasis: $observedRun
                ? PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun
                : PriceEpisodeEvidenceBasis::CanonicalSnapshotRun,
            flags: ['price_snapshot_episode_proxy'],
        );
    }

    private function matches(object $row, SupplierAdjustedCandidate $candidate): bool
    {
        if ($row->energy_price_cents_per_kwh === null) {
            return false;
        }

        return abs((float) $row->energy_price_cents_per_kwh - $candidate->currentEnergyPriceCentsPerKwh) <= 0.0001
            && abs((float) ($row->monthly_fee_eur ?? 0.0) - $candidate->monthlyFeeEur) <= 0.0001;
    }
}
