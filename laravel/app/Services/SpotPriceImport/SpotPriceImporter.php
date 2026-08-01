<?php

namespace App\Services\SpotPriceImport;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use Carbon\Carbon;

class SpotPriceImporter
{
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Persist normalized official spot-price records.
     */
    public function import(array $spotPrices): void
    {
        $quarterData = [];
        $hourlyData = [];

        foreach ($spotPrices as $item) {
            $utcDatetime = $item['utc_datetime'] instanceof Carbon
                ? $item['utc_datetime']->copy()->utc()
                : Carbon::parse($item['utc_datetime'], 'UTC')->utc();

            $record = [
                'region' => $item['region'],
                'timestamp' => $item['timestamp'],
                'utc_datetime' => $utcDatetime,
                'price_without_tax' => $item['price_without_tax'],
                'vat_rate' => $this->vatRateFor($utcDatetime),
            ];

            if (($item['resolution_minutes'] ?? 60) === 15) {
                $quarterData[] = $record;
            } else {
                $hourlyData[] = $record;
            }
        }

        $this->insertInChunks(SpotPriceQuarter::class, $quarterData);
        $this->insertInChunks(SpotPriceHour::class, $this->calculateHourlyAverages($quarterData));
        $this->insertInChunks(SpotPriceHour::class, $hourlyData);
    }

    /**
     * Determine whether every exact UTC hour exists in a half-open range.
     */
    public function hasCompleteHourlyCoverage(Carbon $start, Carbon $end, string $region): bool
    {
        $expectedTimestamps = [];
        $hour = $start->copy()->utc();
        $rangeEnd = $end->copy()->utc();

        while ($hour->lessThan($rangeEnd)) {
            $expectedTimestamps[] = $hour->timestamp;
            $hour->addHour();
        }

        if ($expectedTimestamps === []) {
            return true;
        }

        $storedTimestamps = SpotPriceHour::query()
            ->where('region', $region)
            ->where('timestamp', '>=', $expectedTimestamps[0])
            ->where('timestamp', '<', $rangeEnd->timestamp)
            ->pluck('timestamp')
            ->map(fn ($timestamp) => (int) $timestamp)
            ->unique()
            ->all();

        return array_diff($expectedTimestamps, $storedTimestamps) === [];
    }

    /**
     * @param  class-string<SpotPriceHour|SpotPriceQuarter>  $modelClass
     */
    private function insertInChunks(string $modelClass, array $records): void
    {
        foreach (array_chunk($records, self::INSERT_CHUNK_SIZE) as $chunk) {
            $modelClass::insertOrIgnore($chunk);
        }
    }

    /**
     * Calculate arithmetic hourly means, grouped by region and UTC hour.
     */
    private function calculateHourlyAverages(array $quarterData): array
    {
        $hourGroups = [];

        foreach ($quarterData as $item) {
            $hourStart = $item['utc_datetime']->copy()->utc()->startOfHour();
            $hourKey = $item['region'].'|'.$hourStart->timestamp;

            if (! isset($hourGroups[$hourKey])) {
                $hourGroups[$hourKey] = [
                    'prices' => [],
                    'region' => $item['region'],
                    'vat_rate' => $item['vat_rate'],
                    'utc_datetime' => $hourStart,
                ];
            }

            $hourGroups[$hourKey]['prices'][] = $item['price_without_tax'];
        }

        $hourlyData = [];

        foreach ($hourGroups as $group) {
            $hourlyData[] = [
                'region' => $group['region'],
                'timestamp' => $group['utc_datetime']->timestamp,
                'utc_datetime' => $group['utc_datetime'],
                'price_without_tax' => array_sum($group['prices']) / count($group['prices']),
                'vat_rate' => $group['vat_rate'],
            ];
        }

        return $hourlyData;
    }

    /**
     * Select Finnish electricity VAT by Helsinki local time.
     */
    private function vatRateFor(Carbon $priceDate): float
    {
        $helsinkiDate = $priceDate->copy()->setTimezone('Europe/Helsinki');
        $reducedVatStart = Carbon::create(2022, 12, 1, 0, 0, 0, 'Europe/Helsinki');
        $reducedVatEnd = Carbon::create(2023, 5, 1, 0, 0, 0, 'Europe/Helsinki');
        $increasedVatStart = Carbon::create(2024, 9, 1, 0, 0, 0, 'Europe/Helsinki');

        if ($helsinkiDate->greaterThanOrEqualTo($reducedVatStart)
            && $helsinkiDate->lessThan($reducedVatEnd)) {
            return 0.10;
        }

        if ($helsinkiDate->greaterThanOrEqualTo($increasedVatStart)) {
            return 0.255;
        }

        return 0.24;
    }
}
