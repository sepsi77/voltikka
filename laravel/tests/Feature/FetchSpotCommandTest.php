<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Models\SpotSocialPublication;
use App\Services\PostFastService;
use App\Services\SpotPriceAverageService;
use App\Services\SpotPriceImport\SpotPriceImporter;
use App\Services\SpotPriceVideoService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\CapturesSentryIssues;
use Tests\Concerns\FakesEntsoeDayAhead;
use Tests\TestCase;

class FetchSpotCommandTest extends TestCase
{
    use CapturesSentryIssues;
    use FakesEntsoeDayAhead;
    use RefreshDatabase;

    private const API_KEY = 'entsoe-test-secret-7731';

    private MockInterface $averageService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.entsoe.api_key' => self::API_KEY]);

        // Mock SpotPriceAverageService to prevent actual calculations
        $this->averageService = Mockery::mock(SpotPriceAverageService::class);
        $this->averageService->shouldReceive('calculateAllAverages')->zeroOrMoreTimes()->byDefault();
        $this->app->instance(SpotPriceAverageService::class, $this->averageService);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Answer every ENTSO-E request with these hourly points.
     *
     * @param  list<array{0: int, 1: float, 2?: int}>  $points
     */
    private function fakeEntsoe(array $points): string
    {
        $body = $this->dayAheadXml($points);
        Http::swap(new Factory);
        Http::fake([self::ENTSOE_URL => Http::response($body)]);

        return $body;
    }

    public function test_save_failure_creates_one_safe_issue(): void
    {
        $this->captureSentryIssues();
        $this->fakeEntsoe([[1705644000, 5.0]]);
        $importer = Mockery::mock(SpotPriceImporter::class);
        $importer->shouldReceive('import')->once()->andThrow(new \RuntimeException('private SQL and token'));
        $this->app->instance(SpotPriceImporter::class, $importer);
        $this->artisan('spot:fetch')->assertExitCode(1);
        $this->assertImportIssue('spot', 'error', ['save_or_post_import' => 1]);
        $this->assertSame(['save_or_post_import' => 'store'], $this->sentryIssues[0]->getContexts()['data_fetch']['steps']);
    }

    public function test_recovered_connection_retry_with_today_only_data_is_silent(): void
    {
        Sleep::fake();
        $this->captureSentryIssues();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new ConnectionException('private URL and token');
            }

