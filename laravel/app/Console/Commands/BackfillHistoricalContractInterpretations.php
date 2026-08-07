<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeHistoricalContractEpisode;
use App\Models\ContractHistoricalInterpretation;
use App\Models\ContractHistoricalInterpretationEpisode;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\ContractInterpretation\HistoricalContractEpisodeBuilder;
use App\Services\ContractInterpretation\HistoricalInterpretationFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillHistoricalContractInterpretations extends Command
{
    protected $signature = 'contracts:backfill-historical-interpretations
        {--from= : First selected historical date}
        {--to= : Last selected historical date}
        {--contract=* : Limit to one or more contract IDs}
        {--dispatch-limit=0 : Maximum jobs to dispatch; zero means all}
        {--retry-failed : Make failed current-version analyses pending again}
        {--resume-stale-processing= : Resume processing rows older than this many minutes}
        {--apply : Persist episodes and pending analysis rows}
        {--dispatch : Dispatch selected pending analyses after commit}
        {--plan-hash= : Exact dry-run plan hash required by --apply}
        {--yes : Confirm noninteractive dispatch}';

    protected $description = 'Plan or persist isolated historical contract interpretation episodes';

    public function handle(
        HistoricalContractEpisodeBuilder $builder,
        HistoricalInterpretationFingerprint $fingerprints,
    ): int {
        try {
            $options = $this->validatedOptions();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plan = $this->buildCompactPlan($builder, $fingerprints, $options);
        $this->printPlan($plan);

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        if (! is_string($this->option('plan-hash')) || ! hash_equals($plan['hash'], $this->option('plan-hash'))) {
            $this->error('Apply aborted: --plan-hash must exactly match this recomputed plan. No rows were written.');

            return self::FAILURE;
        }
        if ($this->option('dispatch') && ! config('services.openrouter.api_key')) {
            $this->error('Dispatch aborted: OPENROUTER_API_KEY is not configured. Apply without --dispatch is allowed.');

            return self::FAILURE;
        }
        if ($this->option('dispatch') && ! $this->option('yes')) {
            $this->error('Dispatch requires explicit --yes. No rows were written.');

            return self::FAILURE;
        }

        try {
            $dispatchIds = $this->persistVerifiedPlan($builder, $options, $plan);
        } catch (\RuntimeException $exception) {
            $this->error('Apply aborted during the persistence pass: '.$exception->getMessage().' The transaction was rolled back.');

            return self::FAILURE;
        }

        foreach ($dispatchIds as $id) {
            AnalyzeHistoricalContractEpisode::dispatch($id);
        }

        $this->info('Apply complete. Episodes and analyses are append-only; dispatched jobs: '.count($dispatchIds).'.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildCompactPlan(
        HistoricalContractEpisodeBuilder $builder,
        HistoricalInterpretationFingerprint $fingerprints,
        array $options,
    ): array {
        $discovery = [
            'scanned_contract_days' => 0,
            'eligible_days' => 0,
            'ineligible' => [],
            'grades' => [],
            'peak_full_episode_payloads' => 0,
        ];
        $entries = [];
        $selectedDispatch = [];
        $statusCounts = array_fill_keys(['validated', 'pending', 'processing', 'failed'], 0);
        $actionableCount = 0;
        $now = CarbonImmutable::now();

        foreach ($builder->discoverChunks($options['cutoff'], $options['contracts']) as $chunk) {
            $discovery['scanned_contract_days'] += $chunk['scanned_contract_days'];
            $discovery['eligible_days'] += $chunk['eligible_days'];
            $discovery['ineligible'] = $this->mergeCounts($discovery['ineligible'], $chunk['ineligible']);
            $discovery['grades'] = $this->mergeCounts($discovery['grades'], $chunk['grades']);
            $discovery['peak_full_episode_payloads'] = max(
                $discovery['peak_full_episode_payloads'],
                count($chunk['episodes']),
            );

            $episodes = $this->selectedEpisodes($chunk['episodes'], $options);
            if ($episodes === []) {
                unset($episodes, $chunk);

                continue;
            }

            $existing = ContractHistoricalInterpretation::query()
                ->select(['analysis_fingerprint', 'status', 'started_at', 'updated_at'])
                ->whereIn('analysis_fingerprint', array_column($episodes, 'analysis_fingerprint'))
                ->get()
                ->keyBy('analysis_fingerprint');

            foreach ($episodes as $episode) {
                $row = $existing->get($episode['analysis_fingerprint']);
                if ($row !== null && isset($statusCounts[$row->status])) {
                    $statusCounts[$row->status]++;
                }
                $action = $this->plannedAction($row, $options, $now);
                $entries[] = [
                    'episode_fingerprint' => $episode['episode_fingerprint'],
                    'manifest_fingerprint' => $episode['manifest_fingerprint'],
                    'analysis_fingerprint' => $episode['analysis_fingerprint'],
                    'action' => $action,
                ];
                if ($action !== 'skip') {
                    $actionableCount++;
                    if ($options['dispatch_limit'] === 0
                        || count($selectedDispatch) < $options['dispatch_limit']) {
                        $selectedDispatch[] = $episode['analysis_fingerprint'];
                    }
                }
            }
            unset($episode, $row, $episodes, $existing, $chunk);
        }

        ksort($discovery['ineligible']);
        ksort($discovery['grades']);
        $payload = [
            'builder_version' => HistoricalContractEpisodeBuilder::VERSION,
            'cutoff' => $options['cutoff']->toDateString(),
            'from' => $options['from']->toDateString(),
            'to' => $options['to']->toDateString(),
            'contracts' => $options['contracts'],
            'retry_failed' => $options['retry_failed'],
            'stale_minutes' => $options['stale_minutes'],
            'dispatch_limit' => $options['dispatch_limit'],
            'episodes' => $entries,
            'selected_dispatch' => $selectedDispatch,
        ];
        $hash = $fingerprints->hash($payload);
        unset($payload);

        return [
            'discovery' => $discovery,
            'entries' => $entries,
            'selected_dispatch' => $selectedDispatch,
            'status_counts' => $statusCounts,
            'actionable_count' => $actionableCount,
            'hash' => $hash,
        ];
    }

    /** @return list<int> */
    private function persistVerifiedPlan(
        HistoricalContractEpisodeBuilder $builder,
        array $options,
        array $plan,
    ): array {
        $selected = array_fill_keys($plan['selected_dispatch'], true);

        return DB::transaction(function () use ($builder, $options, $plan, $selected): array {
            $dispatchIds = [];
            $entryIndex = 0;

            foreach ($builder->discoverChunks($options['cutoff'], $options['contracts']) as $chunk) {
                foreach ($this->selectedEpisodes($chunk['episodes'], $options) as $episodePlan) {
                    $expected = $plan['entries'][$entryIndex] ?? null;
                    if (! is_array($expected)
                        || $expected['episode_fingerprint'] !== $episodePlan['episode_fingerprint']
                        || $expected['manifest_fingerprint'] !== $episodePlan['manifest_fingerprint']
                        || $expected['analysis_fingerprint'] !== $episodePlan['analysis_fingerprint']) {
                        throw new \RuntimeException('Historical evidence manifest changed after plan verification.');
                    }

                    $episode = ContractHistoricalInterpretationEpisode::firstOrCreate(
                        ['episode_fingerprint' => $episodePlan['episode_fingerprint']],
                        [
                            'contract_id' => $episodePlan['contract_id'],
                            'episode_start' => $episodePlan['episode_start'],
                            'episode_end' => $episodePlan['episode_end'],
                            'builder_version' => $episodePlan['builder_version'],
                            'evidence_fingerprint' => $episodePlan['evidence_fingerprint'],
                            'manifest_fingerprint' => $episodePlan['manifest_fingerprint'],
                            'evidence_grade' => $episodePlan['evidence_grade'],
                            'analysis_input' => $episodePlan['analysis_input'],
                            'evidence_manifest' => $episodePlan['evidence_manifest'],
                        ],
                    );
                    if ($episode->manifest_fingerprint !== $episodePlan['manifest_fingerprint']) {
                        throw new \RuntimeException('Stored historical episode has a different exact evidence manifest.');
                    }

                    $analysis = ContractHistoricalInterpretation::query()
                        ->where('analysis_fingerprint', $episodePlan['analysis_fingerprint'])
                        ->first();
                    $created = false;
                    if ($analysis === null) {
                        $analysis = ContractHistoricalInterpretation::create([
                            'episode_id' => $episode->id,
                            'contract_id' => $episodePlan['contract_id'],
                            'analysis_fingerprint' => $episodePlan['analysis_fingerprint'],
                            'status' => ContractHistoricalInterpretation::STATUS_PENDING,
                            'schema_version' => config('contract_interpretation.schema_version'),
                            'prompt_version' => config('contract_interpretation.prompt_version'),
                            'historical_addendum_version' => config('contract_interpretation.historical.addendum_version'),
                            'validator_version' => config('contract_interpretation.validator_version'),
                            'parser_version' => CanonicalPricingParser::VERSION,
                            'provider' => config('contract_interpretation.provider'),
                            'model' => config('contract_interpretation.model'),
                            'reasoning_effort' => config('contract_interpretation.reasoning_effort'),
                        ]);
                        $created = true;
                    }
                    if ((int) $analysis->episode_id !== (int) $episode->id
                        || $analysis->contract_id !== $episodePlan['contract_id']) {
                        throw new \RuntimeException('Stored historical analysis identity does not match its episode.');
                    }

                    $this->applyPlannedAction($analysis, $expected['action'], $created, $options);
                    if ($this->option('dispatch')
                        && isset($selected[$analysis->analysis_fingerprint])
                        && $analysis->status === ContractHistoricalInterpretation::STATUS_PENDING) {
                        $dispatchIds[] = $analysis->id;
                    }
                    $entryIndex++;
                }
                unset($episodePlan, $chunk);
            }

            if ($entryIndex !== count($plan['entries'])) {
                throw new \RuntimeException('Historical evidence changed after plan verification.');
            }

            return $dispatchIds;
        });
    }

    private function applyPlannedAction(
        ContractHistoricalInterpretation $analysis,
        string $action,
        bool $created,
        array $options,
    ): void {
        if (($action === 'create') !== $created) {
            throw new \RuntimeException('Historical analysis status changed after plan verification.');
        }
        if ($action === 'skip'
            && $this->plannedAction($analysis, $options, CarbonImmutable::now()) !== 'skip') {
            throw new \RuntimeException('Historical analysis status changed after plan verification.');
        }
        if ($action === 'pending' && $analysis->status !== ContractHistoricalInterpretation::STATUS_PENDING) {
            throw new \RuntimeException('Historical analysis status changed after plan verification.');
        }
        if (! in_array($action, ['create', 'skip', 'pending', 'retry_failed', 'resume_stale'], true)) {
            throw new \RuntimeException('Historical plan contains an unsupported action.');
        }
        if ($action === 'retry_failed') {
            if ($analysis->status !== ContractHistoricalInterpretation::STATUS_FAILED) {
                throw new \RuntimeException('Historical analysis status changed after plan verification.');
            }
            $this->reactivate($analysis);
        }
        if ($action === 'resume_stale') {
            if ($analysis->status !== ContractHistoricalInterpretation::STATUS_PROCESSING
                || $options['stale_minutes'] === null
                || ! $this->isStale($analysis, CarbonImmutable::now(), $options['stale_minutes'])) {
                throw new \RuntimeException('Historical analysis status changed after plan verification.');
            }
            $this->reactivate($analysis);
        }
    }

    private function reactivate(ContractHistoricalInterpretation $analysis): void
    {
        $analysis->update([
            'status' => ContractHistoricalInterpretation::STATUS_PENDING,
            'started_at' => null,
            'completed_at' => null,
            'error' => null,
        ]);
    }

    /** @param list<array<string, mixed>> $episodes @return list<array<string, mixed>> */
    private function selectedEpisodes(array $episodes, array $options): array
    {
        $from = $options['from']->toDateString();
        $to = $options['to']->toDateString();
        $selected = array_values(array_filter(
            $episodes,
            fn (array $episode): bool => $episode['episode_end'] >= $from
                && $episode['episode_start'] <= $to,
        ));
        usort($selected, fn (array $a, array $b): int => [
            $a['contract_id'], $a['episode_start'], $a['episode_fingerprint'],
        ] <=> [
            $b['contract_id'], $b['episode_start'], $b['episode_fingerprint'],
        ]);

        return $selected;
    }

    private function plannedAction(
        ?ContractHistoricalInterpretation $row,
        array $options,
        CarbonImmutable $now,
    ): string {
        if ($row === null) {
            return 'create';
        }
        if ($row->status === ContractHistoricalInterpretation::STATUS_PENDING) {
            return 'pending';
        }
        if ($row->status === ContractHistoricalInterpretation::STATUS_FAILED && $options['retry_failed']) {
            return 'retry_failed';
        }
        if ($row->status === ContractHistoricalInterpretation::STATUS_PROCESSING
            && $options['stale_minutes'] !== null
            && $this->isStale($row, $now, $options['stale_minutes'])) {
            return 'resume_stale';
        }

        return 'skip';
    }

    /** @param array<string, int> $left @param array<string, int> $right @return array<string, int> */
    private function mergeCounts(array $left, array $right): array
    {
        foreach ($right as $key => $count) {
            $left[$key] = ($left[$key] ?? 0) + $count;
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function validatedOptions(): array
    {
        if ($this->option('dispatch') && ! $this->option('apply')) {
            throw new \InvalidArgumentException('--dispatch requires --apply.');
        }

        $cutoff = CarbonImmutable::parse((string) config('contract_interpretation.historical.cutoff'))->startOfDay();
        $from = $this->option('from') !== null
            ? $this->exactDateOption('--from', (string) $this->option('from'))
            : CarbonImmutable::create(1900, 1, 1);
        $to = $this->option('to') !== null
            ? $this->exactDateOption('--to', (string) $this->option('to'))
            : $cutoff;
        if ($to->greaterThan($cutoff)) {
            throw new \InvalidArgumentException('The selected end date is after the configured historical cutoff '.$cutoff->toDateString().'.');
        }
        if ($from->greaterThan($to)) {
            throw new \InvalidArgumentException('--from must not be after --to.');
        }

        $contracts = array_values(array_unique(array_map('strval', (array) $this->option('contract'))));
        sort($contracts, SORT_STRING);
        $dispatchLimit = filter_var($this->option('dispatch-limit'), FILTER_VALIDATE_INT);
        if ($dispatchLimit === false || $dispatchLimit < 0) {
            throw new \InvalidArgumentException('--dispatch-limit must be a non-negative integer.');
        }

        $stale = $this->option('resume-stale-processing');
        $staleMinutes = $stale === null ? null : filter_var($stale, FILTER_VALIDATE_INT);
        $minimum = (int) config('contract_interpretation.historical.stale_processing_min_minutes', 30);
        if ($staleMinutes !== null && ($staleMinutes === false || $staleMinutes < $minimum)) {
            throw new \InvalidArgumentException("--resume-stale-processing must be at least {$minimum} minutes.");
        }

        return [
            'cutoff' => $cutoff,
            'from' => $from,
            'to' => $to,
            'contracts' => $contracts,
            'dispatch_limit' => $dispatchLimit,
            'retry_failed' => (bool) $this->option('retry-failed'),
            'stale_minutes' => $staleMinutes,
        ];
    }

    private function printPlan(array $plan): void
    {
        $discovery = $plan['discovery'];
        $actionableCount = $plan['actionable_count'];
        $this->line('Mode: DRY RUN / READ ONLY'.($this->option('apply') ? ' (plan recomputed before apply)' : ''));
        $this->line('Scanned contract-days: '.$discovery['scanned_contract_days']);
        $this->line('Eligible contract-days: '.$discovery['eligible_days']);
        $this->line('Ineligible reasons: '.json_encode($discovery['ineligible'], JSON_UNESCAPED_SLASHES));
        $this->line('Evidence grades: '.json_encode($discovery['grades'], JSON_UNESCAPED_SLASHES));
        $this->line('Normalized selected episodes: '.count($plan['entries']));
        $this->line('Peak full episode payloads retained: '.$discovery['peak_full_episode_payloads']);
        $this->line('Existing current-version work: '.json_encode($plan['status_counts'], JSON_UNESCAPED_SLASHES));
        $this->line('Actionable analyses: '.$actionableCount);
        $this->line('Minimum initial calls: '.$actionableCount);
        $this->line('Maximum normal calls including repairs: '.($actionableCount * 3));
        $this->line('Transport retries can exceed the maximum normal call count.');
        $this->line('Selected dispatch count: '.count($plan['selected_dispatch']));

        $historicalMean = $this->meanCost('contract_historical_interpretations');
        $currentMean = $this->meanCost('contract_interpretations');
        $this->line('Observed mean historical interpretation cost: '.($historicalMean === null ? 'unavailable' : '$'.number_format($historicalMean, 6)));
        $this->line('Observed mean current interpretation cost: '.($currentMean === null ? 'unavailable' : '$'.number_format($currentMean, 6)));
        $mean = $historicalMean ?? $currentMean;
        $this->line('ESTIMATED observed-mean total provider cost: '.($mean === null ? 'unavailable (no observed mean)' : '$'.number_format($mean * $actionableCount, 6)));
        $this->line('Plan hash: '.$plan['hash']);
    }

    private function exactDateOption(string $option, string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException("{$option} must be an exact valid date in YYYY-MM-DD format.");
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw new \InvalidArgumentException("{$option} must be an exact valid date in YYYY-MM-DD format.");
        }

        return $date;
    }

    private function meanCost(string $table): ?float
    {
        $total = 0.0;
        $count = 0;
        foreach (DB::table($table)
            ->select(['id', 'usage'])
            ->whereNotNull('completed_at')
            ->whereNotNull('usage')
            ->orderBy('id')
            ->cursor() as $row) {
            $usage = is_string($row->usage) ? json_decode($row->usage, true) : $row->usage;
            if (is_array($usage) && isset($usage['cost']) && is_numeric($usage['cost'])) {
                $total += (float) $usage['cost'];
                $count++;
            }
        }

        return $count === 0 ? null : $total / $count;
    }

    private function isStale(
        ContractHistoricalInterpretation $row,
        CarbonImmutable $now,
        int $minutes,
    ): bool {
        $started = $row->started_at ?? $row->updated_at;

        return $started !== null && CarbonImmutable::parse($started)->lessThanOrEqualTo($now->subMinutes($minutes));
    }
}
