<?php

namespace App\Console\Commands;

use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\RetailPremiumObservation;
use App\Services\MorningFreshness\MorningFreshnessResult;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use App\Services\RetailPremium\RetailPremiumHistoryBackfillService;
use App\Services\RetailPremium\RetailPremiumObservationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CollectRetailPremiumObservations extends Command
{
    protected $signature = 'retail-premiums:collect
        {--as-of= : Collection date. Defaults to today in Europe/Helsinki and does not rewind contract data.}
        {--contract=* : Collect only these active lineage-tip contract IDs.}
        {--include-inactive : Backfill historical price periods from inactive lineage ancestors.}
        {--from= : Earliest historical price observation date.}
        {--to= : Latest historical price observation date. Defaults to yesterday.}
        {--include-open : Include the current open period in a historical backfill.}
        {--only= : Backfill one group: spot, reset, fixed-term, or hybrid.}
        {--overwrite : Replace an existing observation with the same identity.}
        {--dry-run : Build and print observations without writing to the database.}
        {--require-freshness : Require current morning import checkpoints before collection.}';

    protected $description = 'Collect per-contract retail premium observations';

    public function handle(
        RetailPremiumObservationService $service,
        RetailPremiumHistoryBackfillService $historyService,
        MorningJobFreshnessService $freshness,
    ): int {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse($this->option('as-of'), 'Europe/Helsinki')->startOfDay()
            : CarbonImmutable::now('Europe/Helsinki')->startOfDay();
        $contractIds = collect($this->option('contract'))->filter()->values();
        $historicalMode = (bool) $this->option('include-inactive');
        $hasHistoricalOptions = $this->option('from') !== null
            || $this->option('to') !== null
            || $this->option('only') !== null
            || (bool) $this->option('include-open');

        if (! $historicalMode && $hasHistoricalOptions) {
            $this->error('The --from, --to, --only, and --include-open options require --include-inactive.');

            return self::INVALID;
        }

        if (! $historicalMode && (bool) $this->option('require-freshness')) {
            $result = $freshness->checkRetailPremium($asOf);

            if (! $result->ready()) {
                return $this->defer($freshness, $asOf, $result);
            }
        }

        $contracts = $this->activeLineageTips($contractIds, $historicalMode ? $this->option('only') : null);

        if ($historicalMode) {
            return $this->backfillHistory($contracts, $historyService, $asOf);
        }

        return $this->collectCurrent($contracts, $service, $freshness, $asOf);
    }

    /**
     * @param  Collection<int, string>  $contractIds
     * @return Collection<int, ElectricityContract>
     */
    private function activeLineageTips(Collection $contractIds, ?string $historicalGroup = null): Collection
    {
        return ElectricityContract::query()
            ->active()
            ->whereIn('pricing_model', ['Spot', 'FixedPrice', 'Hybrid'])
            ->when($historicalGroup === 'spot', fn ($query) => $query->where('pricing_model', 'Spot'))
            ->when($historicalGroup === 'hybrid', fn ($query) => $query->where('pricing_model', 'Hybrid'))
            ->when($historicalGroup === 'fixed-term', fn ($query) => $query
                ->where('pricing_model', 'FixedPrice')
                ->where('contract_type', 'FixedTerm'))
            ->when($historicalGroup === 'reset', fn ($query) => $query->where('pricing_model', 'FixedPrice'))
            ->whereNotNull('published_interpretation_id')
            ->when($contractIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $contractIds))
            ->with([
                'publishedInterpretation' => fn ($query) => $query->select([
                    'id',
                    'contract_id',
                    'source_snapshot_id',
                    'status',
                    'schema_version',
                    'prompt_version',
                    'validator_version',
                    'output',
                ]),
                'publishedInterpretation.sourceSnapshot' => fn ($query) => $query->select([
                    'id',
                    'source_fingerprint',
                    'first_observed_at',
                    'last_observed_at',
                ]),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, ElectricityContract>  $contracts
     */
    private function collectCurrent(
        Collection $contracts,
        RetailPremiumObservationService $service,
        MorningJobFreshnessService $freshness,
        CarbonImmutable $asOf,
    ): int {
        $saved = 0;
        $skipped = 0;
        $built = 0;

        foreach ($contracts as $contract) {
            foreach ($service->buildObservations($contract, $asOf) as $observation) {
                $built++;
                $observation = $this->reuseOpenPricePeriodIdentity($observation);
                $observation = $this->flagPriorHistoryContinuation($observation);
                $premium = $observation['retail_premium_cents_per_kwh'] === null
                    ? 'n/a'
                    : sprintf('%+.4f c/kWh', $observation['retail_premium_cents_per_kwh']);

                $this->line(sprintf(
                    '%s / phase %d / %s: %s (%s, %s)',
                    $contract->id,
                    $observation['phase_index'],
                    $observation['energy_component_type'] ?? 'no-energy-component',
                    $premium,
                    $observation['reference_kind'],
                    $observation['vat_basis'],
                ));

                $result = $this->persist($observation);
                $saved += in_array($result, ['saved', 'extended'], true) ? 1 : 0;
                $skipped += $result === 'skipped' ? 1 : 0;
            }
        }

        if ((bool) $this->option('require-freshness') && $built === 0) {
            return $this->defer(
                $freshness,
                $asOf,
                new MorningFreshnessResult([
                    'retail_output' => 'No current retail premium observations were built.',
                ]),
            );
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Dry run complete. Built %d retail premium observations from %d active contracts.',
                $built,
                $contracts->count(),
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Done. Saved %d observations and skipped %d immutable observations from %d active contracts.',
            $saved,
            $skipped,
            $contracts->count(),
        ));

        return self::SUCCESS;
    }

    private function defer(
        MorningJobFreshnessService $freshness,
        CarbonImmutable $asOf,
        MorningFreshnessResult $result,
    ): int {
        foreach ($result->messages() as $message) {
            $this->error("Morning job deferred: {$message}");
        }

        $freshness->reportDeferred('retail-premiums:collect', $asOf, $result);

        return self::FAILURE;
    }

    /**
     * @param  Collection<int, ElectricityContract>  $contracts
     */
    private function backfillHistory(
        Collection $contracts,
        RetailPremiumHistoryBackfillService $historyService,
        CarbonImmutable $asOf,
    ): int {
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, ['spot', 'reset', 'fixed-term', 'hybrid'], true)) {
            $this->error('The --only value must be spot, reset, fixed-term, or hybrid.');

            return self::INVALID;
        }

        $firstPriceDate = PriceComponent::query()->min('price_date');
        $from = $this->option('from')
            ? CarbonImmutable::parse($this->option('from'))->startOfDay()
            : CarbonImmutable::parse($firstPriceDate ?? $asOf->toDateString())->startOfDay();
        $to = $this->option('to')
            ? CarbonImmutable::parse($this->option('to'))->startOfDay()
            : $asOf->subDay();

        if ($to->lt($from)) {
            $this->error('The historical --to date must be on or after --from.');

            return self::INVALID;
        }

        $contracts = $contracts
            ->filter(fn (ElectricityContract $contract) => $this->matchesBackfillGroup($contract, $only))
            ->values();
        $totals = [];
        $saved = 0;
        $skipped = 0;

        foreach ($contracts as $contract) {
            $result = $historyService->build(
                $contract,
                $from,
                $to,
                (bool) $this->option('include-open'),
            );

            foreach ($result['stats'] as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }

            foreach ($result['observations'] as $observation) {
                $persisted = $this->persist($observation);
                $saved += in_array($persisted, ['saved', 'extended'], true) ? 1 : 0;
                $skipped += $persisted === 'skipped' ? 1 : 0;
            }
        }

        $this->table(
            ['Measure', 'Count'],
            collect($totals)->map(fn (int $value, string $key) => [$key, $value])->values()->all(),
        );

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Historical dry run complete for %d active lineage tips from %s through %s.',
                $contracts->count(),
                $from->toDateString(),
                $to->toDateString(),
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Historical backfill complete. Saved %d rows and skipped %d immutable rows from %d active lineage tips.',
            $saved,
            $skipped,
            $contracts->count(),
        ));

        return self::SUCCESS;
    }

    private function matchesBackfillGroup(ElectricityContract $contract, ?string $only): bool
    {
        if ($only === null) {
            return true;
        }

        return match ($only) {
            'spot' => $contract->pricing_model === 'Spot',
            'hybrid' => $contract->pricing_model === 'Hybrid',
            'reset' => ($contract->canonical_pricing['recurring_schedule']['present'] ?? false) === true,
            'fixed-term' => $contract->pricing_model === 'FixedPrice'
                && $contract->contract_type === 'FixedTerm'
                && ($contract->canonical_pricing['recurring_schedule']['present'] ?? false) !== true,
        };
    }

    /**
     * @param  array<string, mixed>  $observation
     * @return 'saved'|'extended'|'skipped'|'dry-run'
     */
    private function persist(array $observation): string
    {
        if ($this->option('dry-run')) {
            return 'dry-run';
        }

        $identity = [
            'observation_key' => $observation['observation_key'],
            'reference_kind' => $observation['reference_kind'],
            'method_version' => $observation['method_version'],
        ];
        $existing = RetailPremiumObservation::query()->where($identity)->first();

        if ($existing !== null) {
            $incomingFirstObserved = CarbonImmutable::parse($observation['first_observed_date']);
            $incomingLastObserved = CarbonImmutable::parse($observation['last_observed_date']);
            $firstObserved = $incomingFirstObserved->min($existing->first_observed_date);
            $lastObserved = $incomingLastObserved->max($existing->last_observed_date);
            $metadata = $this->mergeObservationMetadata(
                $existing->source_metadata ?? [],
                $observation['source_metadata'] ?? [],
            );

            if (! $this->option('overwrite')) {
                if ($lastObserved->gt($existing->last_observed_date)) {
                    $existing->update([
                        'last_observed_date' => $lastObserved->toDateString(),
                        'source_metadata' => $metadata,
                    ]);

                    return 'extended';
                }

                return 'skipped';
            }

            $observation['first_observed_date'] = $firstObserved->toDateString();
            $observation['last_observed_date'] = $lastObserved->toDateString();
            $observation['source_metadata'] = $metadata;
        }

        RetailPremiumObservation::query()->updateOrCreate($identity, $observation);

        return 'saved';
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeObservationMetadata(array $existing, array $incoming): array
    {
        $metadata = array_replace($existing, $incoming);

        foreach (['period_carrier_ids', 'price_component_ids'] as $key) {
            if (isset($existing[$key]) || isset($incoming[$key])) {
                $metadata[$key] = collect($existing[$key] ?? [])
                    ->concat($incoming[$key] ?? [])
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            }
        }

        return $metadata;
    }

    /**
     * Use the same row while the latest observed semantic price period continues.
     * Historical reconstruction does not call this method.
     *
     * @param  array<string, mixed>  $observation
     * @return array<string, mixed>
     */
    private function reuseOpenPricePeriodIdentity(array $observation): array
    {
        $latest = RetailPremiumObservation::query()
            ->where('lineage_key', $observation['lineage_key'])
            ->where('phase_index', $observation['phase_index'])
            ->where('energy_component_type', $observation['energy_component_type'])
            ->where('reference_kind', $observation['reference_kind'])
            ->where('method_version', $observation['method_version'])
            ->orderByDesc('first_observed_date')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null && hash_equals($latest->price_signature, $observation['price_signature'])) {
            $observation['observation_key'] = $latest->observation_key;
            $observation['first_observed_date'] = $latest->first_observed_date->toDateString();
        }

        return $observation;
    }

    /**
     * Mark the seam where a forward period continues a reconstructed history period.
     *
     * Reconstructed history and canonical forward observations use separate method versions, so
     * they can never merge into one row. Without this flag the seam looks like a new price period
     * at an unchanged price, which reads as zero pass-through in a beta analysis.
     *
     * @param  array<string, mixed>  $observation
     * @return array<string, mixed>
     */
    private function flagPriorHistoryContinuation(array $observation): array
    {
        $previous = RetailPremiumObservation::query()
            ->where('lineage_key', $observation['lineage_key'])
            ->where('energy_component_type', $observation['energy_component_type'])
            ->where('reference_kind', $observation['reference_kind'])
            ->where('method_version', RetailPremiumHistoryBackfillService::METHOD_VERSION)
            ->whereDate('last_observed_date', CarbonImmutable::parse($observation['first_observed_date'])->subDay())
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return $observation;
        }

        $samePrice = $this->sameValue(
            $previous->retail_energy_price_cents_per_kwh,
            $observation['retail_energy_price_cents_per_kwh'],
        ) && $this->sameValue(
            $previous->retail_premium_cents_per_kwh,
            $observation['retail_premium_cents_per_kwh'],
        ) && $this->sameValue($previous->monthly_fee_eur, $observation['monthly_fee_eur']);

        if (! $samePrice) {
            return $observation;
        }

        $observation['quality_flags'] = collect($observation['quality_flags'] ?? [])
            ->push('continues_prior_history_period')
            ->unique()
            ->values()
            ->all();
        $observation['source_metadata'] = ($observation['source_metadata'] ?? []) + [
            'continued_history_observation_key' => $previous->observation_key,
            'continued_history_last_observed_date' => $previous->last_observed_date->toDateString(),
        ];

        return $observation;
    }

    private function sameValue(mixed $stored, mixed $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        return abs((float) $stored - (float) $incoming) < 0.0001;
    }
}
