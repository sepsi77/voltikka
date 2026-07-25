<?php

namespace App\Services\RetailPremium;

use App\Models\RetailPremiumObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only calibration report for the market-reset pass-through coefficient.
 *
 * The market-reset estimator in `../CanonicalPricing/MarketReset/` prices every reset
 * lineage with one global `beta`. That value is measured for the MONTHLY cadence only,
 * from two companies; the quarterly lineages use it as an unverified prior because the
 * FI futures curve history starts 2026-04-08 and a quarterly product only reprices four
 * times a year.
 *
 * This service measures `beta` again from the stored observation dataset so the
 * scheduled report can surface the moment the quarterly cadence becomes measurable and
 * disagrees with the configured value. It writes nothing and changes no pricing.
 */
class RetailPremiumCalibrationService
{
    /** Market-reset population: products that publish one price per period. */
    public const RESET_CADENCES = ['monthly', 'quarterly', 'seasonal'];

    /** Wholesale reference candidates a reset price can plausibly be set from. */
    public const REFERENCE_KINDS = ['month', 'quarter', 'quarter_month_average', 'year'];

    /**
     * The reference kind the ESTIMATOR uses for each cadence, mirroring
     * CanonicalPricing/MarketReset/AGENTS.md rule 3. The headline measurement must use
     * the same kind, otherwise it is not comparable with the coefficient in production.
     *
     * Selecting purely by lowest premium spread instead let a quarter-shaped reference win
     * for a *monthly* product (Pohjois-Karjalan on 2026-07-25), which is not what the
     * estimator does, and it chose inconsistently within one cadence. The cross-reference
     * comparison is still reported, but as the open month-versus-quarter question rather
     * than as the measurement.
     *
     * @var array<string, list<string>>
     */
    public const CADENCE_REFERENCE_PREFERENCE = [
        'monthly' => ['month'],
        'quarterly' => ['quarter', 'quarter_month_average'],
        'seasonal' => ['quarter', 'quarter_month_average'],
    ];

    /**
     * A reference that did not move carries no pass-through information, and dividing
     * a real retail move by it would be noise amplification. Those pairs are skipped.
     */
    public const MIN_REFERENCE_MOVE_CENTS_PER_KWH = 0.01;

    /**
     * Most reset rows still carry `vat_basis = unknown` (a documented upstream gap:
     * the source payload has no VAT field). Those rows are NOT discarded and VAT is
     * NOT inferred from `target_group`; instead the whole measurement is reported
     * under both assumptions, because `beta` is ambiguous by the 1.255 VAT factor.
     */
    public const VAT_ASSUMPTIONS = ['included', 'excluded'];

    /**
     * Build the full calibration report.
     *
     * @return array<string, mixed>
     */
    public function analyse(?float $reviewThreshold = null, ?int $minPairsPerCompany = null): array
    {
        $reviewThreshold = $reviewThreshold ?? (float) config('retail_premium.calibration.beta_review_threshold', 0.25);
        $minPairsPerCompany = $minPairsPerCompany ?? (int) config('retail_premium.calibration.min_pairs_per_company', 3);
        $configuredBeta = (float) config('canonical_pricing.reset_forward_shift.beta', 1.0);

        $methodVersions = $this->resolveMethodVersions();
        $observations = $this->observations($methodVersions);
        $series = $this->buildSeries($observations);

        $scenarios = [];

        foreach (self::VAT_ASSUMPTIONS as $assumption) {
            $scenarios[$assumption] = $this->measure($series, $assumption, $minPairsPerCompany);
        }

        $report = [
            'generated_at' => CarbonImmutable::now('Europe/Helsinki')->toIso8601String(),
            'method_versions' => $methodVersions,
            'configured_beta' => $configuredBeta,
            'review_threshold' => $reviewThreshold,
            'min_pairs_per_company' => $minPairsPerCompany,
            'observation_count' => $observations->count(),
            'series_count' => count($series),
            'multi_period_series_count' => count(array_filter(
                $series,
                fn (array $one) => count($one['periods']) >= 2,
            )),
            'vat_unknown_observation_count' => $observations
                ->reject(fn (RetailPremiumObservation $row) => in_array($row->vat_basis, self::VAT_ASSUMPTIONS, true))
                ->count(),
            'scenarios' => $scenarios,
        ];

        $report['vat_ambiguous'] = $report['vat_unknown_observation_count'] > 0;
        $report['groups'] = $this->mergeGroups($scenarios);
        $report['cadences'] = $this->mergeCadences($scenarios, $configuredBeta, $minPairsPerCompany);
        $report['readiness'] = $this->readiness($scenarios, $minPairsPerCompany);
        $report['review'] = $this->review($report['cadences'], $configuredBeta, $reviewThreshold, $minPairsPerCompany);

        return $report;
    }

