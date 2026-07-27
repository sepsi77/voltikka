<?php

namespace App\Services\PriceForecasting;

use App\Models\FixedContractPriceForecast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FixedTermForecastEvaluationService
{
    public function __construct(private readonly FixedTermPriceForecastService $forecastService) {}

    public function evaluateMatured(
        CarbonInterface $asOfDate,
        ?int $horizonDays = null,
        ?string $modelVersion = null,
    ): array {
        $asOf = CarbonImmutable::instance($asOfDate)->endOfDay();
        $query = FixedContractPriceForecast::query()
            ->whereDate('target_date', '<=', $asOf->toDateString())
            ->whereNull('actual_price_cents_per_kwh')
            ->orderBy('target_date')
            ->orderBy('duration_months')
            ->orderBy('target_quantile');

        if ($horizonDays !== null) {
            $query->where('horizon_days', $horizonDays);
        }

        if ($modelVersion !== null) {
            $query->where('model_version', $modelVersion);
        }

        $evaluated = 0;
        $missingActual = 0;
        $updated = collect();

        $query->chunkById(100, function (Collection $forecasts) use (&$evaluated, &$missingActual, $updated): void {
            foreach ($forecasts as $forecast) {
                /** @var FixedContractPriceForecast $forecast */
                // A matured actual is the seller price observed on the target date.
                // Do not reinterpret it with today's canonical pricing state.
                $actual = $this->forecastService->retailStatistic(
                    $forecast->target_date,
                    $forecast->duration_months,
                    $forecast->target_quantile,
                    FixedTermPriceForecastService::OBSERVED_PRICING_BASIS,
                );

                if ($actual === null || $actual['price'] === null) {
                    $missingActual++;

                    continue;
                }

                $actualChange = $actual['price'] - $forecast->current_price_cents_per_kwh;
                $forecastError = $forecast->forecast_price_cents_per_kwh - $actual['price'];
                $actualDirection = $this->forecastService->directionLabel($actualChange);

                $sourceMetadata = $forecast->source_metadata ?? [];
                $sourceMetadata['actual_retail_pricing_basis'] = $actual['pricing_basis'];
                $sourceMetadata['actual_retail_source_date'] = $actual['source_date'];
                $sourceMetadata['actual_retail_segment'] = $actual['segment'];
                $sourceMetadata['actual_retail_metric'] = $actual['metric'];
                $sourceMetadata['actual_retail_contract_count'] = $actual['contract_count'];

                $forecast->fill([
                    'actual_price_cents_per_kwh' => round($actual['price'], 4),
                    'actual_change_cents_per_kwh' => round($actualChange, 4),
                    'forecast_error_cents_per_kwh' => round($forecastError, 4),
                    'absolute_error_cents_per_kwh' => round(abs($forecastError), 4),
                    'actual_direction' => $actualDirection,
                    'direction_correct' => $this->forecastService->directionCategory($forecast->direction) === $this->forecastService->directionCategory($actualDirection),
                    'source_metadata' => $sourceMetadata,
                    'evaluated_at' => now(),
                ])->save();

                $evaluated++;
                $updated->push($forecast->fresh());
            }
        });

        return [
            'evaluated' => $evaluated,
            'missing_actual' => $missingActual,
            'forecasts' => $updated,
        ];
    }
}
