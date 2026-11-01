<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
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
     * @param string $region Bidding-zone code from `services.entsoe.bidding_zones` (default 'FI')
     * @return array Array of price data with keys:
     *               - region: string (the requested region code)
     *               - timestamp: int (Unix timestamp)
     *               - utc_datetime: Carbon
     *               - price_without_tax: float (c/kWh)
     * @throws RequestException|ConnectionException
     * @throws InvalidArgumentException for a region without a configured EIC code
     */
    public function fetchDayAheadPrices(Carbon $startDate, Carbon $endDate, string $region = 'FI'): array
    {
        $baseUrl = config('services.entsoe.base_url', 'https://web-api.tp.entsoe.eu/api');
        $apiKey = config('services.entsoe.api_key');
        $eic = $this->eicFor($region);

        $params = [
            'securityToken' => $apiKey,
            'documentType' => 'A44', // Day-ahead prices
            'in_Domain' => $eic,
            'out_Domain' => $eic,
            'periodStart' => $startDate->utc()->format('YmdHi'),
            'periodEnd' => $endDate->utc()->format('YmdHi'),
        ];

        $url = $baseUrl . '?' . http_build_query($params);

        // Retry every failed attempt. ENTSO-E sometimes holds a request for 18-30 s and then answers
        // with a non-5xx error (about 1.6 KB); the next hourly run succeeds (October 2026).
        $response = Http::connectTimeout((int) config('services.entsoe.connect_timeout', 5))
            ->timeout((int) config('services.entsoe.timeout', 30))
            ->retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception) {
                return $exception instanceof ConnectionException || $exception instanceof RequestException;
            })->get($url);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $this->parseXmlResponse($response->body(), $region);
    }

    /**
     * Resolve the ENTSO-E EIC code of a configured bidding zone.
     */
    public function eicFor(string $region): string
    {
        if ($region === 'FI') {
            return (string) config('services.entsoe.finland_eic', '10YFI-1--------U');
        }

        $eic = config("services.entsoe.bidding_zones.{$region}");

        if (! is_string($eic) || $eic === '') {
            throw new InvalidArgumentException("No ENTSO-E EIC code configured for region {$region}.");
        }

        return $eic;
    }

    /**
     * Parse ENTSO-E XML response and extract price data.
     *
     * @param string $xmlContent Raw XML response
     * @param string $region Region code written to each record
     * @return array Parsed price data
     */
    private function parseXmlResponse(string $xmlContent, string $region): array
    {
        // Check for empty or acknowledgement response (no data)
        if ($this->isNoDataResponse($xmlContent)) {
            return [];
        }

        try {
            $xml = new SimpleXMLElement($xmlContent);
        } catch (\Exception $e) {
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

        // Some zones (DE-LU) publish more than one price sequence per day. Sequence 1 is the
        // coupled SDAC day-ahead price; it matched SMARD/Energy-Charts exactly on 2026-10-31.
        // Zones without a sequence element (FI, SE, EE) keep every series.
        $sequenceOf = function (SimpleXMLElement $series) use ($ns): ?string {
            $element = $ns
                ? $series->xpath('ns:classificationSequence_AttributeInstanceComponent.position')
                : $series->xpath('classificationSequence_AttributeInstanceComponent.position');

            return isset($element[0]) ? trim((string) $element[0]) : null;
        };

        foreach ($timeSeries as $series) {
            if ($ns) {
                $series->registerXPathNamespace('ns', $ns);
            }

            $sequence = $sequenceOf($series);
            if ($sequence !== null && $sequence !== '1') {
                continue;
            }

            $curveElement = $ns ? $series->xpath('ns:curveType') : $series->xpath('curveType');
            $curveType = trim((string) ($curveElement[0] ?? 'A01'));

            // Get Period elements
            $periods = $ns ? $series->xpath('ns:Period') : $series->xpath('Period');

            foreach ($periods as $period) {
                if ($ns) {
                    $period->registerXPathNamespace('ns', $ns);
                }

                $periodPrices = $this->parsePeriod($period, $ns, $region, $curveType);
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
     * Curve type A03 (variable-sized blocks) omits a point when the price does not change: a
     * point is valid until the next point's position, and the last point until the end of the
     * time interval. ENTSO-E sends A03 for FI and its neighbours since the 15-minute MTU.
     * Without this fill, whole hours were missing and hourly means used 3 of 4 quarters.
     *
     * @param SimpleXMLElement $period Period XML element
     * @param string $ns Namespace URI
     * @return array Price data from this period with 'resolution_minutes' key
     */
    private function parsePeriod(SimpleXMLElement $period, string $ns, string $region, string $curveType = 'A01'): array
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

        $endElement = $ns
            ? $period->xpath('ns:timeInterval/ns:end')
            : $period->xpath('timeInterval/end');
        $end = (string) ($endElement[0] ?? '');

        if ($curveType === 'A03' && $end !== '' && $resolutionMinutes > 0) {
            return $this->parseVariableBlockPoints($points, $ns, $periodStart, Carbon::parse($end, 'UTC'), $resolutionMinutes, $region);
        }

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
                'region' => $region,
                'timestamp' => $pointTime->timestamp,
                'utc_datetime' => $pointTime,
                'price_without_tax' => $priceCentsKwh,
                'resolution_minutes' => $resolutionMinutes,
            ];
        }

        return $prices;
    }

    /**
     * Expand A03 points so that every slot of the time interval has a price.
     *
     * @param array<int, SimpleXMLElement> $points
     */
    private function parseVariableBlockPoints(
        array $points,
        string $ns,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $resolutionMinutes,
        string $region
    ): array {
        $slotCount = intdiv((int) $periodStart->diffInMinutes($periodEnd), $resolutionMinutes);
        $priceByPosition = [];

        foreach ($points as $point) {
            if ($ns) {
                $point->registerXPathNamespace('ns', $ns);
            }

            $positionElement = $ns ? $point->xpath('ns:position') : $point->xpath('position');
            $priceElement = $ns ? $point->xpath('ns:price.amount') : $point->xpath('price.amount');

            if (! isset($positionElement[0], $priceElement[0])) {
                continue;
            }

            $position = (int) $positionElement[0];
            if ($position >= 1 && $position <= $slotCount) {
                $priceByPosition[$position] = (float) $priceElement[0];
            }
        }

        if ($priceByPosition === []) {
            return [];
        }

        ksort($priceByPosition);
        $prices = [];
        $currentPrice = null;

        for ($position = min(array_keys($priceByPosition)); $position <= $slotCount; $position++) {
            if (array_key_exists($position, $priceByPosition)) {
                $currentPrice = $priceByPosition[$position];
            }

            $pointTime = $periodStart->copy()->addMinutes(($position - 1) * $resolutionMinutes);
            $prices[] = [
                'region' => $region,
                'timestamp' => $pointTime->timestamp,
                'utc_datetime' => $pointTime,
                'price_without_tax' => $currentPrice / 10,
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
