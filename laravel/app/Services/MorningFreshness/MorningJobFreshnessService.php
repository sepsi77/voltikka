<?php

namespace App\Services\MorningFreshness;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractSourceSnapshot;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

class MorningJobFreshnessService
{
    /** @var list<string> */
    private const FORECAST_SEGMENTS = [
        'fixed_term_6',
        'fixed_term_12',
        'fixed_term_24',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string, mixed>|null $metadata */
    public function record(
        string $key,
        CarbonInterface|string $effectiveDate,
        string $status,
        ?array $metadata = null,
    ): DataFreshnessCheckpoint {
        $date = $effectiveDate instanceof CarbonInterface
            ? $effectiveDate->toDateString()
            : CarbonImmutable::parse($effectiveDate, 'Europe/Helsinki')->toDateString();

        $checkpoint = DataFreshnessCheckpoint::query()
            ->where('key', $key)
            ->whereDate('effective_date', $date)
            ->first() ?? new DataFreshnessCheckpoint([
                'key' => $key,
                'effective_date' => $date,
            ]);
        $checkpoint->fill([
            'status' => $status,
            'metadata' => $metadata,
            'recorded_at' => CarbonImmutable::now('Europe/Helsinki'),
        ])->save();

        return $checkpoint;
    }

    public function checkRetailPremium(CarbonInterface $asOf): MorningFreshnessResult
    {
        return $this->check($asOf, false);
    }

    public function checkFixedTermForecast(CarbonInterface $asOf): MorningFreshnessResult
    {
        return $this->check($asOf, true);
    }

    public function reportDeferred(string $job, CarbonInterface $asOf, MorningFreshnessResult $result): void
    {
        $this->logger->warning('Morning job deferred', [
            'job' => $job,
            'as_of' => $asOf->toDateString(),
            'failures' => $result->failures,
        ]);
    }

    private function check(CarbonInterface $asOf, bool $forecast): MorningFreshnessResult
    {
        $date = $asOf->toDateString();
        $failures = [];
        $statisticsStartedAt = null;
        $latestRequiredPublication = null;
        $contractCheckpoint = DataFreshnessCheckpoint::query()
            ->where('key', DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT)
            ->whereDate('effective_date', $date)
            ->first();

        if ($contractCheckpoint === null) {
            $failures['contract_checkpoint'] = 'The current contract import checkpoint is missing.';
        } elseif ($contractCheckpoint->status !== DataFreshnessCheckpoint::STATUS_READY) {
            $failures['contract_checkpoint'] = "The current contract import is {$contractCheckpoint->status}.";
        } else {
            $facts = $this->contractFacts($contractCheckpoint->metadata);

            if ($facts === null) {
                $failures['contract_metadata'] = 'The current contract import facts are incomplete.';
            } else {
                $statisticsStartedAt = $facts['statistics_started_at'];

                if ((bool) $this->config->get('contract_interpretation.enabled', false)) {
                    $publication = $this->checkInterpretations(
                        $facts['observed_snapshot_ids'],
                        $facts['active_contract_ids'],
                    );

                    if ($publication['failure'] !== null) {
                        $failures['contract_interpretations'] = $publication['failure'];
                    }

                    $latestRequiredPublication = $publication['latest_published_at'];
                }
            }
        }

        if ($forecast) {
            $basis = (bool) $this->config->get('canonical_pricing.enabled', false)
                ? 'canonical_calculation'
                : 'observed_seller_data';
            $hasFixedTermStatistic = ContractPriceDailyStatistic::query()
                ->whereDate('stat_date', $date)
                ->where('metric_key', 'energy_price')
                ->where('pricing_basis', $basis)
                ->whereNull('consumption_kwh')
                ->whereIn('segment_key', self::FORECAST_SEGMENTS)
                ->exists();

            if (! $hasFixedTermStatistic) {
                $failures['forecast_statistics'] = 'No current fixed-term 6/12/24 energy-price statistic is available in the expected pricing basis.';
            }

            if ($latestRequiredPublication !== null
                && $statisticsStartedAt !== null
                && $latestRequiredPublication->gt($statisticsStartedAt)) {
                $failures['statistics_publication_order'] = 'Contract statistics started before the current interpretation was published.';
            }
        }

        $eexCheckpoint = DataFreshnessCheckpoint::query()
            ->where('key', DataFreshnessCheckpoint::KEY_EEX_FUTURES)
            ->whereDate('effective_date', $date)
            ->first();

        if ($eexCheckpoint === null) {
            $failures['eex_checkpoint'] = 'The current EEX futures checkpoint is missing.';
        } elseif ($eexCheckpoint->status !== DataFreshnessCheckpoint::STATUS_READY) {
            $failures['eex_checkpoint'] = "The current EEX futures fetch is {$eexCheckpoint->status}.";
        } elseif (! $this->hasCurrentRunPriorFiPoint($eexCheckpoint->metadata, $date)) {
            $failures['eex_metadata'] = 'The current EEX fetch has no prior-date FI Base point from this run.';
        }

        $latestTradeDate = ElectricityFuturesEodPrice::query()
            ->where('exchange', 'EEX')
            ->where('area', 'FI')
            ->where('product', 'Base')
            ->whereDate('trade_date', '<', $date)
            ->max('trade_date');

        if ($latestTradeDate === null) {
            $failures['futures_data'] = 'No prior-date FI EEX Base futures data is available.';
        } else {
            $tradeDate = CarbonImmutable::parse($latestTradeDate, 'Europe/Helsinki')->startOfDay();
            $age = $tradeDate->diffInDays(CarbonImmutable::parse($date, 'Europe/Helsinki'), false);
            $maxAge = max(0, (int) $this->config->get('morning_freshness.max_futures_age_days', 7));

            if ($age > $maxAge) {
                $failures['futures_data'] = "The latest FI EEX Base futures data is {$age} days old.";
            }
        }

        return new MorningFreshnessResult($failures);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{observed_snapshot_ids:list<int>, active_contract_ids:list<string>, statistics_started_at:CarbonImmutable, statistics_completed_at:CarbonImmutable}|null
     */
    private function contractFacts(?array $metadata): ?array
    {
        if (! is_array($metadata)
            || ! isset(
                $metadata['observed_snapshot_ids'],
                $metadata['active_contract_ids'],
                $metadata['statistics_started_at'],
                $metadata['statistics_completed_at'],
            )
            || ! is_array($metadata['observed_snapshot_ids'])
            || ! is_array($metadata['active_contract_ids'])
            || $metadata['observed_snapshot_ids'] === []
            || array_filter($metadata['observed_snapshot_ids'], fn (mixed $id) => ! is_int($id)) !== []
            || array_filter($metadata['active_contract_ids'], fn (mixed $id) => ! is_string($id) || $id === '') !== []
            || ! is_string($metadata['statistics_started_at'])
            || $metadata['statistics_started_at'] === ''
            || ! is_string($metadata['statistics_completed_at'])
            || $metadata['statistics_completed_at'] === '') {
            return null;
        }

        try {
            $startedAt = CarbonImmutable::parse($metadata['statistics_started_at']);
            $completedAt = CarbonImmutable::parse($metadata['statistics_completed_at']);
        } catch (Throwable) {
            return null;
        }

        if ($completedAt->lt($startedAt)) {
            return null;
        }

        return [
            'observed_snapshot_ids' => array_values($metadata['observed_snapshot_ids']),
            'active_contract_ids' => array_values($metadata['active_contract_ids']),
            'statistics_started_at' => $startedAt,
            'statistics_completed_at' => $completedAt,
        ];
    }

    /** @param array<string, mixed>|null $metadata */
    private function hasCurrentRunPriorFiPoint(?array $metadata, string $effectiveDate): bool
    {
        $tradeDate = $metadata['current_run_latest_prior_fi_trade_date'] ?? null;

        if (! is_string($tradeDate) || $tradeDate === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($tradeDate, 'Europe/Helsinki')
                ->startOfDay()
                ->lt(CarbonImmutable::parse($effectiveDate, 'Europe/Helsinki')->startOfDay());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<int>  $snapshotIds
     * @param  list<string>  $activeContractIds
     * @return array{failure:string|null, latest_published_at:CarbonImmutable|null}
     */
    private function checkInterpretations(array $snapshotIds, array $activeContractIds): array
    {
        $snapshots = ContractSourceSnapshot::query()
            ->whereIn('id', $snapshotIds)
            ->get(['id', 'contract_id']);

        if ($snapshots->count() !== count($snapshotIds)) {
            return [
                'failure' => 'The current contract import facts reference unknown snapshots.',
                'latest_published_at' => null,
            ];
        }

        $activeIds = array_fill_keys($activeContractIds, true);
        $activeSnapshotCounts = $snapshots
            ->filter(fn (ContractSourceSnapshot $snapshot) => isset($activeIds[$snapshot->contract_id]))
            ->countBy('contract_id');
        $coverageFailureIds = collect(array_keys($activeIds))
            ->filter(fn (string $contractId) => $activeSnapshotCounts->get($contractId, 0) !== 1)
            ->sort(SORT_STRING)
            ->values()
            ->all();

        if ($coverageFailureIds !== []) {
            return [
                'failure' => 'Active contracts do not have exactly one observed snapshot: '.implode(', ', $coverageFailureIds).'.',
                'latest_published_at' => null,
            ];
        }

        $requiredSnapshots = $snapshots
            ->filter(fn (ContractSourceSnapshot $snapshot) => isset($activeIds[$snapshot->contract_id]))
            ->keyBy('contract_id');

        if ($requiredSnapshots->isEmpty()) {
            return ['failure' => null, 'latest_published_at' => null];
        }

        $contracts = ElectricityContract::query()
            ->whereIn('id', $requiredSnapshots->keys())
            ->with('publishedInterpretation:id,contract_id,source_snapshot_id,status,published_at')
            ->get()
            ->keyBy('id');
        $delayedIds = [];
        $latestPublishedAt = null;

        foreach ($requiredSnapshots as $contractId => $snapshot) {
            $interpretation = $contracts->get($contractId)?->publishedInterpretation;

            if ($interpretation === null
                || $interpretation->status !== 'published'
                || $interpretation->source_snapshot_id !== $snapshot->id
                || $interpretation->published_at === null) {
                $delayedIds[] = $contractId;

                continue;
            }

            $publishedAt = CarbonImmutable::instance($interpretation->published_at);
            if ($latestPublishedAt === null || $publishedAt->gt($latestPublishedAt)) {
                $latestPublishedAt = $publishedAt;
            }
        }

        sort($delayedIds, SORT_STRING);

        return [
            'failure' => $delayedIds === []
                ? null
                : 'Current interpretations are not published for active contracts: '.implode(', ', $delayedIds).'.',
            'latest_published_at' => $latestPublishedAt,
        ];
    }
}
