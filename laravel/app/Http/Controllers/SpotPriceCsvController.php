<?php

namespace App\Http\Controllers;

use App\Models\SpotPriceForecast;
use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpotPriceCsvController extends Controller
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';
    private const HISTORY_DAYS = 7;
    private const FORECAST_DAYS = 7;

    public function __invoke(Request $request): StreamedResponse
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $historyStartUtc = $helsinkiNow->copy()->subDays(self::HISTORY_DAYS - 1)->startOfDay()->setTimezone('UTC');
        $historyEndUtc = $helsinkiNow->copy()->endOfDay()->setTimezone('UTC');
        $forecastEndUtc = $helsinkiNow->copy()->addDays(self::FORECAST_DAYS)->endOfDay()->setTimezone('UTC');
        $generatedAt = Carbon::now()->toIso8601String();
        $filename = "voltikka-porssisahkon-hinta-{$helsinkiNow->toDateString()}.csv";

        return response()->streamDownload(function () use ($historyStartUtc, $historyEndUtc, $forecastEndUtc, $generatedAt) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fwrite($out, "# Voltikka — Pörssisähkön tuntihinnat ja hintaennuste\n");
            fwrite($out, "# Generoitu: {$generatedAt}\n");
            fwrite($out, "# Lähde: https://voltikka.fi/spot-price\n");
            fwrite($out, "# Toteutuneet hinnat: Nord Pool (ENTSO-E). Ennusteet: nordpool-predict-fi (MIT-lisenssi, https://github.com/vividfog/nordpool-predict-fi)\n");
            fwrite($out, "# Lisenssi: CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/deed.fi). Lähde mainittava: Voltikka.\n");
            fwrite($out, "# Aikavyöhyke: Europe/Helsinki\n");
            fwrite($out, "\n");

            fputcsv($out, [
                'Päivämäärä',
                'Tunti',
                'Hinta (c/kWh) ALV 0%',
                'Hinta (c/kWh) sis. ALV',
                'ALV %',
                'Tyyppi',
            ]);

            $latestActualUtc = null;

            SpotPriceHour::forRegion(self::REGION)
                ->whereBetween('utc_datetime', [$historyStartUtc, $historyEndUtc])
                ->orderBy('utc_datetime')
                ->chunk(500, function ($rows) use ($out, &$latestActualUtc) {
                    foreach ($rows as $price) {
                        $helsinkiTime = Carbon::parse($price->utc_datetime)
                            ->shiftTimezone('UTC')
                            ->setTimezone(self::TIMEZONE);

                        fputcsv($out, [
                            $helsinkiTime->format('Y-m-d'),
                            $helsinkiTime->format('H') . ':00-' . $helsinkiTime->copy()->addHour()->format('H') . ':00',
                            number_format($price->price_without_tax, 2, '.', ''),
                            number_format($price->price_with_tax, 2, '.', ''),
                            number_format($price->vat_rate * 100, 1, '.', ''),
                            'Toteutunut',
                        ]);

                        $latestActualUtc = $price->utc_datetime;
                    }
                });

            $forecastStartUtc = $latestActualUtc
                ? Carbon::parse($latestActualUtc)->shiftTimezone('UTC')->addHour()
                : $historyEndUtc->copy()->addSecond();

            SpotPriceForecast::forRegion(self::REGION)
                ->forSource(SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI)
                ->where('utc_datetime', '>=', $forecastStartUtc)
                ->where('utc_datetime', '<=', $forecastEndUtc)
                ->orderBy('utc_datetime')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $forecast) {
                        $helsinkiTime = Carbon::parse($forecast->utc_datetime)
                            ->shiftTimezone('UTC')
                            ->setTimezone(self::TIMEZONE);

                        fputcsv($out, [
                            $helsinkiTime->format('Y-m-d'),
                            $helsinkiTime->format('H') . ':00-' . $helsinkiTime->copy()->addHour()->format('H') . ':00',
                            number_format($forecast->price_without_tax, 2, '.', ''),
                            number_format($forecast->price_with_tax, 2, '.', ''),
                            number_format($forecast->vat_rate * 100, 1, '.', ''),
                            'Ennuste',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
