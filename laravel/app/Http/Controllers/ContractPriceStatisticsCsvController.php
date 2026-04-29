<?php

namespace App\Http\Controllers;

use App\Models\ContractPriceDailyStatistic;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractPriceStatisticsCsvController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $today = Carbon::today()->toDateString();
        $generatedAt = Carbon::now()->toIso8601String();
        $filename = "voltikka-sahkon-hintatilastot-{$today}.csv";

        return response()->streamDownload(function () use ($generatedAt) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['# Voltikka — Sähkön hintatilastot']);
            fputcsv($out, ["# Generoitu: {$generatedAt}"]);
            fputcsv($out, ['# Lähde: https://voltikka.fi/sahkosopimus/tilastot']);
            fputcsv($out, ['# Lisenssi: CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/deed.fi). Lähde mainittava: Voltikka.']);
            fputcsv($out, ['# Hinnat sisältävät arvonlisäveron 25,5 %.']);
            fputcsv($out, ['# Spot-sopimusten kokonaishinta = pörssin keskihinta + sopimuksen marginaali.']);
            fputcsv($out, []);

            fputcsv($out, [
                'date',
                'segment_key',
                'metric_key',
                'consumption_kwh',
                'min',
                'p20',
                'median',
                'avg',
                'p80',
                'max',
                'contract_count',
            ]);

            ContractPriceDailyStatistic::query()
                ->orderBy('stat_date')
                ->orderBy('segment_key')
                ->orderBy('metric_key')
                ->orderBy('consumption_kwh')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->stat_date instanceof \Carbon\CarbonInterface
                                ? $row->stat_date->toDateString()
                                : (string) $row->stat_date,
                            $row->segment_key,
                            $row->metric_key,
                            $row->consumption_kwh,
                            $row->min_value,
                            $row->p20_value,
                            $row->median_value,
                            $row->avg_value,
                            $row->p80_value,
                            $row->max_value,
                            $row->contract_count,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
