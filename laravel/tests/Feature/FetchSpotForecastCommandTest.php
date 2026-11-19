<?php

namespace Tests\Feature;

use App\Livewire\SpotPrice;
use App\Models\SpotPriceForecast;
use App\Models\SpotPriceForecastRun;
use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class FetchSpotForecastCommandTest extends TestCase
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

    public function test_fetch_spot_forecast_imports_nordpool_predict_fi_feed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23 12:00:00', 'UTC'));
        $url = config('services.spot_forecasts.nordpool_predict_fi.url');
        $firstUtc = Carbon::parse('2026-05-24 00:00:00', 'UTC');
        $secondUtc = $firstUtc->copy()->addHour();

        Http::fake([
            $url => Http::response([
                [$firstUtc->timestamp * 1000, 10.50],
                [$secondUtc->timestamp * 1000, 12.25],
            ]),
        ]);

        $this->artisan('spot:fetch-forecast')
            ->expectsOutput('Fetching spot price forecasts from nordpool-predict-fi...')
            ->assertExitCode(0);

        $this->assertSame(2, SpotPriceForecast::count());

        $forecast = SpotPriceForecast::query()
            ->where('timestamp', $firstUtc->timestamp)
            ->firstOrFail();

        $this->assertSame(SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI, $forecast->source);
        $this->assertSame(self::REGION, $forecast->region);
        $this->assertEquals(10.50, $forecast->price_with_tax);
        $this->assertEqualsWithDelta(10.50 / 1.255, $forecast->price_without_tax, 0.0001);
        $this->assertSame('https://github.com/vividfog/nordpool-predict-fi', $forecast->source_url);
    }

    public function test_spot_price_page_shows_forecasts_with_source_attribution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23 12:00:00', self::TIMEZONE));
        Cache::flush();

        $this->createFullDayActualPrices(2026, 5, 23);
        $this->createForecastPrice(Carbon::parse('2026-05-24 00:00:00', self::TIMEZONE), 8.75);
        $this->createForecastPrice(Carbon::parse('2026-05-24 01:00:00', self::TIMEZONE), 7.10);

        $component = Livewire::test(SpotPrice::class)
            ->assertSee('Ennuste')
            ->assertSee('nordpool-predict-fi')
            ->assertSee('Ennusteen lähde');

        $forecastDays = $component->viewData('forecastPricesGroupedByDate');

        $this->assertNotEmpty($forecastDays);
        $this->assertSame('Huomenna', $forecastDays[0]['label']);
        $this->assertSame(8.75, $forecastDays[0]['prices'][0]['price_with_vat']);

        $dayStrips = $component->viewData('dayStrips');
        $this->assertNotEmpty($dayStrips);
        $this->assertContains('Huomenna', array_column($dayStrips, 'label'));
    }

    private function createFullDayActualPrices(int $year, int $month, int $day): void
    {
        for ($hour = 0; $hour < 24; $hour++) {
            $helsinkiTime = Carbon::create($year, $month, $day, $hour, 0, 0, self::TIMEZONE);
            $utcTime = $helsinkiTime->copy()->setTimezone('UTC');

            SpotPriceHour::create([
                'region' => self::REGION,
                'timestamp' => $utcTime->timestamp,
                'utc_datetime' => $utcTime,
                'price_without_tax' => 5.0,
                'vat_rate' => 0.255,
            ]);
        }
    }

    private function createForecastPrice(Carbon $helsinkiTime, float $priceWithTax): void
    {
        $utcTime = $helsinkiTime->copy()->setTimezone('UTC');

        SpotPriceForecast::create([
            'source' => SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI,
            'region' => self::REGION,
            'timestamp' => $utcTime->timestamp,
            'utc_datetime' => $utcTime,
            'price_with_tax' => $priceWithTax,
            'vat_rate' => 0.255,
            'source_url' => 'https://github.com/vividfog/nordpool-predict-fi',
            'fetched_at' => Carbon::now('UTC'),
            'metadata' => ['test' => true],
        ]);
    }

    public function test_each_distinct_feed_is_kept_as_an_append_only_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 06:00:00', 'UTC'));
        $url = config('services.spot_forecasts.nordpool_predict_fi.url');
        $first = Carbon::parse('2026-11-02 00:00:00', 'UTC');
        $second = $first->copy()->addHour();

        Http::fakeSequence($url)
            ->push([[$second->timestamp * 1000, 9.0], [$first->timestamp * 1000, 8.0]])
            ->push([[$second->timestamp * 1000, 9.0], [$first->timestamp * 1000, 8.0]])
            ->push([[$first->timestamp * 1000, 11.5], [$second->timestamp * 1000, 12.0]]);

        $this->artisan('spot:fetch-forecast')->assertExitCode(0);
        $this->artisan('spot:fetch-forecast')->assertExitCode(0);
        $this->assertSame(1, SpotPriceForecastRun::count(), 'An unchanged feed must not add a run.');

        $this->artisan('spot:fetch-forecast')->assertExitCode(0);

        $runs = SpotPriceForecastRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertEquals([[$first->timestamp, 8.0], [$second->timestamp, 9.0]], $runs[0]->points);
        $this->assertEquals([[$first->timestamp, 11.5], [$second->timestamp, 12.0]], $runs[1]->points);
        $this->assertSame(2, $runs[1]->point_count);
        $this->assertSame('2026-11-02 00:00:00', $runs[1]->first_utc_datetime);
        $this->assertSame('2026-11-02 01:00:00', $runs[1]->last_utc_datetime);

        // The display table still holds only the latest value per hour.
        $this->assertSame(2, SpotPriceForecast::count());
        $this->assertEquals(11.5, SpotPriceForecast::query()->where('timestamp', $first->timestamp)->value('price_with_tax'));
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $feed = $this->fakeFeed([[1, 8.0], [2, 9.5]]);

        $this->assertSame(0, Artisan::call('spot:fetch-forecast'));
        $defaultRows = $this->storedRows();
        $line = $this->lastJsonLine();
        $this->assertSame('stored', $line['status']);
        $this->assertSame(['records' => 2, 'runs_inserted' => 1], $line['counts']);

        SpotPriceForecast::query()->delete();
        SpotPriceForecastRun::query()->delete();
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, SpotPriceForecast::count() + SpotPriceForecastRun::count(), 'Fetch only writes no rows.');

        // The store runs two hours later and keeps the fetch time (S5); the feed hash is the body's.
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'UTC'));
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', [
            '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256'],
        ]));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        // UTC wall-clock text, as before the staged import.
        $this->assertSame('2026-11-19 06:00:00', DB::table('spot_price_forecast_runs')->value('fetched_at'));
        $this->assertSame('2026-11-19 06:00:00', DB::table('spot_price_forecasts')->value('fetched_at'));
        $this->assertSame(hash('sha256', $feed), SpotPriceForecastRun::query()->value('feed_hash'));
        $this->assertSame(hash('sha256', $feed), SpotPriceForecast::query()->first()->metadata['feed_hash']);
        $this->assertSame(config('services.spot_forecasts.nordpool_predict_fi.url'), SpotPriceForecast::query()->first()->metadata['feed_url']);
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $feed = $this->fakeFeed([[1, 8.0], [2, 9.5]]);
        Log::spy();
        Cache::put('spot_price:forecast:nordpool_predict_fi:v1', 'cached');
        // The run insert fails after the display upsert succeeded inside the same transaction.
        DB::statement("CREATE TRIGGER fail_forecast_runs BEFORE INSERT ON spot_price_forecast_runs BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('spot:fetch-forecast'));
        $output = Artisan::output();
        $line = $this->lastJsonLine($output);

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'spot_price_forecast_runs'], $line);
        foreach ([hash('sha256', $feed), 'forced failure', '9.5'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, SpotPriceForecast::count() + SpotPriceForecastRun::count(), 'A failed store writes no row of the run (S21).');
        $this->assertSame('cached', Cache::get('spot_price:forecast:nordpool_predict_fi:v1'), 'After-commit work does not run.');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($feed): bool {
            $this->assertSame('Import failed: spot:fetch-forecast', $message);
            $this->assertSame(['23000', 19, 'spot_price_forecast_runs', 'store'], [$context['sqlstate'], $context['driver_code'], $context['table'], $context['step']]);
            $this->assertStringNotContainsString(hash('sha256', $feed), json_encode($context));
            $this->assertStringNotContainsString('forced failure', json_encode($context));

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/nordpool-predict-fi/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_forecast_runs'); // the fix
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--from-payload' => $payloads[0]]));
        $this->assertSame(2, SpotPriceForecast::count());
        $this->assertSame(1, SpotPriceForecastRun::count());
        $this->assertNull(Cache::get('spot_price:forecast:nordpool_predict_fi:v1'));
        Http::assertNothingSent();
    }

    public function test_a_failed_fetch_logs_the_class_only_and_leaves_no_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        config(['services.spot_forecasts.nordpool_predict_fi.retry_attempts' => 1]);
        Http::fake([config('services.spot_forecasts.nordpool_predict_fi.url') => Http::response('secret upstream text', 502)]);
        Log::spy();

        $this->assertSame(1, Artisan::call('spot:fetch-forecast'));

        $this->assertSame(['status' => 'failed', 'step' => 'fetch', 'error' => 'fetch_failed'], $this->lastJsonLine());
        $this->assertSame([], glob(config('import_payloads.path').'/nordpool-predict-fi/2026-11-19/*'));
        Log::shouldHaveReceived('error')->once()->with('Import failed: spot:fetch-forecast', [
            'command' => 'spot:fetch-forecast', 'step' => 'fetch', 'error' => 'fetch_failed',
            'exception_class' => RequestException::class,
        ]);
    }

    /**
     * S9: this source is not date-bound. An older payload from the same day is accepted. Its run
     * row already exists (unique feed hash), so the replay inserts no run; the display table takes
     * the replayed values until the next scheduled fetch, as a manual replay is meant to.
     */
    public function test_an_older_same_day_payload_is_accepted_and_adds_no_run_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $this->fakeFeed([[1, 8.0]]);
        Artisan::call('spot:fetch-forecast');
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
        $this->fakeFeed([[1, 11.0]]);
        Artisan::call('spot:fetch-forecast');
        $this->assertSame(2, SpotPriceForecastRun::count());

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--from-payload' => $older]));

        $this->assertSame(['records' => 1, 'runs_inserted' => 0], $this->lastJsonLine()['counts']);
        $this->assertSame(2, SpotPriceForecastRun::count());
        $forecast = SpotPriceForecast::query()->sole();
        $this->assertEquals(8.0, $forecast->price_with_tax);
        $this->assertSame('2026-11-19 04:00:00', DB::table('spot_price_forecasts')->value('fetched_at'));
    }

    /** @param list<array{0: int, 1: float}> $hourPrices  hour offsets from 2026-11-20 00:00 UTC */
    private function fakeFeed(array $hourPrices): string
    {
        $base = Carbon::parse('2026-11-20 00:00:00', 'UTC')->timestamp;
        $body = json_encode(array_map(fn (array $point) => [($base + $point[0] * 3600) * 1000, $point[1]], $hourPrices));
        Http::swap(new Factory);
        Http::fake([config('services.spot_forecasts.nordpool_predict_fi.url') => Http::response($body)]);

        return $body;
    }

    /** @return array<int, array<string, mixed>> */
    private function storedRows(): array
    {
        $strip = fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at']));

        return [
            DB::table('spot_price_forecasts')->orderBy('timestamp')->get()->map($strip)->all(),
            DB::table('spot_price_forecast_runs')->orderBy('id')->get()->map($strip)->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
