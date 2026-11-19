<?php

namespace Tests\Feature;

use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\ContractListCacheService;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Concerns\CapturesSentryIssues;
use Tests\TestCase;

class FetchEexFuturesCommandTest extends TestCase
{
    use CapturesSentryIssues;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_multiple_failed_maturities_create_one_issue(): void
    {
        $this->captureSentryIssues();
        config()->set('eex_futures.request_delay_seconds', 0);
        config()->set('eex_futures.request_delay_jitter_seconds', 0);
        Http::fake(fn () => Http::response('private response', 400));

        $this->artisan('futures:fetch-eex', [
            '--area' => ['FI'], '--tenor' => ['month'], '--maturity' => ['202609', '202610'],
        ])->assertExitCode(1);

        // Two retry passes run, but only the units that are still failed reach the one Issue.
        $this->assertImportIssue('eex_futures', 'error', ['acquisition' => 2]);
        Http::assertSentCount(6);
        Sleep::assertSleptTimes(2);
    }

    public function test_month_discovery_failure_recovers_in_a_retry_pass_without_an_issue(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        $monthProbes = 0;
        Http::fake(function (Request $request) use (&$monthProbes) {
            if (str_contains($request->url(), 'price-ticker') && $request['shortCode'] === 'FNBM' && ++$monthProbes === 1) {
                throw new ConnectionException('private connection detail');
            }

            return $this->eexResponse($request);
        });

        $this->artisan('futures:fetch-eex')->assertExitCode(0);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $checkpoint->status);
        $this->assertSame(0, $checkpoint->metadata['failures']);
        $this->assertSame(1, $checkpoint->metadata['failures_before_retry']);
        $this->assertSame(1, $checkpoint->metadata['retry_passes']);
        $this->assertSame(
            ['month' => '2026-05-21', 'quarter' => '2026-05-21', 'year' => '2026-05-21'],
            $checkpoint->metadata['current_run_latest_prior_fi_trade_dates_by_tenor'],
        );
        $this->assertDatabaseHas('electricity_futures_eod_prices', ['short_code' => 'FNBM', 'maturity' => '202605', 'trade_date' => '2026-05-21']);
        $this->assertSame([], $this->sentryIssues);
        Sleep::assertSleptTimes(1);
        $gate = app(MorningJobFreshnessService::class)->checkRetailPremium(CarbonImmutable::parse('2026-05-22', 'Europe/Helsinki'));
        $this->assertArrayNotHasKey('eex_checkpoint', $gate->failures);
        $this->assertArrayNotHasKey('eex_metadata', $gate->failures);
    }

    public function test_failed_eod_request_is_retried_alone(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        $yearRequests = 0;
        Http::fake(function (Request $request) use (&$yearRequests) {
            if (str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBY' && ++$yearRequests === 1) {
                return Http::response('private response', 503);
            }

            return $this->eexResponse($request);
        });

        $this->artisan('futures:fetch-eex')->assertExitCode(0);

        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, DataFreshnessCheckpoint::sole()->status);
        $this->assertSame(2, $yearRequests);
        Http::assertSentCount(7); // 3 discovery probes, 3 EOD requests, 1 retried EOD request
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_persistent_discovery_failure_keeps_the_failed_outcome(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        $monthProbes = 0;
        Http::fake(function (Request $request) use (&$monthProbes) {
            if (str_contains($request->url(), 'price-ticker') && $request['shortCode'] === 'FNBM') {
                $monthProbes++;

                throw new ConnectionException('private connection detail');
            }

            return $this->eexResponse($request);
        });

        $this->artisan('futures:fetch-eex')->assertExitCode(1);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame(1, $checkpoint->metadata['failures']);
        $this->assertSame(2, $checkpoint->metadata['retry_passes']);
        $this->assertNull($checkpoint->metadata['current_run_latest_prior_fi_trade_dates_by_tenor']['month']);
        $this->assertSame(3, $monthProbes);
        Sleep::assertSleptTimes(2);
        $this->assertImportIssue('eex_futures', 'error', ['discovery' => 1, 'missing_current_run_prior_fi' => 1]);
        $this->assertStringNotContainsString('private', json_encode($this->sentryIssues[0]->getContexts()));
    }

    public function test_too_many_failed_units_are_not_retried(): void
    {
        $this->captureSentryIssues();
        config()->set('eex_futures.retry_max_failed_units', 1);
        config()->set('eex_futures.retry_times', 0);
        Http::fake(fn () => Http::response('private response', 503));

        $this->artisan('futures:fetch-eex', [
            '--area' => ['FI'], '--tenor' => ['month'], '--maturity' => ['202609', '202610'],
        ])->assertExitCode(1);

        Http::assertSentCount(2);
        Sleep::assertNeverSlept();
        $this->assertImportIssue('eex_futures', 'error', ['acquisition' => 2]);
    }

    public function test_run_without_month_rows_and_without_a_failure_is_not_ready(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBM') {
                return Http::response(['series' => []]);
            }

            return $this->eexResponse($request);
        });

        $this->artisan('futures:fetch-eex')->assertExitCode(1);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame(0, $checkpoint->metadata['failures']);
        $this->assertSame(0, $checkpoint->metadata['retry_passes']);
        $this->assertSame('2026-05-21', $checkpoint->metadata['current_run_latest_prior_fi_trade_date']);
        $this->assertSame(
            ['month' => null, 'quarter' => '2026-05-21', 'year' => '2026-05-21'],
            $checkpoint->metadata['current_run_latest_prior_fi_trade_dates_by_tenor'],
        );
        Sleep::assertNeverSlept();
        $this->assertImportIssue('eex_futures', 'error', ['missing_current_run_prior_fi' => 1]);
    }

    public function test_discovery_failures_and_missing_fi_proof_create_one_issue(): void
    {
        $this->captureSentryIssues();
        config()->set('eex_futures.request_delay_seconds', 0);
        config()->set('eex_futures.request_delay_jitter_seconds', 0);
        Http::fake(fn () => Http::response('private response', 400));

        $this->artisan('futures:fetch-eex')->assertExitCode(1);

        $this->assertImportIssue('eex_futures', 'error', [
            'discovery' => 3, 'missing_current_run_prior_fi' => 1,
        ]);
    }

    public function test_normal_empty_maturity_does_not_create_an_issue(): void
    {
        $this->captureSentryIssues();
        config()->set('eex_futures.request_delay_seconds', 0);
        config()->set('eex_futures.request_delay_jitter_seconds', 0);
        Http::fake(fn () => Http::response(['data' => []], 200));

        $this->artisan('futures:fetch-eex', [
            '--area' => ['FI'], '--tenor' => ['month'], '--maturity' => ['202609'],
        ])->assertExitCode(0);

        $this->assertSame([], $this->sentryIssues);
    }

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
        $this->assertSame($initialCacheVersion, app(ContractListCacheService::class)->getVersion());
        $this->assertTrue(app(ContractPriceCacheLifecycle::class)->pending());
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
    }

    public function test_full_scheduled_scope_records_ready_checkpoint(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        // Two candidates per tenor: the second is out of range (HTTP 200, empty data), which is normal.
        config()->set('eex_futures.months_ahead', 2);
        config()->set('eex_futures.quarters_ahead', 2);
        config()->set('eex_futures.years_ahead', 2);

        Http::fake(function (Request $request) {
            $checkpoint = DataFreshnessCheckpoint::sole();
            $this->assertSame(DataFreshnessCheckpoint::STATUS_RUNNING, $checkpoint->status);
            $this->assertTrue(Str::isUuid($checkpoint->metadata['run_uuid']));
            if (str_contains($request->url(), 'price-ticker')
                && ! in_array($request['maturity'], ['202605', '202607', '202701'], true)) {
                return Http::response(['data' => []]);
            }

            return $this->eexResponse($request);
        });

        $this->artisan('futures:fetch-eex')->assertExitCode(0);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::KEY_EEX_FUTURES, $checkpoint->key);
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $checkpoint->status);
        $this->assertSame('2026-05-21', $checkpoint->metadata['current_run_latest_prior_fi_trade_date']);
        $this->assertSame(
            ['month' => '2026-05-21', 'quarter' => '2026-05-21', 'year' => '2026-05-21'],
            $checkpoint->metadata['current_run_latest_prior_fi_trade_dates_by_tenor'],
        );
        $this->assertSame(0, $checkpoint->metadata['retry_passes']);
        Http::assertSentCount(9);
        Sleep::assertNeverSlept();
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_unexpected_exception_marks_the_owned_full_run_failed(): void
    {
        config()->set('eex_futures.request_delay_seconds', 0);
        Http::fake(function () {
            $this->assertSame('running', DataFreshnessCheckpoint::sole()->status);
            throw new \RuntimeException('private upstream detail');
        });
        $this->artisan('futures:fetch-eex')->assertExitCode(1);
        $this->assertSame('failed', DataFreshnessCheckpoint::sole()->status);
        $this->assertSame('unexpected', DataFreshnessCheckpoint::sole()->metadata['reason']);
    }

    public function test_old_owner_cannot_replace_newer_eex_facts(): void
    {
        config()->set('eex_futures.instruments', [['area' => 'FI', 'maturity_type' => 'year', 'short_code' => 'FNBY']]);
        config()->set('eex_futures.years_ahead', 1);
        config()->set('eex_futures.request_delay_seconds', 0);
        Http::fake(function () {
            DataFreshnessCheckpoint::sole()->update(['status' => 'ready', 'metadata' => ['run_uuid' => 'new-owner', 'proof' => 'new-facts']]);

            return Http::response(['series' => []]);
        });
        $this->artisan('futures:fetch-eex')->assertExitCode(1);
        $this->assertSame('ready', DataFreshnessCheckpoint::sole()->status);
        $this->assertSame(['run_uuid' => 'new-owner', 'proof' => 'new-facts'], DataFreshnessCheckpoint::sole()->metadata);
    }

    public function test_dry_run_never_creates_global_eex_checkpoint(): void
    {
        config()->set('eex_futures.instruments', []);
        Http::fake();
        $this->artisan('futures:fetch-eex --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
    }

    public function test_full_scope_fails_when_current_run_fetches_only_non_fi_points_despite_old_fi_data(): void
    {
        $this->captureSentryIssues();
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
        $this->assertImportIssue('eex_futures', 'error', ['missing_current_run_prior_fi' => 1]);
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

    /** Full-scope FI Base month, quarter and year instruments with one candidate maturity each. */
    public function test_all_null_ticker_row_ends_discovery_like_an_empty_one(): void
    {
        $this->fiBaseTenorInstruments();
        config()->set('eex_futures.months_ahead', 4);
        config()->set('eex_futures.request_delay_seconds', 0);
        config()->set('eex_futures.request_delay_jitter_seconds', 0);
        $unlistedRow = [[null, null, null, null, null, null, null]];

        Http::fake(function (Request $request) use ($unlistedRow) {
            // Since 2026-11 EEX answers an unlisted maturity with one all-null row.
            if (str_contains($request->url(), 'price-ticker')
                && ! in_array($request['maturity'], ['202605', '202606'], true)) {
                return Http::response(['header' => ['lastUpdatedAt', 'settlPx'], 'data' => $unlistedRow]);
            }

            return $this->eexResponse($request);
        });

        $this->artisan('futures:fetch-eex', ['--tenor' => ['month'], '--dry-run' => true])
            ->expectsOutputToContain('Discovered 2 month maturities')
            ->assertExitCode(0);

        $eodMaturities = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/chart/eod'))
            ->map(fn ($pair) => $pair[0]['maturity'])
            ->values()
            ->all();
        $this->assertSame(['202605', '202606'], $eodMaturities);
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows_and_proof(): void
    {
        $this->fiBaseTenorInstruments();
        Http::fake(fn (Request $request) => $this->eexResponse($request));
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $demand = $lifecycle->demandRevision();

        $this->assertSame(0, Artisan::call('futures:fetch-eex'));
        $line = $this->lastJsonLine();
        $this->assertSame('stored', $line['status']);
        $this->assertSame([
            'maturity_requests' => 3, 'fetched_points' => 6, 'saved_points' => 6,
            'failures' => 0, 'failures_before_retry' => 0, 'retry_passes' => 0,
        ], $line['counts']);
        $defaultRows = $this->storedRows();
        $defaultCheckpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $defaultCheckpoint->status);
        $this->assertSame($demand + 1, $demand = $lifecycle->demandRevision(), 'A real FI write bumps the pricing caches.');

        ElectricityFuturesEodPrice::query()->delete();
        DataFreshnessCheckpoint::query()->delete();
        $this->assertSame(0, Artisan::call('futures:fetch-eex', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, ElectricityFuturesEodPrice::count(), 'Fetch only writes no rows.');
        $running = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_RUNNING, $running->status, 'S11: running from the start of the fetch.');
        $this->assertSame($demand, $lifecycle->demandRevision());

        // S5/S19: the effective date, window and discovered maturities are fixed in the header.
        $facts = ImportPayloadReader::open($payload['path'], $payload['sha256'])->header()['facts'];
        $this->assertSame('2026-05-22', $facts['effective_date']);
        $this->assertSame(['2026-04-08', '2026-05-22'], [$facts['start_date'], $facts['end_date']]);
        $this->assertSame(['month' => ['202605'], 'quarter' => ['202607'], 'year' => ['202701']], $facts['maturities_by_tenor']);

        // The store runs two hours later, without HTTP, throttle or retry pause.
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 14, 0, 0, 'Europe/Helsinki'));
        $this->preventHttp();
        Sleep::fake();
        $this->assertSame(0, Artisan::call('futures:fetch-eex', [
            '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256'],
        ]));
        Http::assertNothingSent();
        Sleep::assertNeverSlept();

        $this->assertSame($defaultRows, $this->storedRows());
        $replayed = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $replayed->status);
        $this->assertTrue(Str::isUuid($replayed->metadata['run_uuid']));
        $this->assertNotSame($running->metadata['run_uuid'], $replayed->metadata['run_uuid'], 'The replay takes the checkpoint over.');
        $this->assertSame(['path' => $payload['path'], 'sha256' => $payload['sha256']], $replayed->metadata['payload']);
        // The proof built from the payload equals the proof of the one-process run.
        $withoutRun = fn (array $metadata) => array_diff_key($metadata, ['run_uuid' => true, 'payload' => true]);
        $this->assertSame($withoutRun($defaultCheckpoint->metadata), $withoutRun($replayed->metadata));
        $this->assertSame($demand + 1, $lifecycle->demandRevision());
    }

    public function test_store_failure_writes_no_row_fails_the_checkpoint_with_the_payload_and_replays(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        Http::fake(fn (Request $request) => $this->eexResponse($request));
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $demand = $lifecycle->demandRevision();
        $preRun = $this->seedPreRunMonthRow();
        $this->failYearInserts();

        $this->assertSame(1, Artisan::call('futures:fetch-eex'));
        $output = Artisan::output();
        $storeError = ['sqlstate' => '23000', 'driver_code' => 19, 'table' => 'electricity_futures_eod_prices'];
        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', ...$storeError], $this->lastJsonLine($output));
        foreach (['47.44', 'forced failure'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame($preRun, $this->storedRows(), 'A failed store writes no row of the run, and the pre-run row keeps its price (S21).');
        $this->assertSame($demand, $lifecycle->demandRevision(), 'After-commit work does not run.');

        $payloads = glob(config('import_payloads.path').'/eex/2026-05-22/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame([
            'reason' => 'unexpected', 'step' => 'store', 'error' => 'store_failed',
            'payload' => ['path' => $payloads[0], 'sha256' => trim(file_get_contents(str_replace('.ndjson.gz', '.sha256', $payloads[0])))],
            ...$storeError,
        ], array_diff_key($checkpoint->metadata, ['run_uuid' => true]));

        $this->assertImportIssue('eex_futures', 'error', ['unexpected' => 1]);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame(['unexpected' => 'store'], $context['steps']);
        $this->assertSame($storeError, $context['store_error']);
        $this->assertSame(['unexpected' => ['sqlstate_23000' => 1]], $context['reasons']);
        foreach (['47.44', 'forced failure'] as $binding) {
            $this->assertStringNotContainsString($binding, json_encode($context));
        }

        DB::statement('DROP TRIGGER fail_eex_prices'); // the fix
        $this->sentryIssues = [];
        $this->preventHttp();
        $this->assertSame(0, Artisan::call('futures:fetch-eex', ['--from-payload' => $payloads[0]]));
        Http::assertNothingSent();
        $this->assertSame(6, ElectricityFuturesEodPrice::count(), 'The run overwrites the pre-run row.');
        $this->assertEqualsWithDelta(47.00, ElectricityFuturesEodPrice::query()->where('short_code', 'FNBM')->where('trade_date', '2026-05-20')->sole()->settlement_price, 0.0001);
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, DataFreshnessCheckpoint::sole()->status);
        $this->assertSame([], $this->sentryIssues);
        $this->assertSame($demand + 1, $lifecycle->demandRevision());
    }

    public function test_a_payload_from_another_helsinki_date_is_refused_and_leaves_the_checkpoint_alone(): void
    {
        $this->fiBaseTenorInstruments();
        Http::fake(fn (Request $request) => $this->eexResponse($request));
        Artisan::call('futures:fetch-eex', ['--fetch-only' => true]);
        $payload = $this->lastJsonLine()['payload'];
        $running = DataFreshnessCheckpoint::sole()->only(['status', 'metadata']);

        Carbon::setTestNow(Carbon::create(2026, 5, 23, 0, 30, 0, 'Europe/Helsinki'));
        $this->preventHttp();
        $this->assertSame(1, Artisan::call('futures:fetch-eex', ['--from-payload' => $payload['path']]));

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'wrong_date'], $this->lastJsonLine());
        $this->assertSame(0, ElectricityFuturesEodPrice::count());
        $this->assertSame($running, DataFreshnessCheckpoint::sole()->only(['status', 'metadata']), 'One checkpoint, the fetch day\'s, unchanged.');
    }

    public function test_a_replay_whose_store_fails_takes_the_checkpoint_over_as_failed(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        Http::fake(fn (Request $request) => $this->eexResponse($request));
        Artisan::call('futures:fetch-eex', ['--fetch-only' => true]);
        $payload = $this->lastJsonLine()['payload'];
        $fetchOwner = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];
        $preRun = $this->seedPreRunMonthRow();
        $this->failYearInserts();
        $writes = [];
        DataFreshnessCheckpoint::saved(function (DataFreshnessCheckpoint $checkpoint) use (&$writes): void {
            $writes[] = [$checkpoint->status, $checkpoint->metadata['run_uuid']];
        });

        $this->preventHttp();
        $this->assertSame(1, Artisan::call('futures:fetch-eex', ['--from-payload' => $payload['path']]));

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $replayOwner = $checkpoint->metadata['run_uuid'];
        $this->assertNotSame($fetchOwner, $replayOwner);
        // S11: one takeover before the data transaction; the failure finishes that same owner.
        $this->assertSame([['running', $replayOwner], ['failed', $replayOwner]], $writes);
        $this->assertSame(['path' => $payload['path'], 'sha256' => $payload['sha256']], $checkpoint->metadata['payload']);
        $this->assertSame(['store', 'store_failed', '23000'], [$checkpoint->metadata['step'], $checkpoint->metadata['error'], $checkpoint->metadata['sqlstate']]);
        $this->assertSame($preRun, $this->storedRows());
        $this->assertImportIssue('eex_futures', 'error', ['unexpected' => 1]);
    }

    /**
     * The real command, service and cache lifecycle run; the failure is injected at the real
     * cache demand write after the store committed. Run B starts at that moment, before A's
     * failure handling, and A must not replace B's checkpoint.
     */
    public function test_a_real_post_commit_cache_failure_preserves_the_newer_eex_owner(): void
    {
        $this->fiBaseTenorInstruments();
        Http::fake(fn (Request $request) => $this->eexResponse($request));
        Artisan::call('futures:fetch-eex', ['--fetch-only' => true]);
        $payload = $this->lastJsonLine()['payload'];
        $fetchOwner = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $demand = $lifecycle->demandRevision();

        $replayOwner = null;
        Event::listen(WritingKey::class, function (WritingKey $event) use (&$replayOwner): void {
            if ($event->key !== ContractPriceCacheLifecycle::DEMAND_KEY || $replayOwner !== null) {
                return;
            }
            $replayOwner = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];
            // Run B starts after replay A committed and before A's failure handler runs.
            app(MorningJobFreshnessService::class)->record(DataFreshnessCheckpoint::KEY_EEX_FUTURES, '2026-05-22', DataFreshnessCheckpoint::STATUS_RUNNING, ['run_uuid' => 'newer-run', 'stage' => 'started']);

            throw new \RuntimeException('cache unavailable');
        });

        $this->preventHttp();
        $this->assertSame(1, Artisan::call('futures:fetch-eex', ['--from-payload' => $payload['path']]));

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'after_commit_failed'], $this->lastJsonLine());
        $this->assertTrue(Str::isUuid($replayOwner), 'The real demand write was reached.');
        $this->assertNotSame($fetchOwner, $replayOwner);
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_RUNNING, $checkpoint->status, 'Run B keeps the date.');
        $this->assertSame(['run_uuid' => 'newer-run', 'stage' => 'started'], $checkpoint->metadata);
        $this->assertSame(6, ElectricityFuturesEodPrice::count(), 'A committed before its cache demand write failed.');
        $this->assertSame($demand, $lifecycle->demandRevision(), 'The failed write recorded no demand.');
    }

    public function test_a_database_error_in_the_final_checkpoint_rolls_back_the_run_and_reports_its_sql_facts(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        $fetchOwner = null;
        Http::fake(function (Request $request) use (&$fetchOwner) {
            $fetchOwner ??= DataFreshnessCheckpoint::sole()->metadata['run_uuid'];

            return $this->eexResponse($request);
        });
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $demand = $lifecycle->demandRevision();
        $preRun = $this->seedPreRunMonthRow();
        // A statement-level failure of the final `ready` write; the later `failed` write works.
        DB::statement("CREATE TRIGGER fail_eex_ready BEFORE UPDATE ON data_freshness_checkpoints WHEN NEW.status = 'ready' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('futures:fetch-eex'));
        $output = Artisan::output();

        $storeError = ['sqlstate' => '23000', 'driver_code' => 19, 'table' => 'data_freshness_checkpoints'];
        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', ...$storeError], $this->lastJsonLine($output));
        $this->assertStringNotContainsString('forced failure', $output);
        $this->assertSame($preRun, $this->storedRows(), 'S21: the run\'s rows roll back with the checkpoint write.');
        $this->assertSame($demand, $lifecycle->demandRevision(), 'After-commit work does not run.');

        $payloads = glob(config('import_payloads.path').'/eex/2026-05-22/*.ndjson.gz');
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame([
            'run_uuid' => $fetchOwner, 'reason' => 'unexpected', 'step' => 'store', 'error' => 'store_failed',
            'payload' => ['path' => $payloads[0], 'sha256' => trim(file_get_contents(str_replace('.ndjson.gz', '.sha256', $payloads[0])))],
            ...$storeError,
        ], $checkpoint->metadata);
        $this->assertImportIssue('eex_futures', 'error', ['unexpected' => 1]);
        $this->assertSame($storeError, $this->sentryIssues[0]->getContexts()['data_fetch']['store_error']);
    }

    public function test_a_payload_whose_month_discovery_failed_stores_the_same_not_ready_outcome(): void
    {
        $this->captureSentryIssues();
        $this->fiBaseTenorInstruments();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'price-ticker') && $request['shortCode'] === 'FNBM') {
                throw new ConnectionException('private connection detail');
            }

            return $this->eexResponse($request);
        });

        $this->assertSame(0, Artisan::call('futures:fetch-eex', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(3, $payload['failed_units'], 'The main probe and both retry probes are recorded.');
        $this->assertSame([], $this->sentryIssues, 'The fetch step reports nothing; the store decides the outcome.');
        Sleep::assertSleptTimes(2);

        Sleep::fake();
        $this->preventHttp();
        $this->assertSame(1, Artisan::call('futures:fetch-eex', ['--from-payload' => $payload['path']]));
        Http::assertNothingSent();
        Sleep::assertNeverSlept();

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete'], [$line['status'], $line['step'], $line['error']]);
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame([1, 1, 2], [$checkpoint->metadata['failures'], $checkpoint->metadata['failures_before_retry'], $checkpoint->metadata['retry_passes']]);
        $this->assertSame(
            ['month' => null, 'quarter' => '2026-05-21', 'year' => '2026-05-21'],
            $checkpoint->metadata['current_run_latest_prior_fi_trade_dates_by_tenor'],
        );
        $this->assertSame(4, ElectricityFuturesEodPrice::count(), 'Quarter and year rows are stored, as before.');
        $this->assertImportIssue('eex_futures', 'error', ['discovery' => 1, 'missing_current_run_prior_fi' => 1]);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame([ConnectionException::class], $context['exception_classes']);
        $this->assertSame(['discovery' => 'fetch', 'missing_current_run_prior_fi' => 'store'], $context['steps']);
        $this->assertStringNotContainsString('private', json_encode($context));
    }

    public function test_retry_pass_lines_resolve_to_the_last_answered_body(): void
    {
        $this->fiBaseTenorInstruments();
        $yearRequests = 0;
        Http::fake(function (Request $request) use (&$yearRequests) {
            if (str_contains($request->url(), 'chart/eod') && $request['shortCode'] === 'FNBY' && ++$yearRequests === 1) {
                // A failed answer whose body would parse: the store must not use it.
                return Http::response(['series' => [['serieName' => 'settlPx', 'timeAndValue' => [['2026-05-21', 99.99]]]]], 503);
            }

            return $this->eexResponse($request);
        });

        $this->assertSame(0, Artisan::call('futures:fetch-eex'));

        $path = $this->lastJsonLine()['payload']['path'];
        $yearLines = collect(iterator_to_array(ImportPayloadReader::open($path)->requests(), false))
            ->filter(fn (array $line) => ($line['unit']['kind'] ?? null) === 'eod' && $line['unit']['short_code'] === 'FNBY');
        $this->assertSame([503, 200], $yearLines->pluck('status')->values()->all());
        $this->assertEqualsWithDelta(47.44, ElectricityFuturesEodPrice::query()->where('short_code', 'FNBY')->where('trade_date', '2026-05-21')->sole()->settlement_price, 0.0001);
        $this->assertDatabaseMissing('electricity_futures_eod_prices', ['settlement_price' => 99.99]);
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $checkpoint->status);
        $this->assertSame([0, 1, 1], [$checkpoint->metadata['failures'], $checkpoint->metadata['failures_before_retry'], $checkpoint->metadata['retry_passes']]);
    }

    public function test_a_dry_run_payload_replays_only_as_a_dry_run_and_writes_nothing(): void
    {
        $this->fiBaseTenorInstruments();
        Http::fake(fn (Request $request) => $this->eexResponse($request));

        $this->assertSame(0, Artisan::call('futures:fetch-eex', ['--dry-run' => true, '--fetch-only' => true]));
        $path = $this->lastJsonLine()['payload']['path'];

        $this->preventHttp();
        $this->assertSame(1, Artisan::call('futures:fetch-eex', ['--from-payload' => $path]));
        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'options_mismatch'], $this->lastJsonLine());

        $this->assertSame(0, Artisan::call('futures:fetch-eex', ['--from-payload' => $path, '--dry-run' => true]));
        $this->assertStringContainsString('[dry-run] FI month FNBM 202605: 2 prices', Artisan::output());
        $this->assertSame(0, ElectricityFuturesEodPrice::count());
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
    }

    /**
     * A row from before the run with the key of the run's first month point, at another price.
     *
     * @return list<array<string, mixed>>
     */
    private function seedPreRunMonthRow(): array
    {
        DB::table('electricity_futures_eod_prices')->insert([
            'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base',
            'area' => 'FI', 'short_code' => 'FNBM', 'maturity' => '202605', 'maturity_type' => 'month',
            'trade_date' => '2026-05-20', 'settlement_price' => 10.0,
        ]);

        return $this->storedRows();
    }

    /** Fail the run's last upsert (year) after its month and quarter upserts succeeded. */
    private function failYearInserts(): void
    {
        DB::statement("CREATE TRIGGER fail_eex_prices BEFORE INSERT ON electricity_futures_eod_prices WHEN NEW.short_code = 'FNBY' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
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

    private function fiBaseTenorInstruments(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, 'Europe/Helsinki'));
        config()->set('eex_futures.instruments', [
            ['market_region' => 'Nordics', 'area' => 'FI', 'area_name' => 'Finland', 'maturity_type' => 'month', 'short_code' => 'FNBM'],
            ['market_region' => 'Nordics', 'area' => 'FI', 'area_name' => 'Finland', 'maturity_type' => 'quarter', 'short_code' => 'FNBQ'],
            ['market_region' => 'Nordics', 'area' => 'FI', 'area_name' => 'Finland', 'maturity_type' => 'year', 'short_code' => 'FNBY'],
        ]);
        config()->set('eex_futures.months_back', 0);
        config()->set('eex_futures.months_ahead', 1);
        config()->set('eex_futures.quarters_ahead', 1);
        config()->set('eex_futures.years_ahead', 1);
        config()->set('eex_futures.retry_times', 0);
    }

    private function eexResponse(Request $request): PromiseInterface
    {
        if (str_contains($request->url(), 'price-ticker')) {
            return Http::response(['data' => [['2026-05-21T19:00:00.000Z', 47.44]]]);
        }

        return Http::response([
            'series' => [[
                'serieName' => 'settlPx',
                'timeAndValue' => [['2026-05-20', 47.00], ['2026-05-21', 47.44]],
            ]],
        ]);
    }
}
