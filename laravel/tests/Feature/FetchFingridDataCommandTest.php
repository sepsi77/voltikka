<?php

namespace Tests\Feature;

use App\Services\Fingrid\FingridHourlyImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /** Every dataset answers one row per listed start time inside the requested range, value dataset ID + $offset. */
    private function fakeFingrid(float $offset = 0.0, array $starts = ['2026-10-31T10:00:00.000Z']): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($offset, $starts) {
            preg_match('#/datasets/(\d+)/data#', $request->url(), $match);
            $dataset = (int) $match[1];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $inRange = fn (string $start) => Carbon::parse($start)->betweenIncluded(Carbon::parse($query['startTime']), Carbon::parse($query['endTime']));

            return Http::response([
                'data' => array_values(array_map(fn (string $start) => $this->row($dataset, $start, $dataset + $offset), array_filter($starts, $inRange))),
                'pagination' => ['nextPage' => null],
            ]);
        });
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        // The plain form of the S forecast workflow and the collectors schedule: every dataset.
        $this->fakeFingrid();
        $this->assertSame(0, Artisan::call('fingrid:fetch'));
        $datasets = count(config('fingrid.datasets'));
        $line = $this->lastJsonLine();
        $this->assertSame(['stored', ['hourly_rows' => $datasets]], [$line['status'], $line['counts']]);
        $defaultRows = $this->storedRows();
        $this->assertCount($datasets, $defaultRows);

        DB::table('fingrid_hourly_values')->delete();
        $this->assertSame(0, Artisan::call('fingrid:fetch', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, DB::table('fingrid_hourly_values')->count(), 'Fetch only writes no rows.');
        $this->assertSame('2026-11-01T08:00:00Z', $this->payloadLines($payload['path'])[0]['facts']['current_hour']);

        // The store runs two hours later and keeps the fetch time (S5).
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 10, 30, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('fingrid:fetch', ['--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256']]));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        $this->assertSame(['2026-11-01 08:30:00'], DB::table('fingrid_hourly_values')->distinct()->pluck('fetched_at')->all());
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        $this->fakeFingrid(0.25);
        Log::spy();
        // Dataset 191 fails after dataset 124 was upserted in the same transaction.
        DB::statement("CREATE TRIGGER fail_hydro BEFORE INSERT ON fingrid_hourly_values WHEN NEW.dataset_id = 191 BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('fingrid:fetch --dataset=124 --dataset=191'));
        $output = Artisan::output();
        $payloads = glob(config('import_payloads.path').'/fingrid/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
            'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'fingrid_hourly_values',
        ], $this->lastJsonLine($output));
        foreach (['forced failure', '191.25', '124.25'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, DB::table('fingrid_hourly_values')->count(), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: fingrid:fetch', $message);
            $this->assertSame(['store', '23000', 19, 'fingrid_hourly_values'], [$context['step'], $context['sqlstate'], $context['driver_code'], $context['table']]);
            foreach (['forced failure', '191.25', '124.25', 'test-key'] as $binding) {
                $this->assertStringNotContainsString($binding, json_encode($context));
            }

            return true;
        });

        DB::statement('DROP TRIGGER fail_hydro'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=124 --dataset=191 --from-payload='.$payloads[0]));
        Http::assertNothingSent();
        $this->assertSame([124, 191], DB::table('fingrid_hourly_values')->orderBy('dataset_id')->pluck('dataset_id')->map(fn ($id) => (int) $id)->all());
    }

    /** A backfill commits once per 7-day window (S21): a later window's store fault keeps the earlier window. */
    public function test_a_backfill_store_commits_per_window(): void
    {
        $this->fakeFingrid(0.0, ['2026-10-02T10:00:00.000Z', '2026-10-10T10:00:00.000Z']);
        $second = Carbon::parse('2026-10-10 10:00:00', 'UTC')->timestamp;
        DB::statement("CREATE TRIGGER fail_second_window BEFORE INSERT ON fingrid_hourly_values WHEN NEW.timestamp = {$second} BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('fingrid:fetch --dataset=124 --from=2026-10-01 --to=2026-10-14'));
        $this->assertSame('store_failed', $this->lastJsonLine()['error']);
        $this->assertSame(1, DB::table('fingrid_hourly_values')->count(), 'The first window is committed.');

        DB::statement('DROP TRIGGER fail_second_window');
        $path = glob(config('import_payloads.path').'/fingrid/2026-11-01/*.ndjson.gz')[0];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=124 --from=2026-10-01 --to=2026-10-14 --from-payload='.$path));
        $this->assertSame(2, DB::table('fingrid_hourly_values')->count());
    }

    public function test_the_api_key_never_reaches_the_payload(): void
    {
        $this->fakeFingrid();

        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=188 --fetch-only'));

        $path = $this->lastJsonLine()['payload']['path'];
        $this->assertStringNotContainsString('test-key', (string) gzdecode((string) file_get_contents($path)));
        $request = $this->payloadLines($path)[1];
        $this->assertSame(['dataset' => 188, 'window' => 0, 'page' => 1], $request['unit']);
        $this->assertSame('2026-11-01T08:00:00Z', $request['query']['endTime']);
    }

    /**
     * Replay stores only the newest payload: an older same-day payload is refused (superseded)
     * while a newer payload of the same options exists, so its values cannot replace the newer
     * upserted rows.
     */
    public function test_an_older_same_day_payload_is_refused_when_a_newer_payload_exists(): void
    {
        $this->fakeFingrid(0.5);
        Artisan::call('fingrid:fetch --dataset=188 --fetch-only');
        $older = $this->lastJsonLine()['payload'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 9, 30, 0, 'UTC'));
        $this->fakeFingrid(0.75);
        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=188'));

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 12, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('fingrid:fetch --dataset=188 --from-payload='.$older['path']));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $older['path'], 'payload_sha256' => $older['sha256'],
        ], $this->lastJsonLine());
        $row = DB::table('fingrid_hourly_values')->sole();
        $this->assertEqualsWithDelta(188.75, $row->value, 0.0001);
        $this->assertSame('2026-11-01 09:30:00', $row->fetched_at);
    }

    /** The payload identity holds normalized dataset IDs: `0188` and `188` are one option set. */
    public function test_dataset_ids_compare_normalized_for_supersede_and_replay(): void
    {
        $this->fakeFingrid(0.5);
        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=0188 --fetch-only'));
        $older = $this->lastJsonLine()['payload'];
        $this->assertSame([188], $this->payloadLines($older['path'])[0]['options']['dataset']);

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 9, 30, 0, 'UTC'));
        $this->fakeFingrid(0.75);
        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=188'));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        // Not options_mismatch: the older payload is refused only because the newer one supersedes it.
        $this->assertSame(1, Artisan::call('fingrid:fetch --dataset=188 --from-payload='.$older['path']));
        $line = $this->lastJsonLine();
        $this->assertSame(['payload_invalid', 'superseded'], [$line['error'], $line['reason']]);
        $this->assertEqualsWithDelta(188.75, DB::table('fingrid_hourly_values')->sole()->value, 0.0001);
    }

    public function test_a_payload_replays_after_its_dataset_was_removed_from_config(): void
    {
        $this->fakeFingrid(0.5);
        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=188 --fetch-only'));
        $payload = $this->lastJsonLine()['payload'];

        config(['fingrid.datasets' => []]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=188 --from-payload='.$payload['path']));
        $this->assertSame(1, DB::table('fingrid_hourly_values')->count());
        // A new fetch still needs a configured dataset.
        $this->assertSame(1, Artisan::call('fingrid:fetch --dataset=188'));
        $this->assertSame('invalid_options', $this->lastJsonLine()['error']);
    }

    /**
     * A repeated or differently written dataset ID (188, 0188) is fetched once: a second copy
     * would merge its samples into the same window and a failed copy would hide the answered one.
     */
    public function test_a_repeated_dataset_option_fetches_the_dataset_once(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['data' => [$this->row(188, '2026-10-31T10:00:00.000Z', 1000.0)], 'pagination' => ['nextPage' => null]])
                : Http::response(['message' => 'boom'], 500);
        });

        $this->assertSame(0, Artisan::call('fingrid:fetch --dataset=188 --dataset=0188'));

        $this->assertSame(1, $calls);
        $row = DB::table('fingrid_hourly_values')->sole();
        $this->assertSame([1000.0, 1], [(float) $row->value, (int) $row->sample_count]);
    }

    public function test_a_failed_dataset_prints_fetch_incomplete_and_keeps_the_others(): void
    {
        Http::fake([
            'data.fingrid.fi/api/datasets/188/data*' => Http::response(['data' => [$this->row(188, '2026-10-31T10:00:00.000Z', 1.0)], 'pagination' => ['nextPage' => null]]),
            'data.fingrid.fi/api/datasets/191/data*' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->assertSame(1, Artisan::call('fingrid:fetch --dataset=188 --dataset=191'));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false, ['hourly_rows' => 1]], [$line['status'], $line['step'], $line['error'], $line['complete'], $line['counts']]);
        $this->assertSame(1, DB::table('fingrid_hourly_values')->where('dataset_id', 188)->count());
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        $strip = fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at']));

        return DB::table('fingrid_hourly_values')->orderBy('dataset_id')->orderBy('series_key')->orderBy('timestamp')->get()->map($strip)->all();
    }

    /** @return list<array<string, mixed>> */
    private function payloadLines(string $path): array
    {
        $lines = array_filter(explode("\n", (string) gzdecode((string) file_get_contents($path))));

        return array_values(array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
