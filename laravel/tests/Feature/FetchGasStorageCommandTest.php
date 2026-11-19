<?php

namespace Tests\Feature;

use App\Services\GasStorage\GieAgsiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchGasStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 8, 0, 0, 'UTC'));
        config([
            'gas_storage.api_key' => 'test-key',
            'gas_storage.request_delay_seconds' => 0,
            'gas_storage.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(string $gasDay, string $full, string $status = 'C'): array
    {
        return [
            'name' => 'EU', 'code' => 'eu', 'url' => 'eu',
            'gasDayStart' => $gasDay,
            'gasInStorage' => '1012.3456',
            'injection' => '850.12',
            'withdrawal' => '120.50',
            'netWithdrawal' => '-729.62',
            'workingGasVolume' => '1131.4022',
            'status' => $status,
            'trend' => '0.06',
            'full' => $full,
            'updatedAt' => '2026-10-31 18:30:01',
            'info' => [],
        ];
    }

    public function test_stores_daily_storage_for_each_area_with_the_key_header(): void
    {
        Http::fake([
            'agsi.gie.eu/api*' => Http::response([
                'last_page' => 1, 'total' => 2, 'dataset' => 'storage', 'gas_day' => '2026-10-30',
                'data' => [$this->day('2026-10-30', '89.47'), $this->day('2026-10-29', '89.41')],
            ]),
        ]);

        $this->artisan('gas-storage:fetch')->assertExitCode(0);

        $this->assertSame(4, DB::table('gas_storage_daily_values')->count());
        $row = DB::table('gas_storage_daily_values')->where('area_code', 'eu')->where('gas_day', '2026-10-30')->first();
        $this->assertEqualsWithDelta(89.47, $row->full_pct, 0.0001);
        $this->assertEqualsWithDelta(1012.3456, $row->gas_in_storage_twh, 0.0001);
        $this->assertEqualsWithDelta(-729.62, $row->net_withdrawal_gwh, 0.0001);
        $this->assertSame('C', $row->status);

        Http::assertSent(fn (Request $request) => $request->hasHeader('x-key', 'test-key')
            && ($request->data()['type'] ?? null) === 'eu' && $request['from'] === '2026-10-18'
            && $request['to'] === '2026-11-01' && (int) $request['size'] === 300);
        Http::assertSent(fn (Request $request) => ($request->data()['country'] ?? null) === 'de');
    }

    public function test_follows_pagination_and_upserts_corrections(): void
    {
        $run = 1;
        Http::fake(function (Request $request) use (&$run) {
            if ($run === 2) {
                return Http::response(['last_page' => 1, 'data' => [$this->day('2026-10-30', '89.10')]]);
            }

            return Http::response([
                'last_page' => 2,
                'data' => [(int) $request['page'] === 1 ? $this->day('2026-10-30', '89.00', 'E') : $this->day('2026-10-29', '88.90')],
            ]);
        });
        $this->artisan('gas-storage:fetch --area=eu')->assertExitCode(0);
        Http::assertSentCount(2);

        $run = 2;
        $this->artisan('gas-storage:fetch --area=eu')->assertExitCode(0);

        $this->assertSame(2, DB::table('gas_storage_daily_values')->count());
        $row = DB::table('gas_storage_daily_values')->where('gas_day', '2026-10-30')->first();
        $this->assertEqualsWithDelta(89.10, $row->full_pct, 0.0001);
        $this->assertSame('C', $row->status);
    }

    public function test_missing_key_fails_before_any_request(): void
    {
        config(['gas_storage.api_key' => null]);
        Http::fake();

        $this->artisan('gas-storage:fetch')
            ->expectsOutputToContain('GIE_API_KEY is not set')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_if_configured_skips_with_success_and_no_request_when_the_key_is_missing(): void
    {
        config(['gas_storage.api_key' => null]);
        Http::fake();

        $this->artisan('gas-storage:fetch --if-configured')
            ->expectsOutputToContain('GIE_API_KEY is not set')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseCount('gas_storage_daily_values', 0);
    }

    public function test_if_configured_fetches_normally_when_the_key_is_set(): void
    {
        Http::fake([
            'agsi.gie.eu/api*' => Http::response([
                'last_page' => 1, 'total' => 1, 'dataset' => 'storage', 'gas_day' => '2026-10-30',
                'data' => [$this->day('2026-10-30', '89.47')],
            ]),
        ]);

        $this->artisan('gas-storage:fetch --if-configured --area=eu')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('gas_storage_daily_values', 1);
    }

    public function test_error_payload_with_status_200_fails_that_area_but_not_the_others(): void
    {
        Http::fake(fn (Request $request) => ($request->data()['country'] ?? null) === 'de'
            ? Http::response(['last_page' => 0, 'error' => 'access denied', 'message' => 'Invalid or missing API key', 'data' => []])
            : Http::response(['last_page' => 1, 'data' => [$this->day('2026-10-30', '89.47')]]));

        $this->artisan('gas-storage:fetch')->assertExitCode(1);

        $this->assertSame(['eu'], DB::table('gas_storage_daily_values')->pluck('area_code')->all());
    }

    public function test_non_numeric_values_are_stored_as_null_and_bad_days_are_skipped(): void
    {
        $day = $this->day('2026-10-30', '-');
        $day['injection'] = '';
        Http::fake(['*' => Http::response(['last_page' => 1, 'data' => [$day, ['gasDayStart' => 'n/a']]])]);

        $this->artisan('gas-storage:fetch --area=eu --from=2026-10-30 --to=2026-10-30')->assertExitCode(0);

        $row = DB::table('gas_storage_daily_values')->sole();
        $this->assertNull($row->full_pct);
        $this->assertNull($row->injection_gwh);
        $this->assertEqualsWithDelta(1012.3456, $row->gas_in_storage_twh, 0.0001);
    }

    public function test_rejects_unknown_area_and_bad_dates(): void
    {
        Http::fake();

        foreach ([
            ['--area' => ['xx']],
            ['--from' => '2026-13-01'],
            ['--from' => '2026-10-31', '--to' => '2026-10-01'],
        ] as $options) {
            $this->assertSame(1, Artisan::call('gas-storage:fetch', $options));
            $this->assertSame(['status' => 'failed', 'step' => 'fetch', 'error' => 'invalid_options'], $this->lastJsonLine());
        }

        Http::assertNothingSent();
        $this->assertSame([], glob(config('import_payloads.path').'/gie-agsi/*'));
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->fakeAgsi('89.47');

        $this->assertSame(0, Artisan::call('gas-storage:fetch'));
        $defaultRows = $this->storedRows();
        $this->assertSame(['gas_days' => 2, 'failed_areas' => 0], $this->lastJsonLine()['counts']);

        DB::table('gas_storage_daily_values')->delete();
        $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, DB::table('gas_storage_daily_values')->count(), 'Fetch only writes no rows.');
        $this->assertSame(2, $payload['requests']);

        // The store runs later and keeps the fetch time (S5); no key or HTTP call is needed.
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 10, 0, 0, 'UTC'));
        config(['gas_storage.api_key' => null]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256']]));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        $this->assertSame(['2026-11-01 08:00:00'], DB::table('gas_storage_daily_values')->distinct()->pluck('fetched_at')->all());
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        $this->fakeAgsi('89.47');
        Log::spy();
        // The second area's upsert fails after the first succeeded inside the same transaction.
        DB::statement("CREATE TRIGGER fail_gas_de BEFORE INSERT ON gas_storage_daily_values WHEN NEW.area_code = 'de' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('gas-storage:fetch'));
        $output = Artisan::output();
        $payloads = glob(config('import_payloads.path').'/gie-agsi/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
            'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'gas_storage_daily_values',
        ], $this->lastJsonLine($output));
        foreach (['forced failure', '89.47', '1012.3456'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, DB::table('gas_storage_daily_values')->count(), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: gas-storage:fetch', $message);
            $this->assertSame(['23000', 19, 'gas_storage_daily_values', 'store'], [$context['sqlstate'], $context['driver_code'], $context['table'], $context['step']]);
            $this->assertStringNotContainsString('forced failure', json_encode($context));
            $this->assertStringNotContainsString('89.47', json_encode($context));

            return true;
        });

        DB::statement('DROP TRIGGER fail_gas_de'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--from-payload' => $payloads[0]]));
        $this->assertSame(2, DB::table('gas_storage_daily_values')->count());
        Http::assertNothingSent();
    }

    public function test_the_api_key_never_reaches_the_payload_file(): void
    {
        config(['gas_storage.api_key' => 'gie-secret-key-4f9a2c']);
        $this->fakeAgsi('89.47');

        $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--fetch-only' => true]));
        $path = $this->lastJsonLine()['payload']['path'];

        $decompressed = (string) gzdecode((string) file_get_contents($path));
        $this->assertStringContainsString('2026-10-30', $decompressed);
        $this->assertStringNotContainsString('gie-secret-key-4f9a2c', $decompressed);
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-key', 'gie-secret-key-4f9a2c'));
    }

    public function test_an_agsi_error_body_is_recorded_and_stays_a_failure(): void
    {
        Http::fake(fn (Request $request) => ($request->data()['country'] ?? null) === 'de'
            ? Http::response(['last_page' => 0, 'error' => 'access denied', 'message' => 'Invalid or missing API key', 'data' => []])
            : Http::response(['last_page' => 1, 'data' => [$this->day('2026-10-30', '89.47')]]));

        $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        $this->assertSame([2, 1, false], [$reference['requests'], $reference['failed_units'], $reference['complete']]);
        $this->assertStringContainsString('access denied', (string) gzdecode((string) file_get_contents($reference['path'])));

        $this->assertSame(1, Artisan::call('gas-storage:fetch', ['--from-payload' => $reference['path']]));
        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false], [$line['status'], $line['step'], $line['error'], $line['complete']]);
        $this->assertSame(['gas_days' => 1, 'failed_areas' => 1], $line['counts']);
        $this->assertSame(['eu'], DB::table('gas_storage_daily_values')->pluck('area_code')->all());
    }

    public function test_if_configured_without_a_key_skips_in_every_mode_without_a_payload(): void
    {
        $this->fakeAgsi('89.47');
        $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--fetch-only' => true]));
        $path = $this->lastJsonLine()['payload']['path'];

        config(['gas_storage.api_key' => null]);
        foreach ([[], ['--fetch-only' => true], ['--from-payload' => $path]] as $mode) {
            $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--if-configured' => true, ...$mode]));
            $this->assertSame(['status' => 'skipped', 'step' => 'fetch', 'reason' => 'not_configured'], $this->lastJsonLine());
        }

        $this->assertCount(1, glob(config('import_payloads.path').'/gie-agsi/2026-11-01/*.ndjson.gz'));
        $this->assertSame(0, DB::table('gas_storage_daily_values')->count());
    }

    /**
     * A replay stores only the newest payload: an older same-day payload is refused as
     * superseded and writes nothing, so the newer values stay.
     */
    public function test_an_older_same_day_payload_is_refused_as_superseded_and_writes_nothing(): void
    {
        $this->fakeAgsi('89.00');
        Artisan::call('gas-storage:fetch', ['--area' => ['eu']]);
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 20, 0, 0, 'UTC'));
        $this->fakeAgsi('89.50');
        Artisan::call('gas-storage:fetch', ['--area' => ['eu']]);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('gas-storage:fetch', ['--area' => ['eu'], '--from-payload' => $older]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $older, 'payload_sha256' => hash_file('sha256', $older),
        ], $this->lastJsonLine());
        $row = DB::table('gas_storage_daily_values')->sole();
        $this->assertEqualsWithDelta(89.50, $row->full_pct, 0.0001);
        $this->assertSame('2026-11-01 20:00:00', $row->fetched_at);
    }

    public function test_from_payload_refuses_other_data_options(): void
    {
        $this->fakeAgsi('89.47');
        Artisan::call('gas-storage:fetch', ['--fetch-only' => true, '--area' => ['eu']]);
        $path = $this->lastJsonLine()['payload']['path'];

        $this->assertSame(1, Artisan::call('gas-storage:fetch', ['--from-payload' => $path]));
        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'options_mismatch',
            'payload_path' => $path, 'payload_sha256' => hash_file('sha256', $path),
        ], $this->lastJsonLine());

        // --if-configured is not a data option.
        $this->assertSame(0, Artisan::call('gas-storage:fetch', ['--from-payload' => $path, '--area' => ['eu'], '--if-configured' => true]));
    }

    /** More pages than GieAgsiService::MAX_PAGES: the area is incomplete, so none of its days is stored. */
    public function test_an_area_that_reaches_the_page_limit_before_its_last_page_fails(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'last_page' => ($request->data()['country'] ?? null) === 'de' ? 1 : GieAgsiService::MAX_PAGES + 1,
            'data' => [$this->day('2026-10-30', '89.47')],
        ]));

        $this->assertSame(1, Artisan::call('gas-storage:fetch'));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false], [$line['status'], $line['step'], $line['error'], $line['complete']]);
        $this->assertSame(['gas_days' => 1, 'failed_areas' => 1], $line['counts']);
        $this->assertSame(['de'], DB::table('gas_storage_daily_values')->pluck('area_code')->unique()->values()->all());
        Http::assertSentCount(GieAgsiService::MAX_PAGES + 1); // eu stops at the limit; de has one page
    }

    private function fakeAgsi(string $full): void
    {
        Http::swap(new Factory);
        Http::fake(['agsi.gie.eu/api*' => Http::response(['last_page' => 1, 'data' => [$this->day('2026-10-30', $full)]])]);
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        return DB::table('gas_storage_daily_values')->orderBy('area_code')->orderBy('gas_day')->get()
            ->map(fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at'])))->all();
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
