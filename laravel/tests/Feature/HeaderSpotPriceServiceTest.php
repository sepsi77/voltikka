<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use App\Services\HeaderSpotPriceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HeaderSpotPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_current_price_falls_back_to_hourly_price_when_quarter_price_is_missing(): void
    {
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE));

        $helsinkiHour = Carbon::parse('2026-01-20 14:00:00', self::TIMEZONE);
        $utcHour = $helsinkiHour->copy()->setTimezone('UTC');

        SpotPriceHour::create([
            'region' => self::REGION,
            'timestamp' => $utcHour->timestamp,
            'utc_datetime' => $utcHour,
            'price_without_tax' => 8.0,
            'vat_rate' => 0.255,
        ]);

        $currentPrice = app(HeaderSpotPriceService::class)->getCurrentPrice();

        $this->assertNotNull($currentPrice);
        $this->assertFalse($currentPrice['is_quarter']);
        $this->assertSame(10.04, $currentPrice['price_with_tax']);
        $this->assertSame('14:00-15:00', $currentPrice['time_label']);
    }

    public function test_current_price_prefers_quarter_price_when_available(): void
    {
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE));

        $helsinkiQuarter = Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE);
        $utcQuarter = $helsinkiQuarter->copy()->setTimezone('UTC');
        $helsinkiHour = Carbon::parse('2026-01-20 14:00:00', self::TIMEZONE);
        $utcHour = $helsinkiHour->copy()->setTimezone('UTC');

        SpotPriceHour::create([
            'region' => self::REGION,
            'timestamp' => $utcHour->timestamp,
            'utc_datetime' => $utcHour,
            'price_without_tax' => 8.0,
            'vat_rate' => 0.255,
        ]);

        SpotPriceQuarter::create([
            'region' => self::REGION,
            'timestamp' => $utcQuarter->timestamp,
            'utc_datetime' => $utcQuarter,
            'price_without_tax' => 6.0,
            'vat_rate' => 0.255,
        ]);

        $currentPrice = app(HeaderSpotPriceService::class)->getCurrentPrice();

        $this->assertNotNull($currentPrice);
        $this->assertTrue($currentPrice['is_quarter']);
        $this->assertSame(7.53, $currentPrice['price_with_tax']);
        $this->assertSame('14:30-14:45', $currentPrice['time_label']);
    }
}