            return Http::response('<Publication_MarketDocument><TimeSeries><Period><timeInterval><start>2026-08-25T00:00Z</start></timeInterval><resolution>PT60M</resolution><Point><position>1</position><price.amount>0</price.amount></Point></Period></TimeSeries></Publication_MarketDocument>');
        });
        $this->artisan('spot:fetch')->assertExitCode(0);
        $this->assertSame(3, $attempts);
        $this->assertSame([], $this->sentryIssues);
        $this->assertSame(1, SpotPriceHour::count());
    }

    /**
     * Test command fetches today's prices and saves to database.
     */
    public function test_command_fetches_todays_prices_and_saves_to_database(): void
    {
        $this->fakeEntsoe([[1705644000, 5.235], [1705647600, 5.512]]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => 1705644000,
        ]);
        $this->assertDatabaseHas('spot_prices_hour', [
            'region' => 'FI',
            'timestamp' => 1705647600,
        ]);
    }

    /**
     * Test command sets current VAT rate (25.5%) for prices from September 2024 onwards.
     */
    public function test_command_sets_current_vat_rate(): void
    {
        // Timestamp for January 2026 (after September 2024 VAT increase)
        $timestamp = Carbon::create(2026, 1, 19, 10, 0, 0, 'UTC')->timestamp;
        $this->fakeEntsoe([[$timestamp, 5.0]]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.255, $spotPrice->vat_rate);
    }

    /**
     * Test command applies standard VAT rate (24%) for prices before September 2024.
     */
    public function test_command_applies_standard_vat_rate_before_september_2024(): void
    {
        // Timestamp for August 2024 (before VAT increase)
        $timestamp = Carbon::create(2024, 8, 15, 10, 0, 0, 'UTC')->timestamp;
        $this->fakeEntsoe([[$timestamp, 5.0]]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.24, $spotPrice->vat_rate);
    }

    /**
     * Test command applies reduced VAT rate (10%) for Dec 2022 - Apr 2023 period.
     */
    public function test_command_applies_reduced_vat_rate_for_temporary_period(): void
    {
        // Timestamp for January 2023 (temporary reduced VAT period)
        $timestamp = Carbon::create(2023, 1, 15, 10, 0, 0, 'UTC')->timestamp;
        $this->fakeEntsoe([[$timestamp, 5.0]]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.10, $spotPrice->vat_rate);
    }

    /**
     * Test command uses upsert to avoid duplicates (ignores existing records).
     */
    public function test_command_uses_upsert_to_avoid_duplicates(): void
    {
        // Create an existing record
        SpotPriceHour::create([
            'region' => 'FI',
            'timestamp' => 1705644000,
            'utc_datetime' => Carbon::createFromTimestamp(1705644000, 'UTC'),
            'price_without_tax' => 4.0, // Original price
            'vat_rate' => 0.24,
        ]);

        $this->fakeEntsoe([[1705644000, 6.0]]); // Different price from API

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        // Original record should remain unchanged (insertOrIgnore behavior)
        $spotPrice = SpotPriceHour::where('timestamp', 1705644000)->first();
        $this->assertEquals(4.0, $spotPrice->price_without_tax);
        $this->assertEquals(1, SpotPriceHour::count());
    }

    /**
     * Test command fetches correct date range (today and tomorrow), fixed in the payload header.
     */
    public function test_command_fetches_today_and_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 19, 15, 0, 0, 'Europe/Helsinki'));
        $this->fakeEntsoe([]);

        $this->assertSame(0, Artisan::call('spot:fetch', ['--fetch-only' => true]));

        // Today at midnight Helsinki time through the day after tomorrow at midnight, in UTC.
        Http::assertSent(fn (Request $request) => $request['periodStart'] === '202601182200'
            && $request['periodEnd'] === '202601202200'
            && $request['in_Domain'] === '10YFI-1--------U'
            && $request['documentType'] === 'A44');
        $header = json_decode(strtok($this->decompressedPayload($this->lastJsonLine(Artisan::output())['payload']['path']), "\n"), true);
        $this->assertSame(['period_start' => '2026-01-18T22:00:00Z', 'period_end' => '2026-01-20T22:00:00Z'], $header['facts']);
        $this->assertSame([], $header['options']);
    }

    /**
     * Test command handles API errors gracefully.
     */
    public function test_command_handles_api_errors(): void
    {
        Sleep::fake();
        Http::fake([self::ENTSOE_URL => Http::response('Server Error', 500)]);

        $this->assertSame(1, Artisan::call('spot:fetch'));
        $this->assertSame(['status' => 'failed', 'step' => 'fetch', 'error' => 'fetch_failed'], $this->lastJsonLine(Artisan::output()));
        $this->assertSame([], glob(config('import_payloads.path').'/entsoe-day-ahead/*/*'), 'A failed fetch leaves no payload.');
    }

    /**
     * Test command handles API connection timeouts gracefully after service retries are exhausted.
     */
    public function test_command_handles_connection_timeouts(): void
    {
        Sleep::fake();
        $this->captureSentryIssues();
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 30004 milliseconds with 0 bytes received for https://web-api.tp.entsoe.eu/api?securityToken='.self::API_KEY
        ));

        $this->artisan('spot:fetch')
            ->expectsOutput('Failed to fetch spot prices from ENTSO-E API after retries.')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertExitCode(1);
        $this->assertImportIssue('spot', 'error', ['acquisition' => 1]);
        $this->assertSame(['acquisition' => 'fetch'], $this->sentryIssues[0]->getContexts()['data_fetch']['steps']);
        $this->assertStringNotContainsString(self::API_KEY, json_encode($this->sentryIssues[0]->getContexts()));
    }

    /**
     * Test command handles empty API response.
     */
    public function test_command_handles_empty_response(): void
    {
        $this->captureSentryIssues();
        $this->averageService->shouldNotReceive('calculateAllAverages');
        $this->fakeEntsoe([]);

        $this->assertSame(0, Artisan::call('spot:fetch'));

        $this->assertEquals(0, SpotPriceHour::count());
        $this->assertImportIssue('spot', 'warning', ['empty_response' => 1]);
        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(['stored', ['records' => 0]], [$line['status'], $line['counts']]);
    }

    /**
     * Test command outputs success message with record count.
     */
    public function test_command_outputs_success_message(): void
    {
        $this->fakeEntsoe([[1705644000, 5.235], [1705647600, 5.512]]);

        $this->artisan('spot:fetch')
            ->expectsOutput('Fetching spot prices from ENTSO-E API...')
            ->expectsOutput('Spot prices fetched successfully! Processed 2 records.')
            ->assertExitCode(0);
    }

    /**
     * Test command logs success with record count.
     */
    public function test_command_logs_success(): void
    {
        $this->fakeEntsoe([[1705644000, 5.235]]);
        Log::spy();

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        Log::shouldHaveReceived('info')->with('Successfully fetched spot prices', ['count' => 1])->once();
    }

    /**
     * Test command handles negative prices (can occur during high renewable production).
     */
    public function test_command_handles_negative_prices(): void
    {
        $this->fakeEntsoe([[1705644000, -1.0]]); // Negative price (c/kWh)

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', 1705644000)->first();
        $this->assertEquals(-1.0, $spotPrice->price_without_tax);
    }

    /**
     * Test command stores UTC datetime correctly.
     */
    public function test_command_stores_utc_datetime_correctly(): void
    {
        $timestamp = 1705644000; // 2024-01-19 06:00:00 UTC
        $utcDatetime = Carbon::createFromTimestamp($timestamp, 'UTC');
        $this->fakeEntsoe([[$timestamp, 5.0]]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals($utcDatetime->toDateTimeString(), $spotPrice->utc_datetime->toDateTimeString());
    }

    public function test_hourly_schedule_limits_orphaned_overlap_lock_to_sixty_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => preg_match("/spot:fetch(['\"\s]|$)/", (string) $event->command) === 1);

        $this->assertCount(1, $events);

        $event = $events->first();

        $this->assertSame('0 * * * *', $event->expression);
        $this->assertSame('Europe/Helsinki', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    /**
     * Test command processes multiple hours correctly.
     */
    public function test_command_processes_multiple_hours(): void
    {
        $points = [];
        for ($i = 0; $i < 24; $i++) {
            $points[] = [Carbon::create(2026, 1, 19, $i, 0, 0, 'UTC')->timestamp, 5.0 + ($i * 0.1)];
        }
        $this->fakeEntsoe($points);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $this->assertEquals(24, SpotPriceHour::count());
    }

    public function test_repeated_fetches_never_start_social_publication(): void
    {
        $this->fakeEntsoe([[Carbon::create(2026, 1, 19, 10, 0, 0, 'UTC')->timestamp, 5.0]]);

        $videoService = Mockery::mock(SpotPriceVideoService::class);
        $videoService->shouldNotReceive('getDailyVideoData');
        $this->app->instance(SpotPriceVideoService::class, $videoService);

        $postFast = Mockery::mock(PostFastService::class);
        $postFast->shouldNotReceive('uploadVideo');
        $postFast->shouldNotReceive('schedulePosts');
        $this->app->instance(PostFastService::class, $postFast);

        $this->artisan('spot:fetch')->assertExitCode(0);
        $this->artisan('spot:fetch')->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertSame(0, SpotSocialPublication::count());
        $this->assertSame(1, SpotPriceHour::count());
    }

    /**
     * Test VAT rate boundary: September 1, 2024 should have 25.5% VAT.
     */
    public function test_vat_rate_boundary_september_2024(): void
    {
        // September 1, 2024 00:00 Helsinki time
        $timestamp = Carbon::create(2024, 9, 1, 0, 0, 0, 'Europe/Helsinki')
            ->setTimezone('UTC')->timestamp;
        $this->fakeEntsoe([[$timestamp, 5.0]]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.255, $spotPrice->vat_rate);
    }

    /**
     * Test VAT rate boundary: August 31, 2024 should have 24% VAT.
     */
    public function test_vat_rate_boundary_august_2024(): void
    {
        // August 31, 2024 23:00 Helsinki time (last hour before increase)
        $timestamp = Carbon::create(2024, 8, 31, 23, 0, 0, 'Europe/Helsinki')
            ->setTimezone('UTC')->timestamp;
        $this->fakeEntsoe([[$timestamp, 5.0]]);

        $this->artisan('spot:fetch')
            ->assertExitCode(0);

        $spotPrice = SpotPriceHour::where('timestamp', $timestamp)->first();
        $this->assertEquals(0.24, $spotPrice->vat_rate);
    }

    /**
     * D3: the configured securityToken is sent to ENTSO-E but appears nowhere in the payload file.
     * The staged D1/D2/D6/S9 acceptance tests run on the real averages service in
     * FetchSpotStagedImportTest.
     */
    public function test_the_security_token_never_reaches_the_payload_file(): void
    {
        $this->fakeEntsoe([[1705644000, 5.0]]);

        $this->assertSame(0, Artisan::call('spot:fetch', ['--fetch-only' => true]));

        Http::assertSent(fn (Request $request) => $request['securityToken'] === self::API_KEY);
        $payload = $this->decompressedPayload($this->lastJsonLine(Artisan::output())['payload']['path']);
        $this->assertStringContainsString('"documentType":"A44"', $payload);
        $this->assertStringNotContainsString(self::API_KEY, $payload);
        $this->assertStringNotContainsString('securityToken', $payload);
    }
}
