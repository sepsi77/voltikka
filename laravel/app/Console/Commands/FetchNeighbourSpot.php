<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\EntsoeService;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\SpotPriceImport\EnergyChartsPriceService;
use App\Services\SpotPriceImport\SpotPriceImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Import day-ahead prices of the neighbour bidding zones (SE1, SE3, EE, DE-LU) for market reviews.
 *
 * Deliberately separate from spot:fetch: these rows feed no public price, average, cache or
 * social output, so a neighbour failure must not affect the Finnish import or its Issue.
 *
 * Staged (StagedImport): the fetch step records one request per region; a failed region is a
 * failed unit, the other regions are still stored, and the run exits 1 (fetch_incomplete), as
 * before. Not date-bound (S9): rows are insert-only, so a same-day replay inserts nothing new.
 *
 * DE-LU fill: ENTSO-E sometimes publishes DE-LU only as sequence 2, which parseDayAheadPrices()
 * drops. One more request fetches the SMARD price via Energy-Charts for the same window, and the
 * store takes from it only the DE-LU quarter-hours that ENTSO-E sequence 1 in this payload does
 * not cover. A failed or malformed Energy-Charts answer is a failed unit like a failed region.
 */
class FetchNeighbourSpot extends Command
{
    use StagedImport;

    protected $signature = 'spot:fetch-neighbours';

    protected $description = 'Fetch neighbour bidding-zone day-ahead prices from ENTSO-E (DE-LU gaps from SMARD via Energy-Charts) for market reviews';

    private const ENERGY_CHARTS_UNIT = ['region' => EnergyChartsPriceService::REGION, 'source' => 'energy-charts'];

