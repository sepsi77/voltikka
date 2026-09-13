<?php

namespace App\Services\PriceForecasting;

use Carbon\CarbonImmutable;

class FixedTermForecastReport
{
    public function __construct(private readonly FixedTermForecastEvaluationService $evaluationService) {}

    /** Input must be ordered by forecast date, then ID. No forecast rows are retained. */
    public function summarize(iterable $forecasts): array
    {
        $groups = [];
        $skips = array_fill_keys(['non_median', 'incomplete', 'unsupported_provenance', 'unsupported_direction', 'malformed_values'], 0);
        foreach ($forecasts as $forecast) {
            if ($forecast->target_quantile !== 'median') {
                $skips['non_median']++;

                continue;
            }
            if ($forecast->actual_price_cents_per_kwh === null || $forecast->evaluated_at === null) {
                $skips['incomplete']++;

                continue;
            }
            $attributes = $forecast->getAttributes();
            $values = array_map(fn ($key) => $attributes[$key] ?? null, ['current_price_cents_per_kwh', 'forecast_price_cents_per_kwh', 'actual_price_cents_per_kwh']);
            $dates = array_map(fn ($key) => $attributes[$key] ?? null, ['forecast_date', 'target_date']);
            $validDates = count(array_filter($dates, fn ($date) => is_string($date)
                && preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/', $date)
                && CarbonImmutable::hasFormat(substr($date, 0, 10), 'Y-m-d')
                && CarbonImmutable::parse($date)->toDateString() === substr($date, 0, 10))) === 2;
            if (! $validDates || count(array_filter($values, fn ($value) => is_numeric($value) && is_finite((float) $value))) !== 3
                || ! in_array($forecast->duration_months, [6, 12, 24], true)
                || $forecast->horizon_days < 1
                || ! $forecast->forecast_date->copy()->addDays($forecast->horizon_days)->isSameDay($forecast->target_date)) {
                $skips['malformed_values']++;

                continue;
            }
            $provenance = $this->evaluationService->storedEvaluationProvenance($forecast);
            if ($provenance === null) {
                $skips['unsupported_provenance']++;

                continue;
            }
            $actual = ForecastOutlook::category($forecast->actual_direction);
            $outcome = ForecastOutlook::outcome(ForecastOutlook::category($forecast->direction), $actual);
            if ($outcome === null) {
                $skips['unsupported_direction']++;

                continue;
            }
            $identity = [$forecast->model_version, $forecast->horizon_days, $forecast->duration_months,
                $provenance['basis'], $provenance['method'], $provenance['evaluation_method'], $provenance['threshold']];
            $key = json_encode($identity);
            $groups[$key] ??= ['identity' => $identity, 'n' => 0, 'issue_days' => 0, 'last_day' => null,
                'correct' => 0, 'wrong_way' => 0, 'missed_move' => 0, 'false_move' => 0, 'unchanged_correct' => 0];
            $group = &$groups[$key];
            $group['n']++;
            $group[$outcome]++;
            $group['unchanged_correct'] += (int) ($actual === 'flat');
            $day = $forecast->forecast_date->toDateString();
            if ($group['last_day'] !== $day) {
                $group['issue_days']++;
                $group['last_day'] = $day;
            }
            unset($group);
        }
        ksort($groups);

        return ['groups' => array_values($groups), 'skips' => $skips];
    }

    public function lines(array $summary): array
    {
        $lines = [];
        foreach ($summary['groups'] as $group) {
            [$version, $horizon, $duration, $basis, $method, $evaluation, $threshold] = $group['identity'];
            $lines[] = sprintf('%s | %dd | %dm | %s | %s | %s | threshold=%s: n=%d, issue_days=%d, correct=%d, wrong_way=%d, missed_move=%d, false_move=%d, unchanged_correct=%d, correct_rate=%.1f%%, unchanged_rate=%.1f%%',
                $version, $horizon, $duration, $basis, $method, $evaluation, $threshold,
                $group['n'], $group['issue_days'], $group['correct'], $group['wrong_way'], $group['missed_move'], $group['false_move'],
                $group['unchanged_correct'], 100 * $group['correct'] / $group['n'], 100 * $group['unchanged_correct'] / $group['n']);
        }
        if ($lines === []) {
            $lines[] = 'No supported completed median forecasts. Rates are unavailable.';
        }
        foreach ($summary['skips'] as $reason => $count) {
            $lines[] = "Skipped {$reason}: {$count}";
        }

        return $lines;
    }
}