    /**
     * Resolve the newest method version of each family.
     *
     * Filtering to the current pair is mandatory: `method_version` is part of the unique
     * row identity, so superseded versions stay in the table forever beside the current
     * ones and carry known defects (duplicate price periods, missing quarter references).
     * Mixing them double-counts price periods.
     *
     * The stored rows are scanned rather than hardcoded, but the service constants act as
     * a floor so an incompletely re-collected table can never pull the report back onto an
     * older version.
     *
     * @return list<string>
     */
    public function resolveMethodVersions(): array
    {
        $stored = RetailPremiumObservation::query()
            ->distinct()
            ->pluck('method_version')
            ->all();

        return [
            $this->newestVersion('retail-premium-v', RetailPremiumObservationService::METHOD_VERSION, $stored),
            $this->newestVersion('retail-premium-history-v', RetailPremiumHistoryBackfillService::METHOD_VERSION, $stored),
        ];
    }

    /**
     * @param  list<string>  $stored
     */
    private function newestVersion(string $prefix, string $current, array $stored): string
    {
        $newest = (int) substr($current, strlen($prefix));

        foreach ($stored as $version) {
            if (! is_string($version) || ! str_starts_with($version, $prefix)) {
                continue;
            }

            $suffix = substr($version, strlen($prefix));

            if (ctype_digit($suffix)) {
                $newest = max($newest, (int) $suffix);
            }
        }

        return $prefix.$newest;
    }

