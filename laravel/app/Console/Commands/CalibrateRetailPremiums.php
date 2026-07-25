<?php

namespace App\Console\Commands;

use App\Services\RetailPremium\RetailPremiumCalibrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only calibration report for the market-reset pass-through coefficient.
 *
 * It measures `beta` from `retail_premium_observations` and compares it with the single
 * global value the market-reset estimator uses. It writes nothing and changes no pricing.
 *
 * It runs on a schedule so the measurement surfaces itself: when the quarterly cadence
 * becomes measurable and disagrees with the configured global value, the summary line is
 * logged at `warning` level with an explicit review request. Nobody has to remember to
 * read a docs file.
 */
class CalibrateRetailPremiums extends Command
{
    protected $signature = 'retail-premiums:calibrate
        {--threshold= : Absolute beta difference that asks for a review. Defaults to config.}
        {--min-pairs= : Pass-through pairs a company needs to count as measured. Defaults to config.}
        {--json= : Write the full report to this path}';

    protected $description = 'Measure market-reset pass-through (beta) from stored retail premium observations';

    public function handle(RetailPremiumCalibrationService $service): int
    {
        $report = $service->analyse(
            $this->option('threshold') === null ? null : (float) $this->option('threshold'),
            $this->option('min-pairs') === null ? null : (int) $this->option('min-pairs'),
        );

        $this->renderHeader($report);
        $this->renderGroups($report);
        $this->renderCadences($report);
        $this->renderReadiness($report);

        if ($this->option('json')) {
            file_put_contents(
                $this->option('json'),
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
            $this->newLine();
            $this->info('Wrote the full calibration report to '.$this->option('json'));
        }

        $this->logSummary($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHeader(array $report): void
    {
        $this->info('Market-reset pass-through calibration (read-only)');
        $this->line('Method versions: '.implode(', ', $report['method_versions']));
        $this->line(sprintf(
            'Observations: %d, series: %d, multi-period series: %d',
            $report['observation_count'],
            $report['series_count'],
            $report['multi_period_series_count'],
        ));
        $this->line(sprintf(
            'Configured global beta: %.2f, review threshold: %.2f, pairs needed per company: %d',
            $report['configured_beta'],
            $report['review_threshold'],
            $report['min_pairs_per_company'],
        ));

        if ($report['vat_ambiguous']) {
            $this->line(sprintf(
                '%d of %d observations carry an unknown VAT basis, so beta is ambiguous by the 1.255 VAT '
                .'factor. Every figure is reported under both assumptions.',
                $report['vat_unknown_observation_count'],
                $report['observation_count'],
            ));
        }

        $skipped = $report['scenarios']['included'];
        $this->line(sprintf(
            'Skipped pairs: %d with a flat reference (|dF| < %.2f c/kWh), %d across a method-version seam.',
            $skipped['flat_reference_pairs_skipped'],
            RetailPremiumCalibrationService::MIN_REFERENCE_MOVE_CENTS_PER_KWH,
            $skipped['method_seam_pairs_skipped'],
        ));
        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderGroups(array $report): void
    {
        if ($report['groups'] === []) {
            $this->warn('No multi-period market-reset series exist yet, so pass-through cannot be measured.');
            $this->newLine();

            return;
        }

        $this->table(
            [
                'Company', 'Cadence', 'Series', 'Pairs',
                'beta (VAT incl.)', 'beta (VAT excl.)', 'R2 (incl.)', 'Measured reference (sd)', 'Most stable (sd)',
            ],
            collect($report['groups'])->map(fn (array $group) => [
                $group['company_name'],
                $group['cadence'],
                $group['series_included'] ?? 0,
                $group['pair_count_included'] ?? 0,
                $this->number($group['beta_included'] ?? null),
                $this->number($group['beta_excluded'] ?? null),
                $this->number($group['r_squared_included'] ?? null),
                sprintf(
                    '%s (%s)',
                    $group['best_reference_kind_included'] ?? 'n/a',
                    $this->number($group['mean_premium_sd_included'] ?? null),
                ),
                sprintf(
                    '%s (%s)',
                    $group['most_stable_reference_kind_included'] ?? 'n/a',
                    $this->number($group['most_stable_premium_sd_included'] ?? null),
                ),
            ])->all(),
        );
        $this->line('Premium standard deviations are in c/kWh. "Measured reference" is the most stable reference '
            .'kind that produced at least one pass-through pair.');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderCadences(array $report): void
    {
        $this->info('Per cadence against the configured global beta of '.sprintf('%.2f', $report['configured_beta']));

        foreach ($report['cadences'] as $cadence => $row) {
            if ($row['pair_count'] === 0) {
                $this->line(sprintf(
                    '  %s: no pass-through pairs yet. UNCALIBRATED — the configured global beta is an '
                    .'unverified prior for this cadence.',
                    $cadence,
                ));

                continue;
            }

            $this->line(sprintf(
                '  %s: %d companies with pairs, %d with at least %d pairs, %d pairs total.',
                $cadence,
                $row['companies_with_pairs'],
                $row['companies_ready'],
                $row['min_pairs_per_company'],
                $row['pair_count'],
            ));

            if ($row['measurable']) {
                $this->line(sprintf(
                    '    measured beta (companies with >= %d pairs): %s (VAT incl.) / %s (VAT excl.)',
                    $row['min_pairs_per_company'],
                    $this->number($row['median_ready_company_beta_included']),
                    $this->number($row['median_ready_company_beta_excluded']),
                ));
                $this->line(sprintf(
                    '    difference from configured: %s (VAT incl.) / %s (VAT excl.)',
                    $this->signed($row['difference_from_configured_included']),
                    $this->signed($row['difference_from_configured_excluded']),
                ));
            } else {
                // Deliberately no number here. Single-pair companies are shown in the table
                // above but must not produce a headline beta or a comparison.
                $this->line(sprintf(
                    '    not enough evidence for a beta: no company reaches %d pass-through pairs.',
                    $row['min_pairs_per_company'],
                ));
            }

            $this->line(sprintf(
                '    context only, below the %d-pair bar — median across all companies with any pair: %s (VAT incl.)',
                $row['min_pairs_per_company'],
                $this->number($row['median_company_beta_included']),
            ));
            $this->line(sprintf(
                '    secondary, do not read as the headline — pooled beta %s (VAT incl.), weighted by dF^2 so one '
                .'weak series dominates',
                $this->number($row['pooled_beta_included']),
            ));

            if (! $row['measurable']) {
                $this->line(sprintf(
                    '    UNCALIBRATED: no company reaches %d pass-through pairs, so the configured global beta '
                    .'stays an unverified prior for this cadence.',
                    $row['min_pairs_per_company'],
                ));
            }
        }

        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReadiness(array $report): void
    {
        $readiness = $report['readiness'];
        $this->info('Readiness for a per-company parameter');
        $this->line(sprintf(
            '  %d of %d companies have at least %d pass-through pairs%s',
            $readiness['companies_ready'],
            $readiness['companies_with_pairs'],
            $readiness['min_pairs_per_company'],
            $readiness['ready_company_names'] === []
                ? '.'
                : ': '.implode(', ', $readiness['ready_company_names']),
        ));

        $review = $report['review'];

        if ($review['review_needed']) {
            $this->warn(sprintf(
                '  Calibration review needed: quarterly pass-through is measurable and its median company beta '
                .'differs from the configured %.2f by %.2f under every VAT assumption (threshold %.2f).',
                $review['configured_beta'],
                $review['smallest_absolute_difference'],
                $review['threshold'],
            ));

            return;
        }

        if (! $review['quarterly_measurable']) {
            $this->line(sprintf(
                '  Quarterly is still uncalibrated: no company reaches %d pass-through pairs. The 1 October 2026 '
                .'resets give every quarterly lineage a second period.',
                $review['min_pairs_per_company'],
            ));

            return;
        }

        $this->line('  Quarterly pass-through is measurable and agrees with the configured global beta.');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function logSummary(array $report): void
    {
        $review = $report['review'];
        $quarterly = $report['cadences']['quarterly'];
        $monthly = $report['cadences']['monthly'];

        $context = [
            'method_versions' => $report['method_versions'],
            'configured_beta' => $report['configured_beta'],
            'observations' => $report['observation_count'],
            'multi_period_series' => $report['multi_period_series_count'],
            'monthly_pairs' => $monthly['pair_count'],
            // The `*_median_beta_*` keys are the pair-gated medians. The ungated ones are kept
            // beside them as `*_median_beta_*_all_companies` for context, never as the figure.
            'monthly_median_beta_vat_included' => $monthly['median_ready_company_beta_included'],
            'monthly_median_beta_vat_included_all_companies' => $monthly['median_company_beta_included'],
            'monthly_companies_ready' => $monthly['companies_ready'],
            'quarterly_pairs' => $quarterly['pair_count'],
            'quarterly_companies_ready' => $quarterly['companies_ready'],
            'quarterly_median_beta_vat_included' => $quarterly['median_ready_company_beta_included'],
            'quarterly_median_beta_vat_excluded' => $quarterly['median_ready_company_beta_excluded'],
            'quarterly_median_beta_vat_included_all_companies' => $quarterly['median_company_beta_included'],
            'quarterly_measurable' => $review['quarterly_measurable'],
            'companies_ready' => $report['readiness']['companies_ready'],
            'review_needed' => $review['review_needed'],
        ];

        if ($review['review_needed']) {
            Log::warning(sprintf(
                'Retail premium calibration review needed: quarterly market-reset pass-through measures %s '
                .'against a configured global beta of %.2f, a difference of %.2f under every VAT assumption '
                .'(threshold %.2f).',
                $this->number($quarterly['median_ready_company_beta_included']),
                $review['configured_beta'],
                $review['smallest_absolute_difference'],
                $review['threshold'],
            ), $context);

            return;
        }

        // Both figures are the pair-gated medians, so the log line cannot report a beta that
        // rests on a single pass-through pair.
        Log::info(sprintf(
            'Retail premium calibration: %d multi-period reset series, monthly beta %s from %d pairs, quarterly '
            .'beta %s from %d pairs (%s), configured global beta %.2f.',
            $report['multi_period_series_count'],
            $this->number($monthly['median_ready_company_beta_included']),
            $monthly['pair_count'],
            $this->number($quarterly['median_ready_company_beta_included']),
            $quarterly['pair_count'],
            $review['quarterly_measurable'] ? 'measurable' : 'still uncalibrated',
            $report['configured_beta'],
        ), $context);
    }

    private function number(?float $value, string $suffix = ''): string
    {
        return $value === null ? 'n/a' : sprintf('%.2f%s', $value, $suffix);
    }

    private function signed(?float $value): string
    {
        return $value === null ? 'n/a' : sprintf('%+.2f', $value);
    }
}
