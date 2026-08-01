<?php

namespace App\Services\RetailPremium;

use App\Enums\PricingModel;
use App\Models\FixedContractPriceForecast;
use App\Models\RetailPremiumObservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RetailPremiumCrossCheckService
{
    /**
     * Compare the per-lineage term-strip dataset with stored market-level EWMA forecasts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function compare(CarbonInterface $asOfDate): Collection
    {
        $asOf = CarbonImmutable::instance($asOfDate)->startOfDay();

        // Only the current method pair is compared. Superseded method versions stay in the table as
        // an immutable record, and they keep known duplicate-period and unresolved-VAT defects.
        $observations = RetailPremiumObservation::query()
            ->whereDate('first_observed_date', '<=', $asOf->toDateString())
            ->whereIn('method_version', [
                RetailPremiumObservationService::METHOD_VERSION,
                RetailPremiumHistoryBackfillService::METHOD_VERSION,
            ])
            ->where('pricing_model', PricingModel::FixedPrice->value)
            ->where('reference_kind', 'term_strip')
            ->where('quality', 'inferred')
            ->where('vat_basis', 'included')
            ->where('energy_component_type', 'energy_general')
            ->whereNotNull('retail_premium_cents_per_kwh')
            ->orderByDesc('first_observed_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (RetailPremiumObservation $row) => implode('|', [
                $row->lineage_key,
                (string) ($row->source_metadata['duration_months'] ?? ''),
            ]))
            ->map->first()
            ->filter(fn (RetailPremiumObservation $row) => is_numeric($row->source_metadata['duration_months'] ?? null));

        return $observations
            ->groupBy(fn (RetailPremiumObservation $row) => (int) $row->source_metadata['duration_months'])
            ->sortKeys()
            ->map(function (Collection $durationRows, int $durationMonths) use ($asOf) {
                $forecast = FixedContractPriceForecast::query()
                    ->whereDate('forecast_date', $asOf->toDateString())
                    ->where('duration_months', $durationMonths)
                    ->where('target_quantile', 'median')
                    ->latest('id')
                    ->first();
                $datasetPremium = $this->median($durationRows->pluck('retail_premium_cents_per_kwh')->all());

                return [
                    'as_of_date' => $asOf->toDateString(),
                    'duration_months' => $durationMonths,
                    'observation_count' => $durationRows->count(),
                    'dataset_median_retail_premium_cents_per_kwh' => $datasetPremium,
                    'forecast_current_retail_premium_cents_per_kwh' => $forecast?->retail_premium_cents_per_kwh,
                    'forecast_normal_ewma_retail_premium_cents_per_kwh' => $forecast?->normal_retail_premium_cents_per_kwh,
                    'difference_from_current_cents_per_kwh' => $forecast === null
                        ? null
                        : $datasetPremium - $forecast->retail_premium_cents_per_kwh,
                    'difference_from_normal_ewma_cents_per_kwh' => $forecast === null
                        ? null
                        : $datasetPremium - $forecast->normal_retail_premium_cents_per_kwh,
                    'company_medians_cents_per_kwh' => $durationRows
                        ->groupBy('company_name')
                        ->map(fn (Collection $companyRows) => $this->median(
                            $companyRows->pluck('retail_premium_cents_per_kwh')->all(),
                        ))
                        ->sortKeys()
                        ->all(),
                ];
            })
            ->values();
    }

    /**
     * @param  list<float|int|string>  $values
     */
    private function median(array $values): float
    {
        $values = collect($values)->map(fn ($value) => (float) $value)->sort()->values();
        $count = $values->count();
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
