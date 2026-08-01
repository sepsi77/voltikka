<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use App\Services\SpotPriceImport\SpotPriceImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpotPriceImporterTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('vatBoundaries')]
    public function test_it_selects_vat_by_helsinki_local_time(string $localDateTime, float $expectedVatRate): void
    {
        $utcDatetime = Carbon::parse($localDateTime, 'Europe/Helsinki')->utc();

        $this->importer()->import([[
            'region' => 'FI',
            'timestamp' => $utcDatetime->timestamp,
            'utc_datetime' => $utcDatetime,
            'price_without_tax' => 5.0,
            'resolution_minutes' => 60,
        ]]);

        $this->assertEqualsWithDelta(
            $expectedVatRate,
            SpotPriceHour::where('timestamp', $utcDatetime->timestamp)->value('vat_rate'),
            0.0001
        );
    }

    public static function vatBoundaries(): array
    {
        return [
            'before temporary reduction' => ['2022-11-30 23:59:59', 0.24],
            'at temporary reduction' => ['2022-12-01 00:00:00', 0.10],
            'before standard rate returns' => ['2023-04-30 23:59:59', 0.10],
            'at standard rate return' => ['2023-05-01 00:00:00', 0.24],
            'before VAT increase' => ['2024-08-31 23:59:59', 0.24],
            'at VAT increase' => ['2024-09-01 00:00:00', 0.255],
        ];
    }

    public function test_four_quarter_rows_create_one_hourly_arithmetic_mean(): void
    {
        $hourStart = Carbon::create(2025, 12, 1, 10, 0, 0, 'UTC');
        $prices = [];

        foreach ([1.0, 2.0, 3.0, 4.0] as $index => $price) {
            $utcDatetime = $hourStart->copy()->addMinutes($index * 15);
            $prices[] = [
                'region' => 'FI',
                'timestamp' => $utcDatetime->timestamp,
                'utc_datetime' => $utcDatetime,
                'price_without_tax' => $price,
                'resolution_minutes' => 15,
            ];
        }

        $this->importer()->import($prices);

        $this->assertSame(4, SpotPriceQuarter::count());
        $this->assertSame(1, SpotPriceHour::count());
        $this->assertEqualsWithDelta(2.5, SpotPriceHour::firstOrFail()->price_without_tax, 0.0001);
    }

    public function test_direct_hourly_rows_persist(): void
    {
        $utcDatetime = Carbon::create(2025, 12, 1, 10, 0, 0, 'UTC');

        $this->importer()->import([[
            'region' => 'FI',
            'timestamp' => $utcDatetime->timestamp,
            'utc_datetime' => $utcDatetime,
            'price_without_tax' => -1.5,
            'resolution_minutes' => 60,
        ]]);

        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => $utcDatetime->timestamp,
            'price_without_tax' => -1.5,
        ]);
        $this->assertSame(0, SpotPriceQuarter::count());
    }

    public function test_partial_exact_hourly_range_is_incomplete(): void
    {
        $start = Carbon::create(2025, 12, 1, 0, 0, 0, 'UTC');
        $end = $start->copy()->addHours(3);

        $this->insertHour($start);
        $this->insertHour($start->copy()->addMinutes(90));
        $this->insertHour($start->copy()->addHours(2));

        $this->assertFalse($this->importer()->hasCompleteHourlyCoverage($start, $end, 'FI'));
    }

    public function test_fully_populated_exact_hourly_range_is_complete(): void
    {
        $start = Carbon::create(2025, 12, 1, 0, 0, 0, 'UTC');
        $end = $start->copy()->addHours(3);

        for ($hour = $start->copy(); $hour->lessThan($end); $hour->addHour()) {
            $this->insertHour($hour);
        }

        $this->assertTrue($this->importer()->hasCompleteHourlyCoverage($start, $end, 'FI'));
    }

    private function importer(): SpotPriceImporter
    {
        return $this->app->make(SpotPriceImporter::class);
    }

    private function insertHour(Carbon $utcDatetime): void
    {
        SpotPriceHour::create([
            'region' => 'FI',
            'timestamp' => $utcDatetime->timestamp,
            'utc_datetime' => $utcDatetime,
            'price_without_tax' => 5.0,
            'vat_rate' => 0.255,
        ]);
    }
}
