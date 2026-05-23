<?php

namespace App\Services\SpotForecasts;

use App\Models\SpotPriceForecast;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class NordpoolPredictFiService
{
    public const SOURCE = SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI;

    /**
     * Fetch and normalize the public nordpool-predict-fi prediction feed.
     *
     * Upstream format: [[timestamp_ms, price_cents_per_kWh_with_VAT], ...]
     *
     * @return array<int, array<string, mixed>>
     * @throws RequestException|ConnectionException|UnexpectedValueException
     */
    public function fetchForecasts(): array
    {
        $url = $this->feedUrl();
        $fetchedAt = Carbon::now('UTC');

        $response = Http::connectTimeout((float) config('services.spot_forecasts.nordpool_predict_fi.connect_timeout', 5))
            ->timeout((float) config('services.spot_forecasts.nordpool_predict_fi.timeout', 15))
            ->retry(
                (int) config('services.spot_forecasts.nordpool_predict_fi.retry_attempts', 3),
                (int) config('services.spot_forecasts.nordpool_predict_fi.retry_delay_ms', 1000)
            )
            ->acceptJson()
            ->get($url);

        $response->throw();

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new UnexpectedValueException('nordpool-predict-fi feed did not return a JSON array.');
        }

        $feedHash = hash('sha256', $response->body());
        $records = [];

        foreach ($payload as $index => $point) {
            if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                throw new UnexpectedValueException("Invalid prediction point at index {$index}.");
            }

            $timestamp = (int) floor(((float) $point[0]) / 1000);
            $priceWithTax = (float) $point[1];

            $records[] = [
                'source' => self::SOURCE,
                'region' => (string) config('services.spot_forecasts.nordpool_predict_fi.region', 'FI'),
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestampUTC($timestamp),
                'price_with_tax' => round($priceWithTax, 4),
                'vat_rate' => (float) config('services.spot_forecasts.nordpool_predict_fi.vat_rate', 0.255),
                'source_url' => (string) config('services.spot_forecasts.nordpool_predict_fi.source_url', 'https://github.com/vividfog/nordpool-predict-fi'),
                'fetched_at' => $fetchedAt,
                'metadata' => json_encode([
                    'feed_url' => $url,
                    'feed_hash' => $feedHash,
                    'source_price_unit' => 'c/kWh with VAT',
                    'upstream_format' => '[timestamp_ms, price_cents_per_kWh_with_VAT]',
                ], JSON_THROW_ON_ERROR),
            ];
        }

        return $records;
    }

    public function feedUrl(): string
    {
        return (string) config(
            'services.spot_forecasts.nordpool_predict_fi.url',
            'https://raw.githubusercontent.com/vividfog/nordpool-predict-fi/main/deploy/prediction.json'
        );
    }
}
