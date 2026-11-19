<?php

namespace Tests\Feature;

use App\Console\Commands\FetchSpot;
use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\CapturesSentryIssues;
use Tests\Concerns\FakesEntsoeDayAhead;
use Tests\TestCase;

/**
 * Staged acceptance of spot:fetch (D1, D2, D6, S9, S21) through the real chain: the real
 * SpotPriceImporter and SpotPriceAverageService on the test database. Only ENTSO-E HTTP, the
 * clock and the queued statistics cache warm (the after-commit boundary) are faked.
 */
class FetchSpotStagedImportTest extends TestCase
{
    use CapturesSentryIssues;
    use FakesEntsoeDayAhead;
    use RefreshDatabase;

    private const API_KEY = 'entsoe-test-secret-7731';

    private const STORE_LOCK = 'spot_store_v1:FI';

    private const STAGED_STORE_LOCK = 'staged_import_store_v1:spot:fetch';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.entsoe.api_key' => self::API_KEY]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<array{0: int, 1: float, 2?: int}>  $points */
    private function fakeEntsoe(array $points): void
    {
        Http::swap(new Factory);
        Http::fake([self::ENTSOE_URL => Http::response($this->dayAheadXml($points))]);
    }

    private function preventHttp(): void
    {
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
    }

    /** @return list<array<string, mixed>> */
    private function averageRows(): array
    {
        return DB::table('spot_price_averages')->orderBy('period_type')->orderBy('period_start')->get()
            ->map(fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at'])))
            ->all();
    }

    /**
     * Period type and start date => [VAT0 mean, hour count].
     *
     * @return array<string, array{0: float, 1: int}>
     */
    private function averages(): array
    {
        return SpotPriceAverage::query()->orderBy('period_type')->get()
            ->mapWithKeys(fn (SpotPriceAverage $row) => [
                $row->period_type.' '.$row->period_start->toDateString() => [round((float) $row->avg_price_without_tax, 4), $row->hours_count],
            ])->all();
    }

    /**
     * The five FI averages one run on 2026-11-19 (Helsinki) writes over its stored hours.
     *
     * @return array<string, array{0: float, 1: int}>
     */
    private function expectedAverages(float $mean, int $hours): array
    {
        return [
            'daily 2026-11-19' => [$mean, $hours],
            'monthly 2026-11-01' => [$mean, $hours],
            'rolling_30d_local 2026-11-19' => [$mean, $hours],
            'rolling_365d_local 2026-11-19' => [$mean, $hours],
            'yearly 2026-01-01' => [$mean, $hours],
        ];
    }

    /** D1: default mode and --fetch-only then --from-payload write the same price and average rows. */
    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows_and_averages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        // Quarters 4, 5, 6, 7 give the 10:00 hourly mean 5.5; the direct 11:00 hour is 7.5.
        $this->fakeEntsoe([...$this->quarterPoints('2026-11-19 10:00', 4.0), [Carbon::parse('2026-11-19 11:00', 'UTC')->timestamp, 7.5]]);

        $this->assertSame(0, Artisan::call('spot:fetch'));
        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(['stored', 'store', true, ['records' => 5]], [$line['status'], $line['step'], $line['complete'], $line['counts']]);
        $defaultRows = $this->spotRows();
        $defaultAverages = $this->averageRows();
        $this->assertCount(6, $defaultRows, 'Four quarters, their hourly mean and one direct hour.');
        $this->assertSame($this->expectedAverages(6.5, 2), $this->averages());
        Queue::assertPushed(WarmContractPriceStatisticsCache::class, 1);

        SpotPriceQuarter::query()->delete();
        SpotPriceHour::query()->delete();
        SpotPriceAverage::query()->delete();
        Cache::flush(); // the warm job's ShouldBeUnique lock
        Queue::fake();
        $this->assertSame(0, Artisan::call('spot:fetch', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine(Artisan::output())['payload'];
        $this->assertSame([[], []], [$this->spotRows(), $this->averageRows()], 'Fetch only writes no rows.');
        Queue::assertNothingPushed();

        $this->preventHttp();
        $this->assertSame(0, Artisan::call('spot:fetch', [
            '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256'],
        ]));
        Http::assertNothingSent();
        $this->assertSame($defaultRows, $this->spotRows());
        $this->assertSame($defaultAverages, $this->averageRows());
        Queue::assertPushed(WarmContractPriceStatisticsCache::class, 1);
    }

    /**
     * D2, D6 and S21: an average write that fails after the price inserts rolls back the whole
     * store, so neither price nor average rows of the run exist; safe SQL facts are reported, the
     * payload stays, no cache warm is queued, and a later replay writes rows and averages.
     */
    public function test_failed_average_write_rolls_back_prices_and_averages_and_replays_without_http(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $this->captureSentryIssues();
        $this->fakeEntsoe($this->quarterPoints('2026-11-19 10:00', 4.0));
        DB::statement("CREATE TRIGGER fail_spot_averages BEFORE INSERT ON spot_price_averages BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('spot:fetch'));
        $output = Artisan::output();
        $payloads = glob(config('import_payloads.path').'/entsoe-day-ahead/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
            'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'spot_price_averages',
        ], $this->lastJsonLine($output));
        $this->assertSame([], $this->spotRows(), 'The price rows of the failed store are rolled back (S21).');
        $this->assertSame([], $this->averageRows(), 'No average row of the run exists.');
        Queue::assertNothingPushed();
        $this->assertImportIssue('spot', 'error', ['save_or_post_import' => 1]);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame(['sqlstate' => '23000', 'driver_code' => 19, 'table' => 'spot_price_averages'], $context['store_error']);
        foreach (['forced failure', '5.5', self::API_KEY] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
            $this->assertStringNotContainsString($binding, json_encode($context));
        }

        DB::statement('DROP TRIGGER fail_spot_averages'); // the fix
        $this->preventHttp();
        $this->assertSame(0, Artisan::call('spot:fetch', ['--from-payload' => $payloads[0]]));
        Http::assertNothingSent();
        $this->assertSame(4, SpotPriceQuarter::count());
        $this->assertSame(1, SpotPriceHour::count());
        $this->assertSame($this->expectedAverages(5.5, 1), $this->averages());
        Queue::assertPushed(WarmContractPriceStatisticsCache::class, 1);
    }

    /**
     * Replay stores only the newest payload: an older same-day payload is refused (superseded)
     * while a newer payload exists, and writes neither price rows nor averages.
     */
    public function test_an_older_same_day_payload_is_refused_and_keeps_rows_and_averages(): void
    {
        $hour = Carbon::parse('2026-11-19 10:00', 'UTC')->timestamp;
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $this->fakeEntsoe([[$hour, 4.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch', ['--fetch-only' => true]));
        $older = $this->lastJsonLine(Artisan::output())['payload'];

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $this->fakeEntsoe([[$hour, 6.0], [$hour + 3600, 6.5]]);
        $this->assertSame(0, Artisan::call('spot:fetch'));
        $rows = $this->spotRows();
        $averages = $this->averageRows();
        $this->assertSame($this->expectedAverages(6.25, 2), $this->averages());

        Carbon::setTestNow(Carbon::parse('2026-11-19 14:00:00', 'UTC'));
        $this->preventHttp();
        $this->assertSame(1, Artisan::call('spot:fetch', ['--from-payload' => $older['path']]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $older['path'], 'payload_sha256' => $older['sha256'],
        ], $this->lastJsonLine(Artisan::output()));
        $this->assertSame($rows, $this->spotRows());
        $this->assertSame($averages, $this->averageRows());
    }

    /**
     * A store waits for the FI store lock; while another store holds it past the wait, it writes
     * neither prices nor averages and fails with the closed error store_busy. The payload stays.
     */
    public function test_a_store_that_cannot_get_the_store_lock_writes_nothing_and_is_busy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $this->fakeEntsoe($this->quarterPoints('2026-11-19 10:00', 4.0));
        $this->assertSame(0, Artisan::call('spot:fetch', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine(Artisan::output())['payload'];

        $holder = Cache::lock(self::STORE_LOCK, 300);
        $this->assertTrue($holder->get());
        Sleep::fake(syncWithCarbon: true);
        $this->captureSentryIssues();
        $this->preventHttp();
        $this->assertSame(1, Artisan::call('spot:fetch', ['--from-payload' => $payload['path']]));

        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(['failed', 'store', 'store_busy', ['records' => 4]], [$line['status'], $line['step'], $line['error'], $line['counts']]);
        $this->assertSame([[], []], [$this->spotRows(), $this->averageRows()]);
        $this->assertFileExists($payload['path']);
        Queue::assertNothingPushed();
        $this->assertImportIssue('spot', 'error', ['store_busy' => 1]);

        $holder->release();
        $this->assertSame(0, Artisan::call('spot:fetch', ['--from-payload' => $payload['path']]));
        $this->assertSame($this->expectedAverages(5.5, 1), $this->averages());
    }

    /**
     * Store serialization with an older replay: replay A (06:00 payload, hour 10:00 = 4.0)
     * starts while another process holds the staged store lock of spot:fetch, and that process
     * (a scheduled run, B: 10:00 = 6.0 and 11:00 = 6.5) fetches and commits its prices and
     * averages before it lets go. A's newest-payload check runs under that lock, so it sees B's
     * newer payload and refuses A (superseded) without a write. The other process is simulated in
     * one PHPUnit process: A's first lock wait (a faked sleep) runs B with its own command
     * instance after releasing the lock. Without the lock, A would pass the check before B's
     * payload existed and recalculate the averages from a snapshot without B.
     */
    public function test_an_older_replay_waiting_for_a_newer_store_is_superseded(): void
    {
        $hour = Carbon::parse('2026-11-19 10:00', 'UTC')->timestamp;
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $this->fakeEntsoe([[$hour, 4.0]]);
        $this->assertSame(0, Artisan::call('spot:fetch', ['--fetch-only' => true]));
        $replay = $this->lastJsonLine(Artisan::output())['payload'];

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $this->fakeEntsoe([[$hour, 6.0], [$hour + 3600, 6.5]]);
        $storeB = $this->storeBDuringTheFirstLockWait(fn (): null => null);

        $this->assertSame(1, Artisan::call('spot:fetch', ['--from-payload' => $replay['path']]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $replay['path'], 'payload_sha256' => $replay['sha256'],
        ], $this->lastJsonLine(Artisan::output()));
        $this->assertSame(0, $storeB->exitCode, 'B stored while A waited for the lock.');
        $this->assertEqualsWithDelta(6.0, SpotPriceHour::query()->where('timestamp', $hour)->value('price_without_tax'), 0.0001);
        $this->assertSame($this->expectedAverages(6.25, 2), $this->averages());
    }

    /**
     * Store serialization of two default-mode runs: A fetches at 06:00 (10:00 = 4.0) and waits
     * for the staged store lock while B fetches at 12:00 (10:00 = 6.0, 11:00 = 6.5) and commits.
     * Default mode has no newest-payload check, so A stores after B: it inserts nothing new
     * (insert-only) and recalculates the averages over B's committed rows. Without the lock, A's
     * averages would come from a snapshot taken without B.
     */
    public function test_a_default_mode_store_waits_for_a_running_store_and_averages_over_its_rows(): void
    {
        $hour = Carbon::parse('2026-11-19 10:00', 'UTC')->timestamp;
        Carbon::setTestNow(Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $this->fakeEntsoe([[$hour, 4.0]]);
        // A has fetched before it waits, so B's clock and feed are set inside the wait.
        $storeB = $this->storeBDuringTheFirstLockWait(function () use ($hour): void {
            Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
            $this->fakeEntsoe([[$hour, 6.0], [$hour + 3600, 6.5]]);
        });

        $this->assertSame(0, Artisan::call('spot:fetch'));

        $this->assertSame(0, $storeB->exitCode, 'B stored while A waited for the lock.');
        $this->assertEqualsWithDelta(6.0, SpotPriceHour::query()->where('timestamp', $hour)->value('price_without_tax'), 0.0001);
        $this->assertSame($this->expectedAverages(6.25, 2), $this->averages(), 'A averaged over B\'s committed rows.');
    }

    /**
     * Hold the staged store lock of spot:fetch; on the first faked sleep (a store's lock wait),
     * run $prepare, release the lock and run a default-mode spot:fetch as another process would.
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
            $command = $this->app->make(FetchSpot::class);
            $command->setLaravel($this->app);
            // B's after-commit cache warm calls another command through the console application.
            $command->setApplication(Artisan::all()['spot:fetch']->getApplication());
            $storeB->exitCode = $command->run(new ArrayInput([]), new BufferedOutput);
        });

        return $storeB;
    }
}
