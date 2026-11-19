<?php

namespace Tests\Feature;

use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\Jao\JaoNordicPublicationService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchJaoMaxBorderFlowsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 7, 30, 0, 'UTC'));
        config([
            'jao.request_delay_seconds' => 0,
            'jao.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Trimmed from a real 2026-10-30 response (15-minute MTU). */
    private function mtu(string $time, float $se1Fi, string $modified = '2026-10-29T08:14:59.51'): array
    {
        return [
            'id' => 62424,
            'dateTimeUtc' => $time,
            'lastModifiedOn' => $modified,
            'border_SE1_FI' => $se1Fi,
            'border_SE3_FI' => 1200.0,
            'border_EE_FI' => 1016.0,
            'border_NO4_FI' => 57.0,
            'border_FI_SE1' => 1698.0,
            'border_FI_SE3' => 1200.0,
            'border_FI_EE' => 1016.0,
            'border_FI_NO4' => 42.0,
            'border_SE2_SE3' => 7459.0,
        ];
    }

    public function test_stores_hourly_mean_and_minimum_per_border_in_the_default_window(): void
    {
        Http::fake([
            'publicationtool.jao.eu/nordic/api/data/maxBorderFlow*' => Http::sequence()
                ->push(['data' => [
                    $this->mtu('2026-10-30T00:00:00Z', 1800.0),
                    $this->mtu('2026-10-30T00:15:00Z', 1800.0),
                    $this->mtu('2026-10-30T00:30:00Z', 1884.0),
                    $this->mtu('2026-10-30T00:45:00Z', 1799.0, '2026-10-29T09:01:00'),
                ], 'rejected' => false, 'messages' => null])
                ->push(['data' => [], 'rejected' => false, 'messages' => null]),
        ]);

        $this->artisan('jao:fetch')->assertExitCode(0);

        $this->assertSame(8, DB::table('jao_max_border_flows')->count());
        $se1 = DB::table('jao_max_border_flows')->where('border', 'SE1_FI')->first();
        $this->assertEqualsWithDelta(1820.75, $se1->capacity_mw, 0.0001);
        $this->assertEqualsWithDelta(1799.0, $se1->min_capacity_mw, 0.0001);
        $this->assertSame(4, (int) $se1->sample_count);
        $this->assertSame('2026-10-30 00:00:00', $se1->utc_datetime);
        $this->assertSame(Carbon::parse('2026-10-30T00:00:00Z')->timestamp, (int) $se1->timestamp);
        $this->assertSame('2026-10-29T09:01:00', $se1->last_modified_on);
        $this->assertFalse(DB::table('jao_max_border_flows')->where('border', 'like', '%SE2%')->exists());

        // Default window: UTC days 2026-10-30 .. 2026-11-02 in two 48-hour requests.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'FromUtc=2026-10-30T00%3A00%3A00.000Z')
            && str_contains($request->url(), 'ToUtc=2026-11-01T00%3A00%3A00.000Z'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'FromUtc=2026-11-01T00%3A00%3A00.000Z')
            && str_contains($request->url(), 'ToUtc=2026-11-03T00%3A00%3A00.000Z'));
    }

    public function test_rerun_replaces_values_and_hourly_mtu_has_one_sample(): void
    {
        Http::fakeSequence('publicationtool.jao.eu/*')
            ->push(['data' => [$this->mtu('2025-06-01T10:00:00Z', 1500.0)]])
            ->push(['data' => [$this->mtu('2025-06-01T10:00:00Z', 1600.0)]]);

        $this->artisan('jao:fetch --from=2025-06-01 --to=2025-06-01')->assertExitCode(0);
        $this->artisan('jao:fetch --from=2025-06-01 --to=2025-06-01')->assertExitCode(0);

        $se1 = DB::table('jao_max_border_flows')->where('border', 'SE1_FI')->get();
        $this->assertCount(1, $se1);
        $this->assertEqualsWithDelta(1600.0, $se1[0]->capacity_mw, 0.0001);
        $this->assertSame(1, (int) $se1[0]->sample_count);
    }

    public function test_backfill_splits_into_two_day_windows_and_a_failed_window_fails_the_run(): void
    {
        Http::fakeSequence('publicationtool.jao.eu/*')
            ->push(['data' => [$this->mtu('2026-10-01T00:00:00Z', 1800.0)]])
            ->push(['title' => 'boom'], 500)
            ->push(['title' => 'boom'], 500)
            ->push(['title' => 'boom'], 500)
            ->push(['data' => [$this->mtu('2026-10-05T00:00:00Z', 1700.0)]]);

        $this->artisan('jao:fetch --from=2026-10-01 --to=2026-10-06')->assertExitCode(1);

        // 3 windows: 01-02 ok, 03-04 fails after 3 attempts, 05-06 ok.
        Http::assertSentCount(5);
        $this->assertSame(
            ['2026-10-01 00:00:00', '2026-10-05 00:00:00'],
            DB::table('jao_max_border_flows')->where('border', 'SE1_FI')->orderBy('timestamp')->pluck('utc_datetime')->all()
        );
    }

    public function test_rejects_invalid_dates_without_requests(): void
    {
        Http::fake();

        foreach (['2026-13-01', '2026-11-05'] as $from) {
            $this->assertSame(1, Artisan::call('jao:fetch', ['--from' => $from]));
            $this->assertSame(['status' => 'failed', 'step' => 'fetch', 'error' => 'invalid_options'], $this->lastJsonLine());
        }
        Http::assertNothingSent();
        $this->assertSame([], glob(config('import_payloads.path').'/jao/*'));
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->fakeJao(1800.0);

        $this->assertSame(0, Artisan::call('jao:fetch'));
        $defaultRows = $this->storedRows();
        $this->assertSame(['hourly_rows' => 8, 'failed_windows' => 0], $this->lastJsonLine()['counts']);

        DB::table('jao_max_border_flows')->delete();
        $this->assertSame(0, Artisan::call('jao:fetch', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, DB::table('jao_max_border_flows')->count(), 'Fetch only writes no rows.');
        $this->assertSame(2, $payload['requests']);

        // A store on the next day keeps the fetch time (S5); the window came from the header.
        Carbon::setTestNow(Carbon::create(2026, 11, 2, 9, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('jao:fetch', ['--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256']]));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        $this->assertSame(['2026-11-01 07:30:00'], DB::table('jao_max_border_flows')->distinct()->pluck('fetched_at')->all());
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        Http::fakeSequence('publicationtool.jao.eu/*')
            ->push(['data' => [$this->mtu('2026-10-30T00:00:00Z', 1800.0)]])
            ->push(['data' => [$this->mtu('2026-11-01T05:00:00Z', 1777.0)]]);
        Log::spy();
        // The second window's upsert fails after the first succeeded inside the same transaction.
        DB::statement("CREATE TRIGGER fail_jao BEFORE INSERT ON jao_max_border_flows WHEN NEW.utc_datetime = '2026-11-01 05:00:00' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('jao:fetch'));
        $output = Artisan::output();

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'jao_max_border_flows'], $this->lastJsonLine($output));
        foreach (['forced failure', '1777', '2026-11-01 05:00:00'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, DB::table('jao_max_border_flows')->count(), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: jao:fetch', $message);
            $this->assertSame(['23000', 19, 'jao_max_border_flows', 'store'], [$context['sqlstate'], $context['driver_code'], $context['table'], $context['step']]);
            $this->assertStringNotContainsString('forced failure', json_encode($context));
            $this->assertStringNotContainsString('1777', json_encode($context));

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/jao/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_jao'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('jao:fetch', ['--from-payload' => $payloads[0]]));
        $this->assertSame(16, DB::table('jao_max_border_flows')->count());
        Http::assertNothingSent();
    }

    public function test_a_rejected_body_stays_in_the_payload_and_a_corrected_parser_stores_it_without_http(): void
    {
        Http::fakeSequence('publicationtool.jao.eu/*')
            ->push(['data' => [$this->mtu('2026-10-01T00:00:00Z', 1800.0)]])
            ->push(['title' => 'boom'], 500)
            ->push(['title' => 'boom'], 500)
            ->push(['title' => 'boom'], 500)
            // A changed envelope that today's parser rejects.
            ->push(['rows' => [$this->mtu('2026-10-05T00:00:00Z', 1700.0)]]);
        $options = ['--from' => '2026-10-01', '--to' => '2026-10-06'];

        // Only the transport failure is a failed unit; the answered body is kept unparsed.
        $this->assertSame(0, Artisan::call('jao:fetch', [...$options, '--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        $this->assertSame([3, 1, false], [$reference['requests'], $reference['failed_units'], $reference['complete']]);
        $saved = collect(ImportPayloadReader::open($reference['path'])->requests())->firstWhere('unit.from', '2026-10-05T00:00:00Z');
        $this->assertSame(200, $saved['status']);
        $this->assertStringContainsString('"rows"', ImportPayloadReader::body($saved));

        // The current parser rejects that window in the store step and writes none of its rows.
        $this->assertSame(1, Artisan::call('jao:fetch', [...$options, '--from-payload' => $reference['path']]));
        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete'], [$line['status'], $line['step'], $line['error']]);
        $this->assertSame(['hourly_rows' => 8, 'failed_windows' => 2], $line['counts']);
        $this->assertSame(['2026-10-01 00:00:00'], DB::table('jao_max_border_flows')->where('border', 'SE1_FI')->pluck('utc_datetime')->all());

        // After a parser fix, the same file stores the window without a new request.
        $this->app->instance(JaoNordicPublicationService::class, new class extends JaoNordicPublicationService
        {
            public function parse(string $body): array
            {
                $rows = json_decode($body, true)['rows'] ?? null;

                return is_array($rows) ? $rows : parent::parse($body);
            }
        });
        $this->app[Kernel::class]->setArtisan(null); // resolve the command again with the fixed parser
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('jao:fetch', [...$options, '--from-payload' => $reference['path']]));
        Http::assertNothingSent();
        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete'], [$line['status'], $line['step'], $line['error']]);
        $this->assertSame(['hourly_rows' => 16, 'failed_windows' => 1], $line['counts']);
        $this->assertSame(
            ['2026-10-01 00:00:00', '2026-10-05 00:00:00'],
            DB::table('jao_max_border_flows')->where('border', 'SE1_FI')->orderBy('timestamp')->pluck('utc_datetime')->all()
        );
    }

    /**
     * S9: this source is not date-bound. An older payload from the same day is accepted and its
     * upsert puts the older values (and its older fetched_at) back until the next fetch.
     */
    public function test_an_older_same_day_payload_is_accepted_and_restores_its_values(): void
    {
        $this->fakeJao(1800.0);
        Artisan::call('jao:fetch');
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 12, 0, 0, 'UTC'));
        $this->fakeJao(1500.0);
        Artisan::call('jao:fetch');
        $this->assertEqualsWithDelta(1500.0, DB::table('jao_max_border_flows')->where('border', 'SE1_FI')->value('capacity_mw'), 0.0001);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('jao:fetch', ['--from-payload' => $older]));

        $row = DB::table('jao_max_border_flows')->where('border', 'SE1_FI')->sole();
        $this->assertEqualsWithDelta(1800.0, $row->capacity_mw, 0.0001);
        $this->assertSame('2026-11-01 07:30:00', $row->fetched_at);
    }

    private function fakeJao(float $se1Fi): void
    {
        Http::swap(new Factory);
        Http::fakeSequence('publicationtool.jao.eu/*')
            ->push(['data' => [$this->mtu('2026-10-30T00:00:00Z', $se1Fi)]])
            ->push(['data' => []])
            ->push(['data' => [$this->mtu('2026-10-30T00:00:00Z', $se1Fi)]])
            ->push(['data' => []])
            ->push(['data' => [$this->mtu('2026-10-30T00:00:00Z', $se1Fi)]])
            ->push(['data' => []]);
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        return DB::table('jao_max_border_flows')->orderBy('border')->orderBy('timestamp')->get()
            ->map(fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at'])))->all();
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
