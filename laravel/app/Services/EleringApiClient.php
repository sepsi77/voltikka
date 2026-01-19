<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EleringApiClient
{
    private const BASE_URL = 'https://dashboard.elering.ee/api/nps/price';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 5000;
    private const REGION_FI = 'fi';

    /**
     * Fetch spot prices from Elering API.
     *
     * Returns processed data ready for database insertion.
     *
     * @return array Array of spot price data with keys:
     *               - region: string (e.g., 'FI')
     *               - timestamp: int (Unix timestamp)
     *               - utc_datetime: Carbon
     *               - price_without_tax: float (c/kWh)
     *               - vat_rate: float (0.24 or 0.10)
     * @throws RequestException
     */
    public function fetchSpotPrices(): array
    {
        $startDate = Carbon::now('UTC')->subDay();
        $endDate = Carbon::now('UTC')->addDay();

        $response = Http::retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception, $request) {
            return $exception instanceof RequestException
                && ($exception->response?->serverError() || $exception->response === null);
        })->get(self::BASE_URL, [
            'start' => $startDate->toIso8601String(),
            'end' => $endDate->toIso8601String(),
        ]);

        if ($response->failed()) {
            Log::error('Failed to fetch spot prices from Elering API', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RequestException($response);
        }

        $data = $response->json('data') ?? [];

        return $this->processApiResponse($data);
    }

    /**
     * Process API response and convert to database-ready format.
     *
     * @param array $data Raw API response data
     * @return array Processed spot price data
     */
    private function processApiResponse(array $data): array
    {
        $hourlyData = [];

        // Only process FI (Finland) region
        $fiData = $data[self::REGION_FI] ?? [];

        foreach ($fiData as $item) {
            $timestamp = $item['timestamp'];
            $priceEurMwh = $item['price']; // Price in EUR/MWh

            // Convert EUR/MWh to c/kWh: multiply by 100 to get to cents, divide by 1000 to get to kWh
            // Simplified: divide by 10
            $priceCentsPerKwh = $priceEurMwh / 10;

            $utcDatetime = Carbon::createFromTimestamp($timestamp, 'UTC');
            $vatRate = $this->getVatRate($utcDatetime);

            $hourlyData[] = [
                'region' => strtoupper(self::REGION_FI),
                'timestamp' => $timestamp,
                'utc_datetime' => $utcDatetime,
                'price_without_tax' => $priceCentsPerKwh,
                'vat_rate' => $vatRate,
            ];
        }

        return $hourlyData;
    }

    /**
     * Get the VAT rate for a given date.
     *
     * Finland had a temporary reduced VAT rate (10%) from Dec 1, 2022 to Apr 30, 2023.
     * Standard rate is 24%.
     *
     * @see https://www.vero.fi/tietoa-verohallinnosta/uutishuone/uutiset/uutiset/2022/sahkon-arvonlisaveroa-alennetaan-valiaikaisesti/
     *
     * @param Carbon $priceDate The date to get VAT rate for
     * @return float VAT rate (0.24 or 0.10)
     */
    private function getVatRate(Carbon $priceDate): float
    {
        // Convert to Helsinki timezone for VAT determination
        $helsinkiDate = $priceDate->copy()->setTimezone('Europe/Helsinki');

        $reducedVatStart = Carbon::create(2022, 12, 1, 0, 0, 0, 'Europe/Helsinki');
        $reducedVatEnd = Carbon::create(2023, 5, 1, 0, 0, 0, 'Europe/Helsinki');

        if ($helsinkiDate >= $reducedVatStart && $helsinkiDate < $reducedVatEnd) {
            return 0.10; // Temporary reduced VAT rate
        }

        return 0.24; // Standard VAT rate
    }
}
