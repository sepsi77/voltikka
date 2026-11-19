<?php

namespace Tests\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** ENTSO-E A44 bodies for command tests of spot:fetch and spot:fetch-neighbours. */
trait FakesEntsoeDayAhead
{
    private const ENTSOE_URL = 'https://web-api.tp.entsoe.eu/api*';

    /**
     * One A01 TimeSeries per point, so any set of timestamps and resolutions can be expressed.
     * An empty list gives the acknowledgement document ENTSO-E sends when it has no data.
     *
     * @param  list<array{0: int, 1: float, 2?: int}>  $points  [UTC timestamp, c/kWh VAT0, resolution minutes (60)]
     */
    private function dayAheadXml(array $points): string
    {
        if ($points === []) {
            return '<?xml version="1.0" encoding="UTF-8"?><Acknowledgement_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-1:acknowledgementdocument:7:0"><Reason><code>999</code><text>No matching data found</text></Reason></Acknowledgement_MarketDocument>';
        }

        $series = '';
        foreach ($points as $point) {
            $minutes = $point[2] ?? 60;
            $start = Carbon::createFromTimestampUTC($point[0]);
            $series .= '<TimeSeries><curveType>A01</curveType><Period><timeInterval>'
                .'<start>'.$start->format('Y-m-d\TH:i\Z').'</start>'
                .'<end>'.$start->copy()->addMinutes($minutes)->format('Y-m-d\TH:i\Z').'</end>'
                ."</timeInterval><resolution>PT{$minutes}M</resolution>"
                .'<Point><position>1</position><price.amount>'.($point[1] * 10).'</price.amount></Point>'
                .'</Period></TimeSeries>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><Publication_MarketDocument xmlns="urn:iec62325.351:tc57wg16:451-3:publicationdocument:7:3">'
            .$series.'</Publication_MarketDocument>';
    }

    /**
     * The four quarters of one UTC hour.
     *
     * @return list<array{0: int, 1: float, 2: int}>
     */
    private function quarterPoints(string $utcHour, float $price): array
    {
        $start = Carbon::parse($utcHour, 'UTC');

        return array_map(fn (int $i) => [$start->copy()->addMinutes(15 * $i)->timestamp, $price + $i, 15], range(0, 3));
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(string $output): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function spotRows(): array
    {
        $strip = fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at']));

        return [
            ...DB::table('spot_prices_quarter')->orderBy('region')->orderBy('timestamp')->get()->map($strip)->all(),
            ...DB::table('spot_prices_hour')->orderBy('region')->orderBy('timestamp')->get()->map($strip)->all(),
        ];
    }

    private function decompressedPayload(string $path): string
    {
        return (string) gzdecode((string) file_get_contents($path));
    }
}