    /**
     * @param  list<string>  $methodVersions
     * @return Collection<int, RetailPremiumObservation>
     */
    private function observations(array $methodVersions): Collection
    {
        return RetailPremiumObservation::query()
            ->whereIn('method_version', $methodVersions)
            ->whereIn('cadence', self::RESET_CADENCES)
            ->where('quality', '!=', 'not_comparable')
            ->whereIn('reference_kind', self::REFERENCE_KINDS)
            ->whereNotNull('retail_energy_price_cents_per_kwh')
            ->whereNotNull('reference_price_including_vat_cents_per_kwh')
            ->whereNotNull('reference_price_excluding_vat_cents_per_kwh')
            ->orderBy('first_observed_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Group observations into per-lineage price-period series.
     *
     * @param  Collection<int, RetailPremiumObservation>  $observations
     * @return array<string, array<string, mixed>>
     */
    private function buildSeries(Collection $observations): array
    {
        $series = [];

        foreach ($observations as $row) {
            $key = implode('|', [
                (string) $row->lineage_key,
                (string) $row->energy_component_type,
                (string) $row->reference_kind,
            ]);

            $series[$key] ??= [
                'series_key' => $key,
                'company_name' => (string) $row->company_name,
                'cadence' => (string) $row->cadence,
                'lineage_key' => (string) $row->lineage_key,
                'energy_component_type' => (string) $row->energy_component_type,
                'reference_kind' => (string) $row->reference_kind,
                'periods' => [],
            ];

            $series[$key]['periods'][] = [
                'first_observed_date' => $row->first_observed_date?->toDateString(),
                'retail' => (float) $row->retail_energy_price_cents_per_kwh,
                'reference_included' => (float) $row->reference_price_including_vat_cents_per_kwh,
                'reference_excluded' => (float) $row->reference_price_excluding_vat_cents_per_kwh,
                'vat_basis' => (string) $row->vat_basis,
                // The seam where reconstructed history hands over to forward collection looks
                // like a new price period at an unchanged price. It is a method boundary, not a
                // reset, so the step across it must not enter a pass-through fit.
                'method_seam' => in_array('continues_prior_history_period', $row->quality_flags ?? [], true),
            ];
        }

        return $series;
    }

    /**
     * Measure premium stability and pass-through under one VAT assumption.
     *
     * @param  array<string, array<string, mixed>>  $series
     * @return array<string, mixed>
     */
    private function measure(array $series, string $vatAssumption, int $minPairsPerCompany): array
    {
        $grid = [];
        $flatReferenceSkipped = 0;
        $seamSkipped = 0;

        foreach ($series as $one) {
            $periods = $one['periods'];

            if (count($periods) < 2) {
                continue;
            }

            $premiums = [];
            $pairs = [];

            foreach ($periods as $index => $period) {
                $reference = $this->referencePrice($period, $vatAssumption);
                $premiums[] = $period['retail'] - $reference;

                if ($index === 0) {
                    continue;
                }

                if ($period['method_seam']) {
                    $seamSkipped++;

                    continue;
                }

                $previous = $periods[$index - 1];
                $deltaRetail = $period['retail'] - $previous['retail'];
                $deltaReference = $reference - $this->referencePrice($previous, $vatAssumption);

                if (abs($deltaReference) < self::MIN_REFERENCE_MOVE_CENTS_PER_KWH) {
                    $flatReferenceSkipped++;

                    continue;
                }

                $pairs[] = ['dp' => $deltaRetail, 'df' => $deltaReference];
            }

            $gridKey = implode('|', [$one['company_name'], $one['cadence'], $one['reference_kind']]);
            $grid[$gridKey] ??= [
                'company_name' => $one['company_name'],
                'cadence' => $one['cadence'],
                'reference_kind' => $one['reference_kind'],
                'series' => 0,
                'periods' => 0,
                'pairs' => [],
                'premium_sds' => [],
            ];

            $grid[$gridKey]['series']++;
            $grid[$gridKey]['periods'] += count($periods);
            $grid[$gridKey]['pairs'] = array_merge($grid[$gridKey]['pairs'], $pairs);

            $sd = $this->standardDeviation($premiums);

            if ($sd !== null) {
                $grid[$gridKey]['premium_sds'][] = $sd;
            }
        }

        $grid = array_map(function (array $cell) {
            $fit = $this->throughOriginFit($cell['pairs']);

            return [
                'company_name' => $cell['company_name'],
                'cadence' => $cell['cadence'],
                'reference_kind' => $cell['reference_kind'],
                'series' => $cell['series'],
                'periods' => $cell['periods'],
                'pair_count' => count($cell['pairs']),
                'beta' => $fit['beta'],
                'r_squared' => $fit['r_squared'],
                'mean_premium_sd' => $cell['premium_sds'] === []
                    ? null
                    : array_sum($cell['premium_sds']) / count($cell['premium_sds']),
                'pairs' => $cell['pairs'],
            ];
        }, $grid);

        ksort($grid);

        $groups = $this->selectBestReferenceKind($grid);

        return [
            'vat_assumption' => $vatAssumption,
            'flat_reference_pairs_skipped' => $flatReferenceSkipped,
            'method_seam_pairs_skipped' => $seamSkipped,
            'reference_kind_grid' => array_values(array_map(
                fn (array $cell) => collect($cell)->except('pairs')->all(),
                $grid,
            )),
            'groups' => array_map(
                fn (array $group) => collect($group)->except('pairs')->all(),
                $groups,
            ),
            'cadences' => $this->cadenceSummaries($groups, $minPairsPerCompany),
        ];
    }

    /**
     * Collapse the company/cadence/reference-kind grid to one row per company and cadence.
     *
     * The reference kind with the lowest premium standard deviation is the one that company
     * appears to price from, so pass-through is reported against that kind.
     *
     * One correction to that rule: a reference kind that never moved inside the observed window
     * has a premium standard deviation of zero and would win on stability while carrying no
     * pass-through information at all. Kinds that produced at least one pass-through pair are
     * therefore ranked first, and the purely most-stable kind is reported beside it.
     *
     * @param  array<string, array<string, mixed>>  $grid
     * @return list<array<string, mixed>>
     */
    private function selectBestReferenceKind(array $grid): array
    {
        $byGroup = [];

        foreach ($grid as $cell) {
            $byGroup[$cell['company_name'].'|'.$cell['cadence']][] = $cell;
        }

        ksort($byGroup);

        $groups = [];

        foreach ($byGroup as $cells) {
            $ranked = $cells;
            usort(
                $ranked,
                fn (array $a, array $b) => $this->stabilityRank($a, false) <=> $this->stabilityRank($b, false),
            );
            $mostStable = $ranked[0];

            $measured = $cells;
            usort(
                $measured,
                fn (array $a, array $b) => $this->stabilityRank($a, true) <=> $this->stabilityRank($b, true),
            );

            // The headline uses the cadence-appropriate reference, so the measurement is
            // comparable with what the estimator actually does. Fall back to the most stable
            // kind that produced a pair only when the preferred kinds yielded none.
            $preferred = self::CADENCE_REFERENCE_PREFERENCE[$cells[0]['cadence']] ?? [];
            $best = $measured[0];

            foreach ($preferred as $kind) {
                $match = collect($measured)->first(
                    fn (array $cell) => $cell['reference_kind'] === $kind && $cell['pair_count'] > 0,
                );

                if ($match !== null) {
                    $best = $match;

                    break;
                }
            }

            $groups[] = [
                'company_name' => $best['company_name'],
                'cadence' => $best['cadence'],
                'best_reference_kind' => $best['reference_kind'],
                'reference_kind_is_cadence_preferred' => in_array($best['reference_kind'], $preferred, true),
                'most_stable_reference_kind' => $mostStable['reference_kind'],
                'most_stable_premium_sd' => $mostStable['mean_premium_sd'],
                'series' => $best['series'],
                'pair_count' => $best['pair_count'],
                'beta' => $best['beta'],
                'r_squared' => $best['r_squared'],
                'mean_premium_sd' => $best['mean_premium_sd'],
                'reference_kinds_compared' => array_map(fn (array $cell) => [
                    'reference_kind' => $cell['reference_kind'],
                    'mean_premium_sd' => $cell['mean_premium_sd'],
                    'pair_count' => $cell['pair_count'],
                    'beta' => $cell['beta'],
                    'r_squared' => $cell['r_squared'],
                ], $ranked),
                'pairs' => $best['pairs'],
            ];
        }

        return $groups;
    }

    /**
     * Sort key for reference-kind selection. Lower sorts first.
     *
     * @param  array<string, mixed>  $cell
     * @return list<int|float>
     */
    private function stabilityRank(array $cell, bool $requirePairs): array
    {
        return [
            $requirePairs && $cell['pair_count'] === 0 ? 1 : 0,
            $cell['mean_premium_sd'] === null ? 1 : 0,
            $cell['mean_premium_sd'] ?? PHP_FLOAT_MAX,
            -$cell['pair_count'],
            (int) array_search($cell['reference_kind'], self::REFERENCE_KINDS, true),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function cadenceSummaries(array $groups, int $minPairsPerCompany): array
    {
        $summaries = [];

        foreach (self::RESET_CADENCES as $cadence) {
            $cadenceGroups = array_values(array_filter(
                $groups,
                fn (array $group) => $group['cadence'] === $cadence && $group['pair_count'] > 0,
            ));

            $readyGroups = array_values(array_filter(
                $cadenceGroups,
                fn (array $group) => $group['pair_count'] >= $minPairsPerCompany,
            ));

            $pooledPairs = [];

            foreach ($cadenceGroups as $group) {
                $pooledPairs = array_merge($pooledPairs, $group['pairs']);
            }

            $pooled = $this->throughOriginFit($pooledPairs);

            $summaries[$cadence] = [
                'cadence' => $cadence,
                'companies_with_pairs' => count($cadenceGroups),
                'companies_ready' => count($readyGroups),
                'pair_count' => count($pooledPairs),
                'company_betas' => collect($cadenceGroups)
                    ->mapWithKeys(fn (array $group) => [$group['company_name'] => $group['beta']])
                    ->all(),
                'median_company_beta' => $this->median(
                    collect($cadenceGroups)->pluck('beta')->filter(fn ($beta) => $beta !== null)->all(),
                ),
                'median_ready_company_beta' => $this->median(
                    collect($readyGroups)->pluck('beta')->filter(fn ($beta) => $beta !== null)->all(),
                ),
                // Secondary only. A through-origin fit weights by dF^2, so one series with poor
                // pass-through dominates the pool; the measured pooled figure was 0.53 while both
                // usable companies sat at 0.90 and 1.01. Never present this as the headline.
                'pooled_beta' => $pooled['beta'],
                'pooled_r_squared' => $pooled['r_squared'],
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarios
     * @return list<array<string, mixed>>
     */
    private function mergeGroups(array $scenarios): array
    {
        $merged = [];

        foreach ($scenarios as $assumption => $scenario) {
            foreach ($scenario['groups'] as $group) {
                $key = $group['company_name'].'|'.$group['cadence'];
                $merged[$key] ??= [
                    'company_name' => $group['company_name'],
                    'cadence' => $group['cadence'],
                ];
                $merged[$key]['best_reference_kind_'.$assumption] = $group['best_reference_kind'];
                $merged[$key]['reference_kind_is_cadence_preferred_'.$assumption] = $group['reference_kind_is_cadence_preferred'];
                $merged[$key]['most_stable_reference_kind_'.$assumption] = $group['most_stable_reference_kind'];
                $merged[$key]['most_stable_premium_sd_'.$assumption] = $group['most_stable_premium_sd'];
                $merged[$key]['series_'.$assumption] = $group['series'];
                $merged[$key]['pair_count_'.$assumption] = $group['pair_count'];
                $merged[$key]['beta_'.$assumption] = $group['beta'];
                $merged[$key]['r_squared_'.$assumption] = $group['r_squared'];
                $merged[$key]['mean_premium_sd_'.$assumption] = $group['mean_premium_sd'];
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarios
     * @return array<string, array<string, mixed>>
     */
    private function mergeCadences(array $scenarios, float $configuredBeta, int $minPairsPerCompany): array
    {
        $merged = [];

        foreach (self::RESET_CADENCES as $cadence) {
            $row = ['cadence' => $cadence];

            foreach ($scenarios as $assumption => $scenario) {
                $summary = $scenario['cadences'][$cadence];
                // A pair can survive the flat-reference skip under one VAT scale and not the
                // other, so the counts are taken as the widest reading across assumptions.
                $row['companies_with_pairs'] = max($row['companies_with_pairs'] ?? 0, $summary['companies_with_pairs']);
                $row['companies_ready'] = max($row['companies_ready'] ?? 0, $summary['companies_ready']);
                $row['pair_count'] = max($row['pair_count'] ?? 0, $summary['pair_count']);
                $row['median_company_beta_'.$assumption] = $summary['median_company_beta'];
                $row['median_ready_company_beta_'.$assumption] = $summary['median_ready_company_beta'];
                $row['pooled_beta_'.$assumption] = $summary['pooled_beta'];
                $row['company_betas_'.$assumption] = $summary['company_betas'];
                // Gated on the ready median only. A single pass-through pair is not a
                // measurement: on 2026-07-25 two monthly companies had one pair each (0.00 and
                // -0.86) and dragged the headline to "median 0.00, difference -1.00", while the
                // only company with a real sample sat at 1.01. Never compare an ungated median
                // against the configured value.
                $row['difference_from_configured_'.$assumption] = $summary['median_ready_company_beta'] === null
                    ? null
                    : $summary['median_ready_company_beta'] - $configuredBeta;
            }

            $row['measurable'] = $row['companies_ready'] >= 1;
            $row['min_pairs_per_company'] = $minPairsPerCompany;
            $merged[$cadence] = $row;
        }

        return $merged;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarios
     * @return array<string, mixed>
     */
    private function readiness(array $scenarios, int $minPairsPerCompany): array
    {
        $companies = [];

        foreach ($scenarios as $scenario) {
            foreach ($scenario['groups'] as $group) {
                $companies[$group['company_name']] = max(
                    $companies[$group['company_name']] ?? 0,
                    $group['pair_count'],
                );
            }
        }

        $ready = array_filter($companies, fn (int $pairs) => $pairs >= $minPairsPerCompany);
        ksort($companies);

        return [
            'min_pairs_per_company' => $minPairsPerCompany,
            'companies_with_pairs' => count(array_filter($companies, fn (int $pairs) => $pairs > 0)),
            'companies_ready' => count($ready),
            'ready_company_names' => array_keys($ready),
            'pairs_by_company' => $companies,
        ];
    }

    /**
     * Decide whether the configured global `beta` needs a human review.
     *
     * Quarterly is the cadence that matters: the configured value is measured on the
     * monthly cadence only and the quarterly lineages carry it as a prior.
     *
     * The measurement is ambiguous by the 1.255 VAT factor while most reset rows carry an
     * unknown VAT basis, so the review only fires when NO VAT assumption reconciles the
     * measurement with the configured value. Escalating on the worse assumption alone
     * would fire on every run purely because of that factor.
     *
     * @param  array<string, array<string, mixed>>  $cadences
     * @return array<string, mixed>
     */
    private function review(array $cadences, float $configuredBeta, float $threshold, int $minPairsPerCompany): array
    {
        $quarterly = $cadences['quarterly'];
        $measurable = $quarterly['companies_ready'] >= 1;

        $differences = [];

        foreach (self::VAT_ASSUMPTIONS as $assumption) {
            $beta = $quarterly['median_ready_company_beta_'.$assumption];

            if ($beta !== null) {
                $differences[$assumption] = abs($beta - $configuredBeta);
            }
        }

        $smallest = $differences === [] ? null : min($differences);
        $needed = $measurable && $smallest !== null && $smallest > $threshold;

        return [
            'cadence' => 'quarterly',
            'quarterly_measurable' => $measurable,
            'companies_ready' => $quarterly['companies_ready'],
            'min_pairs_per_company' => $minPairsPerCompany,
            'configured_beta' => $configuredBeta,
            'threshold' => $threshold,
            'absolute_difference_by_vat_assumption' => $differences,
            'smallest_absolute_difference' => $smallest,
            'review_needed' => $needed,
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function referencePrice(array $period, string $vatAssumption): float
    {
        $basis = in_array($period['vat_basis'], self::VAT_ASSUMPTIONS, true)
            ? $period['vat_basis']
            : $vatAssumption;

        return $basis === 'included'
            ? $period['reference_included']
            : $period['reference_excluded'];
    }

    /**
     * Through-origin least squares fit of dP on dF.
     *
     * @param  list<array{dp: float, df: float}>  $pairs
     * @return array{beta: float|null, r_squared: float|null}
     */
    private function throughOriginFit(array $pairs): array
    {
        if ($pairs === []) {
            return ['beta' => null, 'r_squared' => null];
        }

        $sumCross = 0.0;
        $sumSquares = 0.0;

        foreach ($pairs as $pair) {
            $sumCross += $pair['dp'] * $pair['df'];
            $sumSquares += $pair['df'] ** 2;
        }

        if ($sumSquares <= 0.0) {
            return ['beta' => null, 'r_squared' => null];
        }

        $beta = $sumCross / $sumSquares;

        $residual = 0.0;
        $total = 0.0;

        foreach ($pairs as $pair) {
            $residual += ($pair['dp'] - $beta * $pair['df']) ** 2;
            $total += $pair['dp'] ** 2;
        }

        return [
            'beta' => $beta,
            // A through-origin fit on a single pair passes exactly through that point, so an R2 of
            // 1.0 would be an artifact of the arithmetic rather than evidence of a good fit.
            'r_squared' => $total <= 0.0 || count($pairs) < 2 ? null : 1 - $residual / $total,
        ];
    }

    /**
     * Sample standard deviation. Needs at least two periods.
     *
     * @param  list<float>  $values
     */
    private function standardDeviation(array $values): ?float
    {
        $count = count($values);

        if ($count < 2) {
            return null;
        }

        $mean = array_sum($values) / $count;
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / ($count - 1));
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
