<?php

namespace Tests\Feature;

use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\ContractListCacheService;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchEexFuturesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_full_scope_stops_before_range_and_network_work_when_initial_checkpoint_fails(): void
    {
        $freshness = $this->createMock(MorningJobFreshnessService::class);
        $freshness->expects($this->once())
            ->method('record')
            ->willThrowException(new \RuntimeException('Checkpoint unavailable'));
        $this->app->instance(MorningJobFreshnessService::class, $freshness);
        Http::fake();

        $this->artisan('futures:fetch-eex')
            ->expectsOutput('Failed to record the EEX freshness checkpoint.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_command_fetches_eex_futures_and_saves_prices(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));

        config()->set('eex_futures.instruments', [[
            'market_region' => 'Nordics',
            'area' => 'FI',
            'area_name' => 'Finland',
            'short_code' => 'FNBY',
        ]]);
        config()->set('eex_futures.years_ahead', 1);
        config()->set('eex_futures.history_window_days', 45);
        config()->set('eex_futures.retry_times', 0);

        $listCache = app(ContractListCacheService::class);
        $initialCacheVersion = $listCache->getVersion();

        Http::fake([
            '*' => Http::response([
                'lastUpdate' => '2026-05-22',
                'currency' => 'EUR',
                'uOM' => 'MWh',
                'longName' => 'EEX Finnish Power Base Year Future',
                'displayYear' => 2027,
                'displaySeason' => null,
                'displayQuarter' => null,
                'displayMonth' => 1,
                'displayWeek' => null,
                'displayDay' => null,
                'series' => [
                    [
                        'serieName' => 'settlPx',
                        'timeAndValue' => [
                            ['2026-05-21', 47.44],
                            ['2026-05-22', 47.10],
                        ],
                    ],
                    [
                        'serieName' => 'volume',
                        'timeAndValue' => [['2026-05-22', 12]],
                    ],
                    [
                        'serieName' => 'lotSize',
                        'timeAndValue' => [['2026-05-22', 1]],
                    ],
                ],
            ]),
        ]);

        $this->artisan('futures:fetch-eex --maturity=202701')
            ->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Referer', 'https://www.eex.com/')
                && $request['commodity'] === 'POWER'
                && $request['pricing'] === 'F'
                && $request['area'] === 'FI'
                && $request['product'] === 'Base'
                && $request['maturity'] === '202701'
                && $request['shortCode'] === 'FNBY'
                && $request['startDate'] === '2026-04-08'
                && $request['endDate'] === '2026-05-22';
        });

        $this->assertDatabaseHas('electricity_futures_eod_prices', [
            'exchange' => 'EEX',
            'area' => 'FI',
            'short_code' => 'FNBY',
            'maturity' => '202701',
            'trade_date' => '2026-05-22',
            'settlement_price' => 47.10,
            'volume' => 12,
            'lot_size' => 1,
            'currency' => 'EUR',
            'unit' => 'MWh',
            'long_name' => 'EEX Finnish Power Base Year Future',
        ]);
        $this->assertSame(2, ElectricityFuturesEodPrice::count());
        $this->assertSame($initialCacheVersion + 1, app(ContractListCacheService::class)->getVersion());
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
    }

    public function test_full_scheduled_scope_records_ready_checkpoint(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));
        config()->set('eex_futures.instruments', [[
            'market_region' => 'Nordics',
            'area' => 'FI',
            'area_name' => 'Finland',
            'maturity_type' => 'year',
            'short_code' => 'FNBY',
        ]]);
        config()->set('eex_futures.years_ahead', 1);
        config()->set('eex_futures.retry_times', 0);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'price-ticker')) {
                return Http::response(['data' => [['2026-05-21T19:00:00.000Z', 47.44]]]);
            }

            return Http::response([
                'series' => [[
                    'serieName' => 'settlPx',
                    'timeAndValue' => [['2026-05-21', 47.44]],
                ]],
            ]);
        });

        $this->artisan('futures:fetch-eex')->assertExitCode(0);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::KEY_EEX_FUTURES, $checkpoint->key);
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $checkpoint->status);
        $this->assertSame('2026-05-21', $checkpoint->metadata['current_run_latest_prior_fi_trade_date']);
    }

    public function test_full_scope_fails_when_current_run_fetches_only_non_fi_points_despite_old_fi_data(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));
        ElectricityFuturesEodPrice::create([
            'area' => 'FI',
            'short_code' => 'FNBY',
            'maturity' => '202701',
            'maturity_type' => 'year',
            'trade_date' => '2026-05-20',
            'settlement_price' => 47.00,
        ]);
        config()->set('eex_futures.instruments', [[
            'market_region' => 'Nordics',
            'area' => 'SE3',
            'area_name' => 'Sweden SE3',
            'maturity_type' => 'year',
            'short_code' => '3SBY',
        ]]);
        config()->set('eex_futures.years_ahead', 1);
        config()->set('eex_futures.retry_times', 0);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'price-ticker')) {
                return Http::response(['data' => [['2026-05-21T19:00:00.000Z', 45.00]]]);
            }

            return Http::response([
                'series' => [[
                    'serieName' => 'settlPx',
                    'timeAndValue' => [['2026-05-21', 45.00]],
                ]],
            ]);
        });

        $this->artisan('futures:fetch-eex')->assertExitCode(1);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertNull($checkpoint->metadata['current_run_latest_prior_fi_trade_date']);
        $this->assertDatabaseHas('electricity_futures_eod_prices', [
            'area' => 'SE3',
            'trade_date' => '2026-05-21',
        ]);
    }

    public function test_command_fetches_month_quarter_and_year_maturities_for_selected_area(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));

        config()->set('eex_futures.instruments', [
            [
                'market_region' => 'Nordics',
                'area' => 'FI',
                'area_name' => 'Finland',
                'maturity_type' => 'month',
                'short_code' => 'FNBM',
            ],
            [
                'market_region' => 'Nordics',
                'area' => 'FI',
                'area_name' => 'Finland',
                'maturity_type' => 'quarter',
                'short_code' => 'FNBQ',
            ],
            [
                'market_region' => 'Nordics',
                'area' => 'FI',
                'area_name' => 'Finland',
                'maturity_type' => 'year',
                'short_code' => 'FNBY',
            ],
        ]);
        config()->set('eex_futures.retry_times', 0);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'price-ticker')) {
                return Http::response(['data' => [['2026-05-21T19:00:00.000Z', 1]]]);
            }

            return Http::response(['series' => []]);
        });

        $this->artisan('futures:fetch-eex --area=FI --months-back=0 --months-ahead=1 --quarters-ahead=1 --years-ahead=1')
            ->assertExitCode(0);

        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBM' && $request['maturity'] === '202605');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBQ' && $request['maturity'] === '202607');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBY' && $request['maturity'] === '202701');
    }

    public function test_default_maturities_match_visible_eex_delivery_windows(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));

        config()->set('eex_futures.instruments', [
            [
                'market_region' => 'Nordics',
                'area' => 'FI',
                'area_name' => 'Finland',
                'maturity_type' => 'month',
                'short_code' => 'FNBM',
            ],
            [
                'market_region' => 'Nordics',
                'area' => 'FI',
                'area_name' => 'Finland',
                'maturity_type' => 'year',
                'short_code' => 'FNBY',
            ],
        ]);
        config()->set('eex_futures.retry_times', 0);

        $validMonthMaturities = ['202604', '202605', '202606', '202607', '202608', '202609', '202610', '202611'];
        $validYearMaturities = ['202701', '202801', '202901', '203001', '203101', '203201'];

        Http::fake(function (Request $request) use ($validMonthMaturities, $validYearMaturities) {
            if (str_contains($request->url(), 'price-ticker')) {
                $valid = ($request['shortCode'] === 'FNBM' && in_array($request['maturity'], $validMonthMaturities, true))
                    || ($request['shortCode'] === 'FNBY' && in_array($request['maturity'], $validYearMaturities, true));

                return Http::response(['data' => $valid ? [['2026-05-21T19:00:00.000Z', 1]] : []]);
            }

            return Http::response(['series' => []]);
        });

        $this->artisan('futures:fetch-eex --area=FI')
            ->assertExitCode(0);

        Http::assertSentCount(30);

        foreach ($validMonthMaturities as $maturity) {
            Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBM' && $request['maturity'] === $maturity);
        }

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'price-ticker') && $request['shortCode'] === 'FNBM' && $request['maturity'] === '202612');

        foreach ($validYearMaturities as $maturity) {
            Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBY' && $request['maturity'] === $maturity);
        }

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'price-ticker') && $request['shortCode'] === 'FNBY' && $request['maturity'] === '203301');
    }

    public function test_maturity_discovery_is_shared_by_tenor_across_areas(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));

        config()->set('eex_futures.instruments', [
            [
                'market_region' => 'Nordics',
                'area' => 'FI',
                'area_name' => 'Finland',
                'maturity_type' => 'year',
                'short_code' => 'FNBY',
            ],
            [
                'market_region' => 'Nordics',
                'area' => 'SE3',
                'area_name' => 'Sweden SE3',
                'maturity_type' => 'year',
                'short_code' => '3SBY',
            ],
        ]);
        config()->set('eex_futures.years_ahead', 2);
        config()->set('eex_futures.retry_times', 0);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'price-ticker')) {
                return Http::response(['data' => $request['maturity'] === '202701' ? [['2026-05-21T19:00:00.000Z', 1]] : []]);
            }

            return Http::response(['series' => []]);
        });

        $this->artisan('futures:fetch-eex --tenor=year')
            ->assertExitCode(0);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'price-ticker') && $request['shortCode'] === 'FNBY' && $request['maturity'] === '202701');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'price-ticker') && $request['shortCode'] === 'FNBY' && $request['maturity'] === '202801');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'price-ticker') && $request['shortCode'] === '3SBY');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBY' && $request['maturity'] === '202701');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod') && $request['shortCode'] === '3SBY' && $request['maturity'] === '202701');
    }

    public function test_backfill_command_fetches_all_publicly_available_history_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));

        config()->set('eex_futures.instruments', [[
            'market_region' => 'Nordics',
            'area' => 'FI',
            'area_name' => 'Finland',
            'maturity_type' => 'year',
            'short_code' => 'FNBY',
        ]]);
        config()->set('eex_futures.years_ahead', 2);
        config()->set('eex_futures.retry_times', 0);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'price-ticker')) {
                return Http::response(['data' => $request['maturity'] === '202701' ? [['2026-05-21T19:00:00.000Z', 1]] : []]);
            }

            return Http::response(['series' => []]);
        });

        $this->artisan('futures:backfill-eex --area=FI --tenor=year --dry-run')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/eod')
            && $request['shortCode'] === 'FNBY'
            && $request['maturity'] === '202701'
            && $request['startDate'] === '2026-04-08'
            && $request['endDate'] === '2026-05-22');
    }

    public function test_command_caps_requested_start_date_to_eex_history_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));

        config()->set('eex_futures.instruments', [[
            'market_region' => 'Nordics',
            'area' => 'FI',
            'area_name' => 'Finland',
            'maturity_type' => 'year',
            'short_code' => 'FNBY',
        ]]);
        config()->set('eex_futures.retry_times', 0);

        Http::fake(['*' => Http::response(['series' => []])]);

        $this->artisan('futures:fetch-eex --start-date=2026-01-01 --end-date=2026-05-22 --maturity=202701 --history-window-days=45')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $request['startDate'] === '2026-04-08');
    }
}
