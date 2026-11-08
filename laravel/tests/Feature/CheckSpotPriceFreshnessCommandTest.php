<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CheckSpotPriceFreshnessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 8, 6, 12, 34, 0, 'Europe/Helsinki'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_no_data_logs_an_error_and_fails(): void
    {
        Log::spy();

        $this->artisan('spot:check-freshness')
            ->expectsOutput('Official FI Spot price data is stale.')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Official FI Spot price data is stale.', [
                'current_hour_utc' => '2026-08-06T09:00:00Z',
                'latest_hour_utc' => null,
                'lag_minutes' => null,
            ]);
    }

    public function test_stale_data_logs_safe_context_and_fails(): void
    {
        $this->storeFiHour(Carbon::create(2026, 8, 6, 8, 0, 0, 'UTC'));
        Log::spy();

        $this->artisan('spot:check-freshness')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Official FI Spot price data is stale.', [
                'current_hour_utc' => '2026-08-06T09:00:00Z',
                'latest_hour_utc' => '2026-08-06T08:00:00Z',
                'lag_minutes' => 60,
            ]);
    }

    public function test_current_hour_data_succeeds_without_an_error_log(): void
    {
        $this->storeFiHour(Carbon::create(2026, 8, 6, 9, 0, 0, 'UTC'));
        Log::spy();

        $this->artisan('spot:check-freshness')
            ->expectsOutput('Official FI Spot price data is current.')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('error');
    }

    private function storeFiHour(Carbon $hour): void
    {
        SpotPriceHour::query()->create([
            'region' => 'FI',
            'timestamp' => $hour->timestamp,
            'utc_datetime' => $hour,
            'price_without_tax' => 5.0,
            'vat_rate' => 0.255,
        ]);
    }
}
