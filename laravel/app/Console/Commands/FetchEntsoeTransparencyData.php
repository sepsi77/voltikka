<?php

namespace App\Console\Commands;

use App\Services\EntsoeTransparency\EntsoeTransparencyClient;
use App\Services\EntsoeTransparency\GenerationImporter;
use App\Services\EntsoeTransparency\OutageImporter;
use App\Services\EntsoeTransparency\ReservoirImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect ENTSO-E Transparency datasets for market reviews (config/entsoe_transparency.php):
 * unavailability (A77/A80 per zone, A78 per border direction), actual generation per type
 * (A75) and weekly reservoir filling (A72).
 *
 * Without --from: the daily window. Outages: documents created or updated in the last 3 days
 * whose outage period touches the last year or the next two years. Generation: the last 4
 * days. Reservoirs: the last 8 weeks. With --from/--to: a backfill in pieces (outages: one
 * year, the API limit, ending by default one year ahead; generation: 7 days; reservoirs: 364
 * days). Generation and reservoir windows end at the start of the current UTC hour.
 * Read-only towards ENTSO-E; writes locally.
 */
class FetchEntsoeTransparencyData extends Command
{
    protected $signature = 'entsoe:fetch
                            {--dataset=* : outages, generation or reservoirs (default: all)}
                            {--zone=* : Only these zone or border keys (default: all configured)}
                            {--from= : Backfill start date (YYYY-MM-DD, UTC)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD, UTC)}';

    protected $description = 'Fetch ENTSO-E outages, generation per type and reservoir filling for market reviews';

    private const DATASETS = ['outages', 'generation', 'reservoirs'];

    private const OUTAGE_BACKFILL_CHUNK_DAYS = 365;

    /** A version query by mRID may not span more than one year around the outage start. */
    private const HISTORY_HALF_WINDOW_DAYS = 182;

    public function __construct(
        private readonly EntsoeTransparencyClient $client,
        private readonly OutageImporter $outages,
        private readonly GenerationImporter $generation,
        private readonly ReservoirImporter $reservoirs
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $requested = (array) $this->option('dataset');
        $unknown = array_diff($requested, self::DATASETS);
        if ($unknown !== []) {
            $this->error('Unknown dataset(s): '.implode(', ', $unknown));

            return Command::FAILURE;
        }
        $datasets = $requested !== [] ? array_values($requested) : self::DATASETS;

        $units = $this->units($datasets, (array) $this->option('zone'));
        if ($units === null) {
            return Command::FAILURE;
        }

        try {
            $from = $this->option('from') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : null;
            $to = $this->option('to') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')->addDay()
                : null;
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return Command::FAILURE;
        }

        $failed = [];
        foreach ($units as $unit) {
            try {
                $written = match ($unit['dataset']) {
                    'outages' => $this->fetchOutages($unit, $from, $to),
                    'generation' => $this->fetchSeries($unit, $from, $to, 'generation'),
                    'reservoirs' => $this->fetchSeries($unit, $from, $to, 'reservoirs'),
                };
                $this->info("{$unit['dataset']} {$unit['zone']} {$unit['params']['documentType']}: {$written} rows.");
            } catch (Throwable $e) {
                $failed[] = $unit;
                // Exception messages can contain the request URL with the securityToken.
                logger()->error('ENTSO-E transparency fetch failed', [
                    'dataset' => $unit['dataset'],
                    'zone' => $unit['zone'],
                    'document_type' => $unit['params']['documentType'],
                    'exception_class' => $e::class,
                ]);
                $this->error("{$unit['dataset']} {$unit['zone']} {$unit['params']['documentType']}: fetch failed (".$e::class.').');
            }
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * One unit per dataset, zone (or border) and document type; a failed unit does not stop others.
     *
     * @param  list<string>  $datasets
     * @param  list<string>  $zoneFilter
     * @return list<array{dataset: string, zone: string, params: array<string, string>}>|null
     */
    private function units(array $datasets, array $zoneFilter): ?array
    {
        $units = [];
        $known = [];
        $keep = fn (string $zone) => $zoneFilter === [] || in_array($zone, $zoneFilter, true);

        foreach ($datasets as $dataset) {
            if ($dataset === 'outages') {
                $config = (array) config('entsoe_transparency.outages');
                foreach ((array) $config['zones'] as $zone => $eic) {
                    $known[] = $zone;
                    foreach ((array) $config['document_types'] as $type) {
                        if ($keep($zone)) {
                            $units[] = ['dataset' => $dataset, 'zone' => $zone, 'params' => ['documentType' => $type, 'biddingZone_Domain' => $eic]];
                        }
                    }
                }
                foreach ((array) $config['borders'] as $border => $ends) {
                    $known[] = $border;
                    if ($keep($border)) {
                        $units[] = ['dataset' => $dataset, 'zone' => $border, 'params' => [
                            'documentType' => 'A78',
                            'in_Domain' => $config['border_zones'][$ends['in']],
                            'out_Domain' => $config['border_zones'][$ends['out']],
                        ]];
                    }
                }

                continue;
            }

            $documentType = $dataset === 'generation' ? 'A75' : 'A72';
            foreach ((array) config("entsoe_transparency.{$dataset}.zones") as $zone => $eic) {
                $known[] = $zone;
                if ($keep($zone)) {
                    $units[] = ['dataset' => $dataset, 'zone' => $zone, 'params' => ['documentType' => $documentType, 'processType' => 'A16', 'in_Domain' => $eic]];
                }
            }
        }

        $unknown = array_diff($zoneFilter, $known);
        if ($unknown !== []) {
            $this->error('Unknown zone(s) for the selected datasets: '.implode(', ', $unknown));

            return null;
        }

        return $units;
    }

    /**
     * @param  array{dataset: string, zone: string, params: array<string, string>}  $unit
     */
    private function fetchOutages(array $unit, ?Carbon $from, ?Carbon $to): int
    {
        $config = (array) config('entsoe_transparency.outages');
        $now = Carbon::now('UTC')->startOfMinute();

        if ($from === null) {
            $queries = [[
                'periodStart' => $this->format($now->copy()->subDays((int) $config['past_days'])),
                'periodEnd' => $this->format($now->copy()->addDays((int) $config['future_days'])),
                'periodStartUpdate' => $this->format($now->copy()->subDays((int) $config['update_lookback_days'])),
                'periodEndUpdate' => $this->format($now),
            ]];
        } else {
            $end = $to ?? $now->copy()->startOfDay()->addDays((int) $config['backfill_future_days']);
            $queries = array_map(fn (array $chunk) => [
                'periodStart' => $this->format($chunk[0]),
                'periodEnd' => $this->format($chunk[1]),
            ], $this->chunks($from, $end, self::OUTAGE_BACKFILL_CHUNK_DAYS));
        }

        $written = 0;
        $history = [];
        foreach ($queries as $query) {
            $documents = $this->client->fetchAllOutageDocuments($unit['params'] + $query);
            $result = $this->outages->import($unit['params']['documentType'], $unit['zone'], $documents, Carbon::now('UTC'));
            $written += $result['inserted'];
            foreach ($result['needs_history'] as $document) {
                $history[$document['mrid']] = $document;
            }
        }

        // The query above returns only the latest version of each document. Earlier versions
        // (what was known before) need one query per mRID.
        foreach ($history as $document) {
            $documents = $this->client->fetchAllOutageDocuments($unit['params'] + [
                'mRID' => $document['mrid'],
                'periodStart' => $this->format($document['start']->subDays(self::HISTORY_HALF_WINDOW_DAYS)),
                'periodEnd' => $this->format($document['start']->addDays(self::HISTORY_HALF_WINDOW_DAYS)),
            ]);
            $written += $this->outages->import($unit['params']['documentType'], $unit['zone'], $documents, Carbon::now('UTC'))['inserted'];
        }

        return $written;
    }

    /**
     * @param  array{dataset: string, zone: string, params: array<string, string>}  $unit
     */
    private function fetchSeries(array $unit, ?Carbon $from, ?Carbon $to, string $dataset): int
    {
        $currentHour = Carbon::now('UTC')->startOfHour();
        $end = $to !== null && $to->lessThan($currentHour) ? $to : $currentHour->copy();

        $windows = $from === null
            ? [[$currentHour->copy()->subDays((int) config("entsoe_transparency.{$dataset}.lookback_days")), $currentHour->copy()]]
            : $this->chunks($from, $end, (int) config("entsoe_transparency.{$dataset}.backfill_chunk_days"));

        $written = 0;
        foreach ($windows as [$start, $windowEnd]) {
            $documents = $this->client->fetchDocuments($unit['params'] + [
                'periodStart' => $this->format($start),
                'periodEnd' => $this->format($windowEnd),
            ]);
            $importer = $dataset === 'generation' ? $this->generation : $this->reservoirs;
            $written += $importer->import($unit['zone'], $documents, Carbon::now('UTC'));
        }

        return $written;
    }

    private function format(\DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('YmdHi');
    }

    /**
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function chunks(Carbon $start, Carbon $end, int $days): array
    {
        $chunks = [];
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            $chunkEnd = $cursor->copy()->addDays($days);
            if ($chunkEnd->greaterThan($end)) {
                $chunkEnd = $end->copy();
            }
            $chunks[] = [$cursor->copy(), $chunkEnd];
            $cursor = $chunkEnd;
        }

        return $chunks;
    }
}
