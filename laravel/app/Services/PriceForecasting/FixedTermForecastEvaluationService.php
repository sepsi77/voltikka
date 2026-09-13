<?php

namespace App\Services\PriceForecasting;

use App\Models\ContractPriceDailyStatistic;
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
        bool $dryRun = false,
    ): array {
        $asOf = CarbonImmutable::instance($asOfDate)->endOfDay();
        $query = FixedContractPriceForecast::query()
            ->whereDate('target_date', '<=', $asOf->toDateString())
            ->whereNull('actual_price_cents_per_kwh')
            ->orderBy('id');

        if ($horizonDays !== null) {
            $query->where('horizon_days', $horizonDays);
        }

        if ($modelVersion !== null) {
            $query->where('model_version', $modelVersion);
        }

        $evaluated = 0;
        $missingActual = 0;
        $unsupportedProvenance = 0;
        $updated = collect();

        $query->chunkById(100, function (Collection $forecasts) use (&$evaluated, &$missingActual, &$unsupportedProvenance, $updated, $dryRun): void {
            foreach ($forecasts as $forecast) {
                /** @var FixedContractPriceForecast $forecast */
                $provenance = $this->evaluationProvenance($forecast);
                if ($provenance === null || ForecastOutlook::category($forecast->direction) === null) {
                    $unsupportedProvenance++;

                    continue;
                }

                $actual = $this->forecastService->retailStatistic(
                    $forecast->target_date,
                    $forecast->duration_months,
                    $forecast->target_quantile,
                    $provenance['basis'],
                );

                if ($actual === null || $actual['price'] === null) {
                    $missingActual++;

                    continue;
                }

                $actualChange = round($actual['price'] - $forecast->current_price_cents_per_kwh, 4);
                $forecastError = $forecast->forecast_price_cents_per_kwh - $actual['price'];
                $actualDirection = $this->forecastService->directionLabel($actualChange, $provenance['threshold']);

                $sourceMetadata = $forecast->source_metadata ?? [];
                $forecastCategory = ForecastOutlook::category($forecast->direction);
                $actualCategory = ForecastOutlook::category($actualDirection);
                $sourceMetadata['forecast_direction_category'] = $forecastCategory;
                $sourceMetadata['actual_direction_category'] = $actualCategory;
                $sourceMetadata['direction_outcome'] = ForecastOutlook::outcome($forecastCategory, $actualCategory);
                $sourceMetadata['evaluation_method_version'] = 'same_basis_v1';
                $sourceMetadata['actual_retail_method_version'] = $provenance['method'];
                $sourceMetadata['evaluation_direction_threshold_cents_per_kwh'] = $provenance['threshold'];
                $sourceMetadata['unchanged_price_absolute_error_cents_per_kwh'] = round(abs($actualChange), 4);
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
                ]);

                if (! $dryRun) {
                    $forecast->save();
                }

                $evaluated++;
                $updated->push($forecast);
            }
        });

        return [
            'evaluated' => $evaluated,
            'missing_actual' => $missingActual,
            'unsupported_provenance' => $unsupportedProvenance,
            'forecasts' => $updated,
        ];
    }

    public function storedEvaluationProvenance(FixedContractPriceForecast $forecast): ?array
    {
        $provenance = $this->evaluationProvenance($forecast);
        $metadata = $forecast->source_metadata;
        if ($provenance === null || ! is_array($metadata)
            || ($metadata['evaluation_method_version'] ?? null) !== 'same_basis_v1'
            || ($metadata['actual_retail_pricing_basis'] ?? null) !== $provenance['basis']
            || ($metadata['actual_retail_method_version'] ?? null) !== $provenance['method']
            || ! is_numeric($metadata['evaluation_direction_threshold_cents_per_kwh'] ?? null)
            || (float) $metadata['evaluation_direction_threshold_cents_per_kwh'] !== $provenance['threshold']
            || ($metadata['actual_retail_source_date'] ?? null) !== $forecast->target_date?->toDateString()
            || ($metadata['actual_retail_segment'] ?? null) !== (FixedTermPriceForecastService::SEGMENTS[$forecast->duration_months] ?? null)
            || ($metadata['actual_retail_metric'] ?? null) !== 'energy_price'
            || ! is_numeric($metadata['actual_retail_contract_count'] ?? null)
            || (float) $metadata['actual_retail_contract_count'] <= 0) {
            return null;
        }

        return $provenance + ['evaluation_method' => $metadata['evaluation_method_version']];
    }

    private function evaluationProvenance(FixedContractPriceForecast $forecast): ?array
    {
        $metadata = $forecast->source_metadata;
        if ($metadata !== null && ! is_array($metadata)) {
            return null;
        }
        $metadata ??= [];
        $v1 = $forecast->model_version === 'fixed_term_ewma_gap_v1';
        $legacyUnitMethod = $v1 || $forecast->model_version === 'fixed_term_ewma_gap_v2';
        $basis = array_key_exists('current_retail_pricing_basis', $metadata)
            ? $metadata['current_retail_pricing_basis']
            : ($v1 ? FixedTermPriceForecastService::OBSERVED_PRICING_BASIS : null);
        $method = array_key_exists('current_retail_method_version', $metadata)
            ? $metadata['current_retail_method_version']
            : ($legacyUnitMethod ? ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION : null);
        $threshold = array_key_exists('direction_threshold_cents_per_kwh', $metadata)
            ? $metadata['direction_threshold_cents_per_kwh']
            : ($v1 ? 0.15 : null);

        if (! in_array($basis, [FixedTermPriceForecastService::OBSERVED_PRICING_BASIS, FixedTermPriceForecastService::CANONICAL_PRICING_BASIS], true)
            || $method !== ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION
            || ! is_numeric($threshold) || ! is_finite((float) $threshold) || (float) $threshold < 0) {
            return null;
        }

        return ['basis' => $basis, 'method' => $method, 'threshold' => (float) $threshold];
    }
}
