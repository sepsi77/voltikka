<?php

namespace App\Console\Commands;

use App\Models\ElectricityContract;
use App\Models\RetailPremiumObservation;
use App\Services\RetailPremium\RetailPremiumObservationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CollectRetailPremiumObservations extends Command
{
    protected $signature = 'retail-premiums:collect
        {--as-of= : Collection date. Defaults to today in Europe/Helsinki and does not rewind contract data.}
        {--contract=* : Collect only these active contract IDs.}
        {--overwrite : Replace an existing observation with the same identity.}
        {--dry-run : Build and print observations without writing to the database.}';

    protected $description = 'Collect per-contract retail premium observations';

    public function handle(RetailPremiumObservationService $service): int
    {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse($this->option('as-of'), 'Europe/Helsinki')->startOfDay()
            : CarbonImmutable::now('Europe/Helsinki')->startOfDay();
        $contractIds = collect($this->option('contract'))->filter()->values();

        $contracts = ElectricityContract::query()
            ->active()
            ->whereIn('pricing_model', ['Spot', 'FixedPrice', 'Hybrid'])
            ->whereNotNull('published_interpretation_id')
            ->when($contractIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $contractIds))
            ->with('publishedInterpretation.sourceSnapshot')
            ->orderBy('id')
            ->get();

        $saved = 0;
        $skipped = 0;
        $built = 0;

        foreach ($contracts as $contract) {
            foreach ($service->buildObservations($contract, $asOf) as $observation) {
                $built++;
                $observation = $this->reuseOpenPricePeriodIdentity($observation);
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

                if ($this->option('dry-run')) {
                    continue;
                }

                $identity = [
                    'observation_key' => $observation['observation_key'],
                    'reference_kind' => $observation['reference_kind'],
                    'method_version' => $observation['method_version'],
                ];
                $existing = RetailPremiumObservation::query()->where($identity)->first();

                if ($existing !== null && ! $this->option('overwrite')) {
                    $skipped++;

                    continue;
                }

                RetailPremiumObservation::query()->updateOrCreate($identity, $observation);
                $saved++;
            }
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

    /**
     * Use the same row while the latest observed semantic price period continues.
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
}
