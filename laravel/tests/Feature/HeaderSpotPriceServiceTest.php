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

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_current_price_falls_back_to_hourly_price_when_quarter_price_is_missing(): void
    {
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

    public function test_negative_quarter_price_is_returned_and_rendered_as_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE));
        $utcQuarter = Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE)->utc();

        SpotPriceQuarter::create([
            'region' => self::REGION,
            'timestamp' => $utcQuarter->timestamp,
            'utc_datetime' => $utcQuarter,
            'price_without_tax' => -2.0,
            'vat_rate' => 0.255,
        ]);

        $currentPrice = app(HeaderSpotPriceService::class)->getCurrentPrice();

        $this->assertNotNull($currentPrice);
        $this->assertSame(-2.51, $currentPrice['price_with_tax']);

        $this->get('/api/header-spot-price')
            ->assertOk()
            ->assertSee('data-header-spot-price-state="available"', false)
            ->assertSee('-2,51 c/kWh')
            ->assertDontSee('Spot-hintoja ei ole saatavilla');
    }

    public function test_zero_hourly_price_is_returned_and_rendered_as_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE));
        $utcHour = Carbon::parse('2026-01-20 14:00:00', self::TIMEZONE)->utc();

        SpotPriceHour::create([
            'region' => self::REGION,
            'timestamp' => $utcHour->timestamp,
            'utc_datetime' => $utcHour,
            'price_without_tax' => 0.0,
            'vat_rate' => 0.255,
        ]);

        $currentPrice = app(HeaderSpotPriceService::class)->getCurrentPrice();

        $this->assertNotNull($currentPrice);
        $this->assertSame(0.0, $currentPrice['price_with_tax']);

        $this->get('/api/header-spot-price')
            ->assertOk()
            ->assertSee('data-header-spot-price-state="available"', false)
            ->assertSee('0,00 c/kWh')
            ->assertDontSee('Spot-hintoja ei ole saatavilla');
    }

    public function test_header_api_renders_explicit_unavailable_state_without_fake_polling(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-20 14:30:00', self::TIMEZONE));

        $response = $this->get('/api/header-spot-price');

        $response
            ->assertOk()
            ->assertSee('data-header-spot-price-state="unavailable"', false)
            ->assertSee('Spot-hintoja ei ole saatavilla')
            ->assertDontSee('wire:poll', false);

        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('stale-while-revalidate=300', (string) $response->headers->get('Cache-Control'));
    }

    public function test_header_shell_uses_same_unavailable_copy(): void
    {
        $html = view('components.header-spot-price-shell')->render();

        $this->assertStringContainsString('Spot-hintoja ei ole saatavilla', $html);
    }
}
