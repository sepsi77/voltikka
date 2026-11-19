<?php

namespace Tests\Feature;

use App\Console\Commands\FetchEexEuropeFutures;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\ImportPayload\ImportPayloadReader;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchEexEuropeFuturesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 13, 0, 0, 'Europe/Helsinki'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function eodResponse(Request $request): array
    {
        $prices = ['DEBM' => 223.04, 'G3BM' => 103.813, 'FEUA' => 89.19];

        return [
            'lastUpdate' => '2026-10-30',
            'currency' => 'EUR',
            'uOM' => $request['shortCode'] === 'FEUA' ? 'TOCD' : 'MWh',
            'longName' => 'EEX '.$request['shortCode'],
            'displayYear' => (int) substr($request['maturity'], 0, 4),
            'displayMonth' => (int) substr($request['maturity'], 4, 2),
            'series' => [
                ['serieName' => 'settlPx', 'timeAndValue' => [['2026-10-30', $prices[$request['shortCode']] ?? 50.0]]],
                ['serieName' => 'volume', 'timeAndValue' => [['2026-10-30', 1000]]],
                ['serieName' => 'lotSize', 'timeAndValue' => [['2026-10-30', 10]]],
            ],
        ];
    }

    public function test_stores_german_power_ttf_gas_and_eua_rows_without_touching_the_nordic_checkpoint(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->eodResponse($request)));

        $this->artisan('futures:fetch-eex-europe')->assertExitCode(0);

        // 6 + 4 + 3 German power, 6 TTF and 2 EUA requests, all on the chart endpoint.
        Http::assertSentCount(21);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'price-ticker'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Referer', 'https://www.eex.com/')
            && $request['commodity'] === 'NATGAS' && $request['area'] === 'TTF'
            && $request['product'] === 'Physical' && $request['shortCode'] === 'G3BM'
            && $request['startDate'] === '2026-09-18' && $request['endDate'] === '2026-11-01');

        $this->assertSame(21, ElectricityFuturesEodPrice::query()->count());
        $gas = ElectricityFuturesEodPrice::query()->where('short_code', 'G3BM')->where('maturity', '202612')->sole();
        $this->assertSame('NATGAS', $gas->commodity);
        $this->assertSame('TTF', $gas->area);
        $this->assertEqualsWithDelta(103.813, $gas->settlement_price, 0.00001);
        $this->assertSame(
            ['202612', '202712'],
            ElectricityFuturesEodPrice::query()->where('short_code', 'FEUA')->orderBy('maturity')->pluck('maturity')->all()
        );

        // Isolation: no row has an FI/Base identity, and the global EEX checkpoint is not written.
        $this->assertSame(0, ElectricityFuturesEodPrice::query()->where('area', 'FI')->count());
        $this->assertSame(0, DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_EEX_FUTURES)->count());
    }

    public function test_a_failed_request_does_not_stop_the_others_and_exits_one(): void
    {
        Http::fake(fn (Request $request) => $request['shortCode'] === 'DEBY'
            ? Http::response('private body', 400)
            : Http::response($this->eodResponse($request)));

        $this->artisan('futures:fetch-eex-europe', ['--short-code' => ['DEBY', 'FEUA']])->assertExitCode(1);

        $this->assertSame(['FEUA'], ElectricityFuturesEodPrice::query()->distinct()->pluck('short_code')->all());
    }

    public function test_rerun_upserts_revised_settlements(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->eodResponse($request)));
        $this->artisan('futures:fetch-eex-europe', ['--short-code' => ['DEBM']])->assertExitCode(0);
        $this->artisan('futures:fetch-eex-europe', ['--short-code' => ['DEBM']])->assertExitCode(0);

        $this->assertSame(6, ElectricityFuturesEodPrice::query()->count());
    }

    public function test_refuses_unknown_short_codes_and_nordic_areas(): void
    {
        Http::fake();

        $this->artisan('futures:fetch-eex-europe', ['--short-code' => ['XXXX']])->assertExitCode(1);

        config()->set('eex_futures.europe_instruments', [
            ['area' => 'FI', 'short_code' => 'FNBM', 'maturity_type' => 'month', 'count' => 1],
        ]);
        $this->artisan('futures:fetch-eex-europe')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->eodResponse($request)));
        $options = ['--short-code' => ['DEBM', 'FEUA']];

        $this->assertSame(0, Artisan::call('futures:fetch-eex-europe', $options));
        $this->assertSame(['saved_points' => 8, 'failures' => 0], $this->lastJsonLine()['counts']);
        $defaultRows = $this->storedRows();
        $this->assertCount(8, $defaultRows);

        ElectricityFuturesEodPrice::query()->delete();
        $this->assertSame(0, Artisan::call('futures:fetch-eex-europe', [...$options, '--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, ElectricityFuturesEodPrice::count(), 'Fetch only writes no rows.');
        $this->assertSame('2026-11-01', ImportPayloadReader::open($payload['path'])->fact('effective_date'));

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'Europe/Helsinki'));
        $this->preventHttp();
        $this->assertSame(0, Artisan::call('futures:fetch-eex-europe', [
            ...$options, '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256'],
        ]));
        Http::assertNothingSent();
        $this->assertSame($defaultRows, $this->storedRows());
        $this->assertSame(0, DataFreshnessCheckpoint::count());
    }

    public function test_store_failure_writes_no_row_logs_safe_sql_facts_and_replays(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->eodResponse($request)));
        Log::spy();
        // A row from before the run with the key of the run's first point, at another price.
        DB::table('electricity_futures_eod_prices')->insert([
            'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base', 'area' => 'DE',
            'short_code' => 'DEBM', 'maturity' => '202611', 'maturity_type' => 'month', 'trade_date' => '2026-10-30', 'settlement_price' => 1.0,
        ]);
        $preRun = $this->storedRows();
        // The last of the six DEBM maturities fails after the first five upserts succeeded.
        DB::statement("CREATE TRIGGER fail_eex_prices BEFORE INSERT ON electricity_futures_eod_prices WHEN NEW.maturity = '202704' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('futures:fetch-eex-europe', ['--short-code' => ['DEBM']]));
        $output = Artisan::output();

        $storeError = ['sqlstate' => '23000', 'driver_code' => 19, 'table' => 'electricity_futures_eod_prices'];
        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', ...$storeError], $this->lastJsonLine($output));
        foreach (['223.04', 'forced failure'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame($preRun, $this->storedRows(), 'A failed store writes no row of the run, and the pre-run row keeps its price (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($storeError): bool {
            $this->assertSame('Import failed: futures:fetch-eex-europe', $message);
            $this->assertSame(['step' => 'store', ...$storeError], array_intersect_key($context, ['step' => true, ...$storeError]));
            $this->assertStringNotContainsString('223.04', json_encode($context));
            $this->assertStringNotContainsString('forced failure', json_encode($context));

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/eex-europe/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_eex_prices'); // the fix
        $this->preventHttp();
        $this->assertSame(0, Artisan::call('futures:fetch-eex-europe', ['--short-code' => ['DEBM'], '--from-payload' => $payloads[0]]));
        Http::assertNothingSent();
        $this->assertSame(6, ElectricityFuturesEodPrice::count(), 'The run overwrites the pre-run row.');
        $this->assertEqualsWithDelta(223.04, ElectricityFuturesEodPrice::query()->where('maturity', '202611')->sole()->settlement_price, 0.0001);
    }

    public function test_a_payload_from_another_helsinki_date_is_refused(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->eodResponse($request)));
        Artisan::call('futures:fetch-eex-europe', ['--short-code' => ['FEUA'], '--fetch-only' => true]);
        $path = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 2, 0, 10, 0, 'Europe/Helsinki'));
        $this->preventHttp();
        $this->assertSame(1, Artisan::call('futures:fetch-eex-europe', ['--short-code' => ['FEUA'], '--from-payload' => $path]));

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'wrong_date'], $this->lastJsonLine());
        $this->assertSame(0, ElectricityFuturesEodPrice::count());
    }

    public function test_a_partial_fetch_stores_the_answered_requests_and_says_fetch_incomplete(): void
    {
        Http::fake(fn (Request $request) => $request['shortCode'] === 'DEBY'
            ? Http::response('private body', 400)
            : Http::response($this->eodResponse($request)));

        $this->assertSame(1, Artisan::call('futures:fetch-eex-europe', ['--short-code' => ['DEBY', 'FEUA']]));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false], [$line['status'], $line['step'], $line['error'], $line['complete']]);
        $this->assertSame(['saved_points' => 2, 'failures' => 3], $line['counts']);
        $this->assertSame(2, ElectricityFuturesEodPrice::count());
    }

    private function preventHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([]);
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        return DB::table('electricity_futures_eod_prices')->orderBy('short_code')->orderBy('maturity')->orderBy('trade_date')->get()
            ->map(fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at'])))->all();
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_maturity_rules(): void
    {
        $command = app(FetchEexEuropeFutures::class);
        $date = CarbonImmutable::create(2026, 11, 15);

        $this->assertSame(['202611', '202612', '202701'], $command->maturities(['maturity_type' => 'month', 'count' => 3], $date));
        $this->assertSame(['202701', '202704'], $command->maturities(['maturity_type' => 'quarter', 'count' => 2], $date));
        $this->assertSame(['202701', '202801'], $command->maturities(['maturity_type' => 'year', 'count' => 2], $date));
        $this->assertSame(['202612', '202712'], $command->maturities(['maturity_type' => 'month', 'maturity_rule' => 'december', 'count' => 2], $date));
        $this->assertSame(['202604'], $command->maturities(['maturity_type' => 'quarter', 'count' => 1], CarbonImmutable::create(2026, 3, 31)));
    }
}
