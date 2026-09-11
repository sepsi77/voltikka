<?php

namespace Tests\Feature;

use App\Livewire\ContractPriceStatistics;
use App\Models\SpotPriceAverage;
use App\Services\Caching\ContractPageCacheVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpotRollingReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_helpers_prefer_local_on_a_tie_but_keep_newer_legacy_rows_usable(): void
    {
        foreach ([30, 365] as $days) {
            $legacyType = $days === 30 ? SpotPriceAverage::PERIOD_ROLLING_30D : SpotPriceAverage::PERIOD_ROLLING_365D;
            $localType = $days === 30 ? SpotPriceAverage::PERIOD_ROLLING_30D_LOCAL : SpotPriceAverage::PERIOD_ROLLING_365D_LOCAL;
            $method = $days === 30 ? 'latestRolling30Days' : 'latestRolling365Days';
            $local = $this->average($localType, '2026-06-01', 6.0);
            $this->average($legacyType, '2026-06-01', 99.0);
            $this->assertSame($local->id, SpotPriceAverage::$method()->id);

            $newerLegacy = $this->average($legacyType, '2026-06-02', 7.0);
            $this->assertSame($newerLegacy->id, SpotPriceAverage::$method()->id);
            $newerLocal = $this->average($localType, '2026-06-02', 8.0);
            $this->assertSame($newerLocal->id, SpotPriceAverage::$method()->id);
        }
    }

    public function test_statistics_reader_uses_local_rows_and_carries_sparse_evidence(): void
    {
        $this->average(SpotPriceAverage::PERIOD_ROLLING_365D, '2026-06-01', 99.0);
        $local = $this->average(SpotPriceAverage::PERIOD_ROLLING_365D_LOCAL, '2026-06-01', 6.0);
        $local->update(['hours_count' => 2]);
        $service = app(\App\Services\ContractStatistics\ContractPriceStatisticsService::class);
        $method = new \ReflectionMethod($service, 'rolling365EvidenceForDate');
        $shape = $method->invoke($service, '2026-06-01');
        $this->assertSame(6.0, $shape->overallAvgWithTax);
        $this->assertSame(2, $shape->actualHours);
        $this->assertSame('helsinki_dates_v2', $shape->windowSemantics);
    }

    public function test_statistics_raw_reader_classifies_utc_hours_and_excludes_the_next_local_date(): void
    {
        foreach (['2026-06-01 04:00:00' => 10.0, '2026-06-01 19:00:00' => 2.0, '2026-06-01 21:00:00' => 999.0] as $time => $price) {
            \Illuminate\Support\Facades\DB::table('spot_prices_hour')->insert([
                'region' => 'FI',
                'timestamp' => \Carbon\CarbonImmutable::parse($time, 'UTC')->timestamp,
                'utc_datetime' => $time,
                'price_without_tax' => $price,
                'vat_rate' => 0.0,
            ]);
        }
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Helsinki');
            $service = app(\App\Services\ContractStatistics\ContractPriceStatisticsService::class);
            $method = new \ReflectionMethod($service, 'rolling365EvidenceForDate');
            $shape = $method->invoke($service, '2026-06-01');
            $this->assertSame(2, $shape->actualHours);
            $this->assertSame(10.0, $shape->dayAvgWithTax);
            $this->assertSame(2.0, $shape->nightAvgWithTax);
            $this->assertSame(6.0, $shape->overallAvgWithTax);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_local_rolling_updates_change_page_and_daily_statistics_fingerprints(): void
    {
        $page = app(ContractPageCacheVersion::class);
        $statistics = new ContractPriceStatistics;
        $version = new \ReflectionMethod($statistics, 'statisticsDataVersion');
        $initialPage = $page->hash();
        $initialStatistics = $version->invoke($statistics);
        $row = $this->average(SpotPriceAverage::PERIOD_ROLLING_365D_LOCAL, '2026-06-01', 6.0);
        $addedPage = $page->hash();
        $addedStatistics = $version->invoke($statistics);
        $this->assertNotSame($initialPage, $addedPage);
        $this->assertNotSame($initialStatistics, $addedStatistics);

        // A same-second refresh can alter coverage and price without changing dates or count.
        $row->update(['hours_count' => 8700, 'avg_price_with_tax' => 6.1]);
        $this->assertNotSame($addedPage, $page->hash());
        $this->assertNotSame($addedStatistics, $version->invoke($statistics));
    }

    private function average(string $type, string $end, float $price): SpotPriceAverage
    {
        return SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => $type,
            'period_start' => $end,
            'period_end' => $end,
            'avg_price_without_tax' => $price,
            'avg_price_with_tax' => $price,
            'day_avg_with_tax' => $price,
            'night_avg_with_tax' => $price,
            'hours_count' => 8760,
        ]);
    }
}
