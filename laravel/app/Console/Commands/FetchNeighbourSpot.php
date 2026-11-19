<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\EntsoeService;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\SpotPriceImport\SpotPriceImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
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
 */
class FetchNeighbourSpot extends Command
{
    use StagedImport;

    protected $signature = 'spot:fetch-neighbours';

    protected $description = 'Fetch neighbour bidding-zone day-ahead prices from ENTSO-E for market reviews';

    public function __construct(
        private readonly EntsoeService $entsoeService,
        private readonly SpotPriceImporter $spotPriceImporter
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
        // Helsinki today through the day after tomorrow, fixed from the run's fetch time (S5).
        $start = Carbon::instance($payload->fetchedAt())->setTimezone('Europe/Helsinki')->startOfDay()->utc();
        $end = $start->copy()->setTimezone('Europe/Helsinki')->addDays(2)->startOfDay()->utc();
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
    }

    /** Runs inside the StagedImport store transaction: every answered region, or none. */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $regions = $this->regions();
        $records = 0;
        $stored = 0;
        foreach ($payload->requests() as $request) {
            $region = $request['unit']['region'] ?? null;
            if (! in_array($region, $regions, true)) {
                throw new UnexpectedValueException('The neighbour spot payload holds an unknown region.');
            }
            $status = $request['status'] ?? null;
            if (! is_int($status) || $status < 200 || $status >= 300) {
                continue; // a failed unit (S10), listed in the end line
            }

            $prices = $this->entsoeService->parseDayAheadPrices((string) ImportPayloadReader::body($request), $region);
            $this->spotPriceImporter->import($prices);
            $this->info("{$region}: ".count($prices).' records.');
            $records += count($prices);
            $stored++;
        }

        $failed = count($payload->end()['failed_units']);

        return new ImportStoreResult(
            ['records' => $records, 'regions' => $stored, 'failed_regions' => $failed],
            error: $failed === 0 ? null : 'fetch_incomplete',
            errorStep: 'fetch',
        );
    }

    /** @return list<string> */
    private function regions(): array
    {
        return array_map('strval', array_keys((array) config('services.entsoe.bidding_zones', [])));
    }
}
