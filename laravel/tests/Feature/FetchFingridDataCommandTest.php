<?php

namespace Tests\Feature;

use App\Services\Fingrid\FingridHourlyImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchFingridDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 8, 30, 0, 'UTC'));
        config([
            'fingrid.api_key' => 'test-key',
            'fingrid.request_delay_seconds' => 0,
            'fingrid.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(int $dataset, string $start, float $value, ?array $additional = null): array
    {
        return array_filter([
            'datasetId' => $dataset,
            'startTime' => $start,
            'endTime' => $start,
            'value' => $value,
            'additionalJson' => $additional,
        ], fn ($v) => $v !== null);
    }

    public function test_stores_hourly_means_of_power_samples(): void
    {
        Http::fake([
            'data.fingrid.fi/api/datasets/188/data*' => Http::response([
                'data' => [
                    $this->row(188, '2026-10-31T10:00:00.000Z', 1000.0),
                    $this->row(188, '2026-10-31T10:30:00.000Z', 1200.0),
                    $this->row(188, '2026-10-31T11:00:00.000Z', 1600.0),
                ],
                'pagination' => ['nextPage' => null],
            ]),
        ]);

        $this->artisan('fingrid:fetch --dataset=188')->assertExitCode(0);

        $rows = DB::table('fingrid_hourly_values')->orderBy('timestamp')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1100.0, $rows[0]->value, 0.0001);
        $this->assertSame(2, (int) $rows[0]->sample_count);
        $this->assertSame('2026-10-31 10:00:00', $rows[0]->utc_datetime);
        $this->assertSame('', $rows[0]->series_key);

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'test-key')
            && str_contains($request->url(), 'startTime=2026-10-29T08%3A00%3A00Z')
            && str_contains($request->url(), 'endTime=2026-11-01T08%3A00%3A00Z'));
    }

    public function test_datahub_breakdowns_get_their_own_series_and_a_longer_window(): void
    {
        $group = fn (string $userGroup, string $count) => [
            'TimeSeriesType' => 'CTT_AVG_CONS_ACP_USERGROUP_RESOLUTION',
            'Res' => 'PT1H',
            'Uom' => 'KWH',
            'UserGroup' => $userGroup,
            'ReadTS' => '2026-10-20T00:00:00Z',
            'Value' => '0.9',
            'Count' => $count,
        ];

        Http::fake([
            'data.fingrid.fi/api/datasets/364/data*' => Http::response([
                'data' => [
                    $this->row(364, '2026-10-20T00:00:00.000Z', 0.14508, $group('BE01', '1474918')),
                    $this->row(364, '2026-10-20T00:00:00.000Z', 0.93917, $group('BE02', '1042777')),
                ],
                'pagination' => ['nextPage' => null],
            ]),
        ]);

        $this->artisan('fingrid:fetch --dataset=364')->assertExitCode(0);

        $rows = DB::table('fingrid_hourly_values')->orderBy('series_key')->get();
        $this->assertSame(['UserGroup=BE01', 'UserGroup=BE02'], $rows->pluck('series_key')->all());
        $this->assertSame(1042777, (int) $rows[1]->point_count);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'startTime=2026-10-16T08%3A00%3A00Z'));
    }

    public function test_rerun_replaces_corrected_values_and_follows_pagination(): void
    {
        Http::fakeSequence('data.fingrid.fi/api/datasets/124/data*')
            ->push(['data' => [$this->row(124, '2026-10-31T10:00:00.000Z', 9000.0)], 'pagination' => ['nextPage' => 2]])
            ->push(['data' => [$this->row(124, '2026-10-31T10:15:00.000Z', 9100.0)], 'pagination' => ['nextPage' => null]])
            ->push(['data' => [$this->row(124, '2026-10-31T10:00:00.000Z', 9500.0)], 'pagination' => ['nextPage' => null]]);

        $this->artisan('fingrid:fetch --dataset=124')->assertExitCode(0);
        $this->assertEqualsWithDelta(9050.0, DB::table('fingrid_hourly_values')->value('value'), 0.0001);

        $this->artisan('fingrid:fetch --dataset=124')->assertExitCode(0);
        $this->assertSame(1, DB::table('fingrid_hourly_values')->count());
        $this->assertEqualsWithDelta(9500.0, DB::table('fingrid_hourly_values')->value('value'), 0.0001);
    }

    public function test_backfill_uses_seven_day_pieces_and_a_failed_dataset_fails_the_run(): void
    {
        Http::fake([
            'data.fingrid.fi/api/datasets/75/data*' => Http::response(['data' => [], 'pagination' => ['nextPage' => null]]),
            'data.fingrid.fi/api/datasets/191/data*' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->artisan('fingrid:fetch --dataset=75 --dataset=191 --from=2026-10-01 --to=2026-10-14')->assertExitCode(1);

        $windRequests = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/datasets/75/'));
        $this->assertCount(2, $windRequests);
    }

    public function test_rejects_unconfigured_dataset(): void
    {
        Http::fake();

        $this->artisan('fingrid:fetch --dataset=9999')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_series_key_ignores_value_attributes(): void
    {
        $key = app(FingridHourlyImporter::class)->seriesKey([
            'TimeSeriesType' => 'X', 'Res' => 'PT1H', 'Uom' => 'KWH', 'ReadTS' => 'x', 'Value' => '1', 'Count' => '2',
            'ProductionType' => 'AV06', 'CustomerType' => 'AB02',
        ]);

        $this->assertSame('CustomerType=AB02|ProductionType=AV06', $key);
    }
}
