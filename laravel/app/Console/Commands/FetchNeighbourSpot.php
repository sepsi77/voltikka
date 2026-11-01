<?php

namespace App\Console\Commands;

use App\Services\EntsoeService;
use App\Services\SpotPriceImport\SpotPriceImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Import day-ahead prices of the neighbour bidding zones (SE1, SE3, EE, DE-LU) for market reviews.
 *
 * Deliberately separate from spot:fetch: these rows feed no public price, average, cache or
 * social output, so a neighbour failure must not affect the Finnish import or its Issue.
 */
class FetchNeighbourSpot extends Command
{
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
        $startDate = Carbon::today('Europe/Helsinki')->setTimezone('UTC');
        $endDate = Carbon::tomorrow('Europe/Helsinki')->addDay()->setTimezone('UTC');
        $failed = [];

        foreach (array_keys((array) config('services.entsoe.bidding_zones', [])) as $region) {
            try {
                $prices = $this->entsoeService->fetchDayAheadPrices($startDate, $endDate, $region);
                $this->spotPriceImporter->import($prices);
                $this->info("{$region}: ".count($prices).' records.');
            } catch (RequestException|ConnectionException $e) {
                $failed[] = $region;
                // Never log the exception message: it can contain the ENTSO-E securityToken.
                Log::error('Neighbour spot import failed', [
                    'region' => $region,
                    'exception_class' => $e::class,
                ]);
                $this->error("{$region}: fetch failed.");
            }
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