    public function __construct(
        private readonly EntsoeService $entsoeService,
        private readonly SpotPriceImporter $spotPriceImporter,
        private readonly EnergyChartsPriceService $energyCharts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetch($payload),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
        );
    }

    protected function stagedImportSource(): string
    {
        return 'entsoe-day-ahead';
    }

    private function fetch(ImportPayloadWriter $payload): void
    {
        // Helsinki yesterday through the day after tomorrow, fixed from the run's fetch time (S5).
        // Yesterday is included so a late publication for it is still picked up; rows are
        // insert-only, so rows already stored stay.
        $today = Carbon::instance($payload->fetchedAt())->setTimezone('Europe/Helsinki')->startOfDay();
        $start = $today->copy()->subDay()->utc();
        $end = $today->copy()->addDays(2)->utc();
        $payload->setFact('period_start', $start->format('Y-m-d\TH:i:s\Z'));
        $payload->setFact('period_end', $end->format('Y-m-d\TH:i:s\Z'));

        foreach ($this->regions() as $region) {
            try {
                $payload->record(['region' => $region], $this->entsoeService->fetchDayAheadResponse($start->copy(), $end->copy(), $region));
            } catch (RequestException|ConnectionException $e) {
                [$url, $query] = $this->entsoeService->dayAheadRequest($start->copy(), $end->copy(), $region);
                $payload->recordFailure(['region' => $region], 'GET', $url, $query, $e);
                // Never log the exception message: it can contain the ENTSO-E securityToken.
                Log::error('Neighbour spot import failed', [
                    'region' => $region,
                    'exception_class' => $e::class,
                ]);
                $this->error("{$region}: fetch failed.");
            }
        }

        if (in_array(EnergyChartsPriceService::REGION, $this->regions(), true)) {
            $this->fetchEnergyCharts($payload, $start, $end);
        }
    }

    /** One Energy-Charts request for the DE-LU fill; a failed or malformed answer is a failed unit. */
    private function fetchEnergyCharts(ImportPayloadWriter $payload, Carbon $start, Carbon $end): void
    {
        try {
            $response = $this->energyCharts->fetchResponse($start->copy(), $end->copy());
        } catch (RequestException|ConnectionException $e) {
            [$url, $query] = $this->energyCharts->request($start, $end);
            $payload->recordFailure(self::ENERGY_CHARTS_UNIT, 'GET', $url, $query, $e);
            $this->reportEnergyChartsFailure($e);

            return;
        }

        try {
            $this->energyCharts->parsePrices($response->body(), $start, $end);
        } catch (UnexpectedValueException $e) {
            $payload->record(self::ENERGY_CHARTS_UNIT, $response, failed: true);
            $this->reportEnergyChartsFailure($e);

            return;
        }

        $payload->record(self::ENERGY_CHARTS_UNIT, $response);
    }

    private function reportEnergyChartsFailure(Throwable $e): void
    {
        Log::error('Neighbour spot import failed', [
            'region' => EnergyChartsPriceService::REGION,
            'source' => 'energy-charts',
            'exception_class' => $e::class,
        ]);
        $this->error(EnergyChartsPriceService::REGION.': Energy-Charts fetch failed.');
    }

    /**
     * Runs inside the StagedImport store transaction: every answered unit, or none. All records go
     * to one import() call, so an hour that mixes ENTSO-E and filled quarters gets the mean of all
     * four.
     */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $regions = $this->regions();
        $failedUnits = $payload->end()['failed_units'];
        $prices = [];
        $fillBodies = [];
        $stored = 0;
        foreach ($payload->requests() as $request) {
            $unit = $request['unit'];
            $region = $unit['region'] ?? null;
            $fill = array_key_exists('source', $unit);
            if (! in_array($region, $regions, true) || ($fill && $unit != self::ENERGY_CHARTS_UNIT)) {
                throw new UnexpectedValueException('The neighbour spot payload holds an unknown unit.');
            }
            $status = $request['status'] ?? null;
            if (in_array($unit, $failedUnits) || ! is_int($status) || $status < 200 || $status >= 300) {
                continue; // a failed unit (S10), listed in the end line
            }
            if ($fill) {
                $fillBodies[] = (string) ImportPayloadReader::body($request);

                continue;
            }

            $regionPrices = $this->entsoeService->parseDayAheadPrices((string) ImportPayloadReader::body($request), $region);
            $this->info("{$region}: ".count($regionPrices).' records.');
            array_push($prices, ...$regionPrices);
            $stored++;
        }

        $filled = $this->fillFromEnergyCharts($prices, $fillBodies, $payload);
        if ($fillBodies !== []) {
            $this->info(EnergyChartsPriceService::REGION.": {$filled} quarter-hours filled from Energy-Charts (SMARD).");
        }
        $this->spotPriceImporter->import($prices);

        $failedFill = count(array_filter($failedUnits, fn (array $unit): bool => array_key_exists('source', $unit)));

        return new ImportStoreResult(
            [
                'records' => count($prices),
                'regions' => $stored,
                'failed_regions' => count($failedUnits) - $failedFill,
                'de_lu_filled' => $filled,
                'failed_fill' => $failedFill,
            ],
            error: $failedUnits === [] ? null : 'fetch_incomplete',
            errorStep: 'fetch',
        );
    }

    /**
     * Append the Energy-Charts DE-LU quarter-hours that the ENTSO-E records of this payload do not
     * cover (ENTSO-E stays primary) and return how many were added.
     *
     * @param  list<array<string, mixed>>  $prices  parsed ENTSO-E records of every region
     * @param  list<string>  $fillBodies
     */
    private function fillFromEnergyCharts(array &$prices, array $fillBodies, ImportPayloadReader $payload): int
    {
        if ($fillBodies === []) {
            return 0;
        }

        // Every quarter-hour an ENTSO-E DE-LU record covers (an hourly record covers four).
        $covered = [];
        foreach ($prices as $price) {
            if ($price['region'] !== EnergyChartsPriceService::REGION) {
                continue;
            }
            $slotEnd = $price['timestamp'] + 60 * ($price['resolution_minutes'] ?? 60);
            for ($slot = $price['timestamp']; $slot < $slotEnd; $slot += 900) {
                $covered[$slot] = true;
            }
        }

        $start = Carbon::parse((string) $payload->fact('period_start'), 'UTC');
        $end = Carbon::parse((string) $payload->fact('period_end'), 'UTC');
        $filled = 0;
        foreach ($fillBodies as $body) {
            foreach ($this->energyCharts->parsePrices($body, $start, $end) as $record) {
                if (! isset($covered[$record['timestamp']])) {
                    $covered[$record['timestamp']] = true;
                    $prices[] = $record;
                    $filled++;
                }
            }
        }

        return $filled;
    }

    /** @return list<string> */
    private function regions(): array
    {
        return array_map('strval', array_keys((array) config('services.entsoe.bidding_zones', [])));
    }
}
