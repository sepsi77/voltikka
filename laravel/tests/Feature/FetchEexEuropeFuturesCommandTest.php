<?php

namespace Tests\Feature;

use App\Console\Commands\FetchEexEuropeFutures;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityFuturesEodPrice;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
