<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        $this->artisan('gas-storage:fetch --area=xx')->assertExitCode(1);
        $this->artisan('gas-storage:fetch --from=2026-13-01')->assertExitCode(1);
        $this->artisan('gas-storage:fetch --from=2026-10-31 --to=2026-10-01')->assertExitCode(1);

        Http::assertNothingSent();
    }
}
