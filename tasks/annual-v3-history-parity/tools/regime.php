<?php
// Read-only: median, display regime and stored compatibility key per segment/consumption/date.
require '/Users/seppo/code/voltikka/laravel/vendor/autoload.php';
$app = require '/Users/seppo/code/voltikka/laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use Illuminate\Support\Facades\DB;
DB::statement('PRAGMA query_only = ON');
$dates = array_slice($argv, 1);
$rows = DB::table('contract_price_daily_statistics')->where('metric_key', 'annual_cost')->where('method_version', 'annual_cost_as_of_v3')
    ->whereIn(DB::raw('DATE(stat_date)'), $dates)->orderBy('segment_key')->orderBy('consumption_kwh')->orderBy('stat_date')->get();
$display = [];
$compat = [];
foreach ($rows as $r) {
    $d = substr($r->stat_date, 0, 10);
    $counts = json_decode($r->basis_counts, true);
    $dk = AnnualSeriesCompatibility::aggregateDisplayKey($r->compatibility_key, $r->method_version, $counts);
    $display[$r->segment_key][$r->consumption_kwh][$d] = $dk;
    $compat[$r->segment_key][$r->consumption_kwh][$d] = $r->compatibility_key;
    $med[$r->segment_key][$r->consumption_kwh][$d] = $r->median_value;
    $dom[$r->segment_key][$r->consumption_kwh][$d] = json_encode($counts['estimate_method'] ?? []);
}
foreach ($med as $seg => $byKwh) {
    foreach ($byKwh as $kwh => $byDate) {
        $line = sprintf('%-18s %5d', $seg, $kwh);
        $dks = array_values(array_unique($display[$seg][$kwh]));
        $cks = array_values(array_unique($compat[$seg][$kwh]));
        foreach ($dates as $d) {
            $line .= sprintf(' %s=%s', substr($d, 5), isset($byDate[$d]) ? number_format((float) $byDate[$d], 2, '.', '') : '—');
            $line .= isset($display[$seg][$kwh][$d]) ? '[D'.array_search($display[$seg][$kwh][$d], $dks).'C'.array_search($compat[$seg][$kwh][$d], $cks).']' : '';
        }
        echo $line, PHP_EOL;
        if (count($dks) > 1 && getenv('SHOW_COUNTS')) {
            foreach ($dates as $d) { echo '      ', $d, ' ', $dom[$seg][$kwh][$d] ?? '', PHP_EOL; }
        }
    }
}
