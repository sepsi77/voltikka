<?php

namespace App\Http\Controllers;

use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use PDO;
use RuntimeException;
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
            fputcsv($out, ['# CSV sisältää kaikki menetelmäversiot auditointia varten. Vain is_active_annual_method=1 on julkisessa vuosikustannuskäytössä.']);
            fputcsv($out, ['# Yksikköhintarivit käyttävät method_version=unit_statistics_v1 ja is_active_annual_method=0.']);
            fputcsv($out, ['# '.SellerSetEnergyPriceIndexService::METRIC_KEY.': avg on myyjien suoran yleissähköhinnan indeksi (c/kWh); segmentit erottavat kokonaisindeksin, kolme sopimusperhettä ja indeksin ulkopuolisen Hybridin perushinnan.']);
            fputcsv($out, ['# pricing_basis=canonical_calculation: nykyhinta on validoidusta kanonisesta hinnoittelusta laskettu arvo.']);
            fputcsv($out, ['# pricing_basis=observed_seller_data: historiallinen arvo on kyseisenä päivänä havaittu myyjädata.']);
            fputcsv($out, []);

            fputcsv($out, [
                'date',
                'segment_key',
                'metric_key',
                'pricing_basis',
                'method_version',
                'calculation_basis',
                'estimate_basis',
                'compatibility_key',
                'basis_counts',
                'is_active_annual_method',
                'consumption_kwh',
                'min',
                'p20',
                'median',
                'avg',
                'p80',
                'max',
                'contract_count',
            ]);

            $activeAnnualMethod = ContractPriceDailyStatistic::activeAnnualMethodVersion()->value;

            $query = ContractPriceDailyStatistic::query()
                ->orderBy('stat_date')
                ->orderBy('segment_key')
                ->orderBy('metric_key')
                ->orderBy('consumption_kwh')
                ->orderBy('method_version');

            $pdo = $query->getConnection()->getReadPdo();
            $buffered = null;

            try {
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
                    if (! $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false)) {
                        throw new RuntimeException('Cannot disable CSV query buffering.');
                    }
                }

                $rows = $query->cursor()->getIterator();
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->stat_date instanceof CarbonInterface
                            ? $row->stat_date->toDateString()
                            : (string) $row->stat_date,
                        $row->segment_key,
                        $row->metric_key,
                        $row->pricing_basis,
                        $row->method_version,
                        $row->calculation_basis,
                        $row->estimate_basis,
                        $row->compatibility_key,
                        $row->basis_counts === null
                            ? null
                            : json_encode($row->basis_counts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $row->metric_key === 'annual_cost' && $row->method_version === $activeAnnualMethod ? 1 : 0,
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
            } finally {
                // Release the generator and its PDO statement before another query can run.
                unset($rows);
                try {
                    if ($buffered !== null && ! $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered)) {
                        throw new RuntimeException('Cannot restore CSV query buffering.');
                    }
                } finally {
                    fclose($out);
                }
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
