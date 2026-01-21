<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class EntsoeService
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 1000;

    /**
     * Fetch day-ahead prices from ENTSO-E Transparency Platform API.
     *
     * @param Carbon $startDate Start of the period (UTC)
     * @param Carbon $endDate End of the period (UTC)
     * @return array Array of price data with keys:
     *               - region: string ('FI')
     *               - timestamp: int (Unix timestamp)
     *               - utc_datetime: Carbon
     *               - price_without_tax: float (c/kWh)
     * @throws RequestException
     */
    public function fetchDayAheadPrices(Carbon $startDate, Carbon $endDate): array
    {
        $baseUrl = config('services.entsoe.base_url', 'https://web-api.tp.entsoe.eu/api');
        $apiKey = config('services.entsoe.api_key');
        $finlandEic = config('services.entsoe.finland_eic', '10YFI-1--------U');

        $params = [
            'securityToken' => $apiKey,
            'documentType' => 'A44', // Day-ahead prices
            'in_Domain' => $finlandEic,
            'out_Domain' => $finlandEic,
            'periodStart' => $startDate->utc()->format('YmdHi'),
            'periodEnd' => $endDate->utc()->format('YmdHi'),
        ];

        $url = $baseUrl . '?' . http_build_query($params);

        $response = Http::retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception, $request) {
            return $exception instanceof RequestException
                && ($exception->response?->serverError() || $exception->response === null);
        })->get($url);

        if ($response->failed()) {
            Log::error('Failed to fetch spot prices from ENTSO-E API', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RequestException($response);
        }

        return $this->parseXmlResponse($response->body());
    }

    /**
     * Parse ENTSO-E XML response and extract price data.
     *
     * @param string $xmlContent Raw XML response
     * @return array Parsed price data
     */
    private function parseXmlResponse(string $xmlContent): array
    {
        // Check for empty or acknowledgement response (no data)
        if ($this->isNoDataResponse($xmlContent)) {
            return [];
        }

        try {
            $xml = new SimpleXMLElement($xmlContent);
        } catch (\Exception $e) {
            Log::error('Failed to parse ENTSO-E XML response', [
                'error' => $e->getMessage(),
                'content' => substr($xmlContent, 0, 500),
            ]);
            return [];
        }

        // Register namespace for XPath queries
        $namespaces = $xml->getNamespaces(true);
        $ns = reset($namespaces) ?: '';

        if ($ns) {
            $xml->registerXPathNamespace('ns', $ns);
        }

        $prices = [];

        // Find all TimeSeries elements
        $timeSeries = $ns ? $xml->xpath('//ns:TimeSeries') : $xml->xpath('//TimeSeries');

        foreach ($timeSeries as $series) {
            if ($ns) {
                $series->registerXPathNamespace('ns', $ns);
            }

            // Get Period elements
            $periods = $ns ? $series->xpath('ns:Period') : $series->xpath('Period');

            foreach ($periods as $period) {
                if ($ns) {
                    $period->registerXPathNamespace('ns', $ns);
                }

                $periodPrices = $this->parsePeriod($period, $ns);
                $prices = array_merge($prices, $periodPrices);
            }
        }

        // Sort by timestamp
        usort($prices, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        return $prices;
    }

    /**
     * Parse a Period element and extract price points.
     * Returns raw 15-minute data when available (does not aggregate).
     *
     * @param SimpleXMLElement $period Period XML element
     * @param string $ns Namespace URI
     * @return array Price data from this period with 'resolution_minutes' key
     */
    private function parsePeriod(SimpleXMLElement $period, string $ns): array
    {
        $prices = [];

        // Get time interval start
        $startElement = $ns
            ? $period->xpath('ns:timeInterval/ns:start')
            : $period->xpath('timeInterval/start');
        $start = (string)($startElement[0] ?? '');

        // Get resolution
        $resolutionElement = $ns
            ? $period->xpath('ns:resolution')
            : $period->xpath('resolution');
        $resolution = (string)($resolutionElement[0] ?? 'PT60M');

        if (empty($start)) {
            return [];
        }

        $periodStart = Carbon::parse($start, 'UTC');
        $resolutionMinutes = $this->parseResolution($resolution);

        // Get all Point elements
        $points = $ns ? $period->xpath('ns:Point') : $period->xpath('Point');

        // Collect all price values with their positions
        foreach ($points as $point) {
            if ($ns) {
                $point->registerXPathNamespace('ns', $ns);
            }

            $positionElement = $ns
                ? $point->xpath('ns:position')
                : $point->xpath('position');
            $priceElement = $ns
                ? $point->xpath('ns:price.amount')
                : $point->xpath('price.amount');

            $position = (int)($positionElement[0] ?? 0);
            $priceEurMwh = (float)($priceElement[0] ?? 0);

            $pointTime = $periodStart->copy()->addMinutes(($position - 1) * $resolutionMinutes);
            $priceCentsKwh = $priceEurMwh / 10;

            $prices[] = [
                'region' => 'FI',
                'timestamp' => $pointTime->timestamp,
                'utc_datetime' => $pointTime,
                'price_without_tax' => $priceCentsKwh,
                'resolution_minutes' => $resolutionMinutes,
            ];
        }

        return $prices;
    }

    /**
     * Parse ISO 8601 duration to minutes.
     *
     * @param string $resolution Resolution string (e.g., 'PT60M', 'PT15M')
     * @return int Resolution in minutes
     */
    private function parseResolution(string $resolution): int
    {
        if (preg_match('/PT(\d+)M/', $resolution, $matches)) {
            return (int)$matches[1];
        }

        if (preg_match('/PT(\d+)H/', $resolution, $matches)) {
            return (int)$matches[1] * 60;
        }

        // Default to 60 minutes
        return 60;
    }

    /**
     * Check if the XML response indicates no data available.
     *
     * @param string $xmlContent XML content
     * @return bool True if response indicates no data
     */
    private function isNoDataResponse(string $xmlContent): bool
    {
        // Check for acknowledgement document (error/no data response)
        if (str_contains($xmlContent, 'Acknowledgement_MarketDocument')) {
            return true;
        }

        // Check for empty Publication_MarketDocument
        if (str_contains($xmlContent, 'Publication_MarketDocument')
            && !str_contains($xmlContent, '<TimeSeries>')) {
            return true;
        }

        return false;
    }
}
