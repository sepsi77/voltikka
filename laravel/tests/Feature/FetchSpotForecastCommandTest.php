<?php

namespace Tests\Feature;

use App\Console\Commands\FetchSpotForecast;
use App\Livewire\SpotPrice;
use App\Models\SpotPriceForecast;
use App\Models\SpotPriceForecastRun;
use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class FetchSpotForecastCommandTest extends TestCase
{
    use RefreshDatabase;

    private const REGION = 'FI';

    private const TIMEZONE = 'Europe/Helsinki';

    private const STORE_LOCK = 'spot_forecast_store_v1:nordpool_predict_fi:FI';

    private const STAGED_STORE_LOCK = 'staged_import_store_v1:spot:fetch-forecast';

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
        $this->assertSame(['records' => 2, 'display_rows' => 2, 'runs_inserted' => 1], $line['counts']);

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
        $payloads = glob(config('import_payloads.path').'/nordpool-predict-fi/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
            'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'spot_price_forecast_runs',
        ], $line);
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
     * S9: this source is not date-bound. While the newer feed's payload exists, an older replay
     * is refused (superseded) and writes nothing. Without that file (for example removed by
     * retention or lost), the display check alone protects the display: the replay archives its
     * vintage in the append-only run table, but the display table, the page and its cache keep
     * the newer feed (11 c/kWh, not 8). The display check also covers a default-mode store that
     * overlaps a newer one, which the payload rule does not judge.
     */
    public function test_an_older_same_day_payload_archives_its_run_and_keeps_the_newer_display(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $this->fakeFeed([[1, 8.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--fetch-only' => true]));
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
        $this->fakeFeed([[1, 11.0], [2, 12.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast'));
        $newer = $this->lastJsonLine()['payload']['path'];
        $display = $this->storedRows()[0];
        $page = $this->forecastOnPage();
        $this->assertSame([11.0, 12.0], $page[0]);

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Cache::put('spot_price:forecast:nordpool_predict_fi:v1', 'cached page data');
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(1, Artisan::call('spot:fetch-forecast', ['--from-payload' => $older]));
        $refused = $this->lastJsonLine();
        $this->assertSame(['payload_invalid', 'superseded'], [$refused['error'], $refused['reason']]);
        $this->assertSame(1, SpotPriceForecastRun::count(), 'A refused replay writes nothing.');

        $this->removePayload($newer);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--from-payload' => $older]));
        Http::assertNothingSent();

        $this->assertSame(['records' => 1, 'display_rows' => 0, 'runs_inserted' => 1], $this->lastJsonLine()['counts']);
        $this->assertSame($display, $this->storedRows()[0], 'The newer display rows, including its trailing hour, stay.');
        $this->assertSame(['2026-11-19 10:00:00'], DB::table('spot_price_forecasts')->distinct()->pluck('fetched_at')->all());
        $runs = SpotPriceForecastRun::query()->orderBy('fetched_at')->get();
        $this->assertCount(2, $runs);
        $this->assertSame('2026-11-19 04:00:00', DB::table('spot_price_forecast_runs')->orderBy('fetched_at')->value('fetched_at'), 'The older vintage keeps its fetch time.');
        $this->assertEquals([[Carbon::parse('2026-11-20 01:00:00', 'UTC')->timestamp, 8.0]], $runs[0]->points);
        $this->assertSame('cached page data', Cache::get('spot_price:forecast:nordpool_predict_fi:v1'), 'The display did not change, so its cache stays.');

        $this->assertSame($page, $this->forecastOnPage(), 'The page shows the newer values and update time.');
    }

    /**
     * A feed that returns unchanged adds no run row (unique feed hash) but refreshes the display
     * rows' fetch time, so a replay of a payload fetched between the two still counts as older
     * for the display check (the newest payload file is removed, so the payload rule does not
     * refuse the replay first).
     */
    public function test_a_replay_older_than_a_refetched_unchanged_feed_keeps_the_display(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $this->fakeFeed([[1, 11.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast'));

        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $this->fakeFeed([[1, 8.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast'));
        $between = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
        $this->fakeFeed([[1, 11.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast'));
        $refetched = $this->lastJsonLine();
        $this->assertSame(['records' => 1, 'display_rows' => 1, 'runs_inserted' => 0], $refetched['counts']);
        // Without the 10:00 payload file only the display check judges the replay.
        $this->removePayload($refetched['payload']['path']);

        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--from-payload' => $between]));

        $this->assertSame(['records' => 1, 'display_rows' => 0, 'runs_inserted' => 0], $this->lastJsonLine()['counts']);
        $this->assertEquals(11.0, SpotPriceForecast::query()->sole()->price_with_tax);
        $this->assertSame('2026-11-19 10:00:00', DB::table('spot_price_forecasts')->value('fetched_at'));
    }

    /**
     * A store waits for the source/region store lock; while another store holds it past the
     * wait, it writes neither table and fails with the closed error store_busy. The payload stays.
     */
    public function test_a_store_that_cannot_get_the_store_lock_writes_nothing_and_is_busy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
        $this->fakeFeed([[1, 11.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload']['path'];

        $holder = Cache::lock(self::STORE_LOCK, 300);
        $this->assertTrue($holder->get());
        Sleep::fake(syncWithCarbon: true);
        Log::spy();
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(1, Artisan::call('spot:fetch-forecast', ['--from-payload' => $payload]));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'store', 'store_busy'], [$line['status'], $line['step'], $line['error']]);
        $this->assertSame(['records' => 1, 'display_rows' => 0, 'runs_inserted' => 0], $line['counts']);
        $this->assertSame([[], []], $this->storedRows());
        $this->assertFileExists($payload);
        Log::shouldHaveReceived('error')->once()->with('Import failed: spot:fetch-forecast', [
            'command' => 'spot:fetch-forecast', 'step' => 'store', 'error' => 'store_busy', 'exception_class' => null,
        ]);

        $holder->release();
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--from-payload' => $payload]));
        $this->assertSame(['records' => 1, 'display_rows' => 1, 'runs_inserted' => 1], $this->lastJsonLine()['counts']);
    }

    /**
     * PRC-002 under concurrency, older replay: replay A (04:00, [8]) starts while another
     * process holds the staged store lock of spot:fetch-forecast, and that process fetches and
     * commits the newer feed B (12:00, [11, 12]) before it lets go. A's newest-payload check runs
     * under that lock, so it sees B's payload and refuses A (superseded): the display stays
     * [11, 12] and only B's run is archived. The other process is simulated in one PHPUnit
     * process: A's first lock wait (a faked sleep) runs B in default mode with its own command
     * instance after releasing the lock. Without the lock, A would pass the check before B's
     * payload existed.
     */
    public function test_an_older_replay_waiting_for_a_newer_store_is_superseded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $this->fakeFeed([[1, 8.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch-forecast', ['--fetch-only' => true]));
        $older = $this->lastJsonLine()['payload'];

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $this->fakeFeed([[1, 11.0], [2, 12.0]]);
        $storeB = $this->storeBDuringTheFirstLockWait(fn (): null => null);

        $this->assertSame(1, Artisan::call('spot:fetch-forecast', ['--from-payload' => $older['path']]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $older['path'], 'payload_sha256' => $older['sha256'],
        ], $this->lastJsonLine());
        $this->assertSame(0, $storeB->exitCode, 'B stored while A waited for the lock.');
        $this->assertEquals([11.0, 12.0], SpotPriceForecast::query()->orderBy('timestamp')->pluck('price_with_tax')->all());
        $this->assertSame(['2026-11-19 12:00:00'], DB::table('spot_price_forecast_runs')->pluck('fetched_at')->all(), 'A refused writes no run.');
        $this->assertSame([11.0, 12.0], $this->forecastOnPage()[0], 'The page shows B.');
    }

    /**
     * PRC-002 under concurrency, default mode: A fetches the older feed (04:00, [8]) and waits
     * for the staged store lock while B fetches the newer feed (10:00, [11, 12]) and commits.
     * Default mode has no newest-payload check, so only the display check protects the display:
     * A stores after B, archives its run and leaves B's display, provenance and page in place.
     */
    public function test_a_default_mode_store_after_a_newer_store_keeps_the_newer_display(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $this->fakeFeed([[1, 8.0]]);
        // A has fetched before it waits, so B's clock and feed are set inside the wait.
        $storeB = $this->storeBDuringTheFirstLockWait(function (): void {
            Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
            $this->fakeFeed([[1, 11.0], [2, 12.0]]);
        });

        $this->assertSame(0, Artisan::call('spot:fetch-forecast'));

        $this->assertSame(0, $storeB->exitCode, 'B stored while A waited for the lock.');
        $this->assertSame(['records' => 1, 'display_rows' => 0, 'runs_inserted' => 1], $this->lastJsonLine()['counts']);
        $this->assertEquals([11.0, 12.0], SpotPriceForecast::query()->orderBy('timestamp')->pluck('price_with_tax')->all());
        $this->assertSame(['2026-11-19 10:00:00'], DB::table('spot_price_forecasts')->distinct()->pluck('fetched_at')->all(), 'The display keeps B\'s provenance.');
        $this->assertSame(['2026-11-19 04:00:00', '2026-11-19 10:00:00'], DB::table('spot_price_forecast_runs')->orderBy('fetched_at')->pluck('fetched_at')->all(), 'Both vintages are archived.');
        $this->assertSame([11.0, 12.0], $this->forecastOnPage()[0], 'The page shows B.');
    }

    /**
     * Hold the staged store lock of spot:fetch-forecast; on the first faked sleep (a store's lock
     * wait), run $prepare, release the lock and run a default-mode store as another process would.
     *
     * @param  Closure(): void  $prepare
     * @return object{exitCode: int|null}
     */
    private function storeBDuringTheFirstLockWait(Closure $prepare): object
    {
        $holder = Cache::lock(self::STAGED_STORE_LOCK, 300);
        $this->assertTrue($holder->get());
        $storeB = (object) ['exitCode' => null];
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($holder, $prepare, $storeB): void {
            if ($storeB->exitCode !== null) {
                return;
            }
            $prepare();
            $holder->release();
            $command = $this->app->make(FetchSpotForecast::class);
            $command->setLaravel($this->app);
            $storeB->exitCode = $command->run(new ArrayInput([]), new BufferedOutput);
        });

        return $storeB;
    }

    /**
     * The forecast prices and update label that /spot-price renders, read without its cache.
     *
     * @return array{0: list<float>, 1: string|null}
     */
    private function forecastOnPage(): array
    {
        Cache::flush();
        $component = Livewire::test(SpotPrice::class);

        return [
            array_column($component->viewData('forecastPricesGroupedByDate')[0]['prices'], 'price_with_vat'),
            $component->viewData('forecastSource')['fetched_at'],
        ];
    }

    /** Delete a payload file and its .sha256 file. */
    private function removePayload(string $path): void
    {
        unlink($path);
        unlink(substr($path, 0, -strlen('.ndjson.gz')).'.sha256');
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
