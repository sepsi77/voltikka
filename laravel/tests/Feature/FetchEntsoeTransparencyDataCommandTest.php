<?php

namespace Tests\Feature;

use App\Services\EntsoeTransparency\EntsoeCurve;
use App\Services\EntsoeTransparency\OutageImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

class FetchEntsoeTransparencyDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../Fixtures/entsoe-transparency/';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 8, 30, 0, 'UTC'));
        config([
            'services.entsoe.api_key' => 'secret-token',
            'entsoe_transparency.request_delay_seconds' => 0,
            'entsoe_transparency.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'entsoe-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_daily_outage_run_stores_versions_fetches_earlier_revisions_and_keeps_cancelled(): void
    {
        $noData = $this->fixture('acknowledgement-no-data.xml');

        Http::fake(function (Request $request) use ($noData) {
            $q = $this->queryOf($request);
            if (($q['biddingZone_Domain'] ?? null) === '10YFI-1--------U' && $q['documentType'] === 'A80') {
                if (isset($q['mRID'])) {
                    return Http::response($this->zip([
                        '001.xml' => $this->fixture('outage-a80-ol3-rev7.xml'),
                        '002.xml' => $this->fixture('outage-a80-ol3-rev6.xml'),
                    ]), 200, ['Content-Type' => 'application/zip']);
                }

                return Http::response($this->zip(['001.xml' => $this->fixture('outage-a80-ol3-rev7.xml')]), 200, ['Content-Type' => 'application/zip']);
            }
            if (($q['biddingZone_Domain'] ?? null) === '10Y1001A1001A46L' && $q['documentType'] === 'A80') {
                return Http::response($this->zip(['001.xml' => $this->fixture('outage-a80-se3-cancelled.xml')]), 200, ['Content-Type' => 'application/zip']);
            }

            return Http::response($noData, 200, ['Content-Type' => 'text/xml']);
        });

        $this->artisan('entsoe:fetch --dataset=outages')->assertExitCode(0);

        $ol3 = DB::table('entsoe_outages')->where('resource_name', 'Olkiluoto 3')->orderBy('revision_number')->get();
        $this->assertSame([6, 7], $ol3->pluck('revision_number')->map(fn ($r) => (int) $r)->all());
        $latest = $ol3[1];
        $this->assertSame('FI', $latest->zone);
        $this->assertSame('A80', $latest->document_type);
        $this->assertSame('A53', $latest->business_type);
        $this->assertSame('B14', $latest->psr_type);
        $this->assertSame('Olkiluoto 3 B3', $latest->unit_name);
        $this->assertEqualsWithDelta(1600.0, $latest->nominal_mw, 0.001);
        $this->assertEqualsWithDelta(0.0, $latest->min_available_mw, 0.001);
        $this->assertSame('2026-09-09 22:00:00', $latest->start_utc);
        $this->assertSame('2026-11-07 16:00:00', $latest->end_utc);
        $this->assertFalse((bool) $latest->is_cancelled);
        $this->assertSame([['start' => '2026-09-09 22:00:00', 'end' => '2026-11-07 16:00:00', 'mw' => 0]], json_decode($latest->available_periods, true));
        $this->assertSame([['code' => 'A95', 'text' => 'Yearly maintenance'], ['code' => 'B19', 'text' => '']], json_decode($latest->reasons, true));
        $this->assertSame('2026-10-29 22:00:00', $ol3[0]->end_utc);

        $cancelled = DB::table('entsoe_outages')->where('zone', 'SE3')->first();
        $this->assertSame('Trängslet', $cancelled->resource_name);
        $this->assertSame('A09', $cancelled->doc_status);
        $this->assertTrue((bool) $cancelled->is_cancelled);

        // Daily window: update filter for the last 3 days, outage period one year back and two ahead.
        Http::assertSent(function (Request $request) {
            $q = $this->queryOf($request);

            return ($q['documentType'] ?? null) === 'A80' && ! isset($q['mRID'])
                && $q['periodStartUpdate'] === '202610290830' && $q['periodEndUpdate'] === '202611010830'
                && $q['periodStart'] === '202511010830' && $q['periodEnd'] === '202810310830'
                && $q['offset'] === '0';
        });
        // Version history query for OL3 (revision 6 missing): one year around the outage start.
        Http::assertSent(function (Request $request) {
            $q = $this->queryOf($request);

            return ($q['mRID'] ?? null) === 'coo--hR7U1v3g7eOuR4Tzg'
                && $q['periodStart'] === '202603112200' && $q['periodEnd'] === '202703102200';
        });
        // Transmission is queried per direction with in/out domains.
        Http::assertSent(function (Request $request) {
            $q = $this->queryOf($request);

            return ($q['documentType'] ?? null) === 'A78'
                && $q['out_Domain'] === '10Y1001A1001A39I' && $q['in_Domain'] === '10YFI-1--------U';
        });

        // Insert-only: a rerun adds nothing and does not change stored versions.
        $this->artisan('entsoe:fetch --dataset=outages --zone=FI')->assertExitCode(0);
        $this->assertSame(3, DB::table('entsoe_outages')->count());
    }

    public function test_transmission_outage_keeps_all_assets_under_the_border_key(): void
    {
        Http::fake(fn () => Http::response($this->zip(['001.xml' => $this->fixture('outage-a78-ee-fi-two-assets.xml')]), 200));

        $this->artisan('entsoe:fetch --dataset=outages --zone=EE_FI --from=2026-07-01 --to=2026-07-31')->assertExitCode(0);

        $row = DB::table('entsoe_outages')->sole();
        $this->assertSame('EE_FI', $row->zone);
        $this->assertSame('A78', $row->document_type);
        $this->assertSame('Estlink2', $row->resource_name);
        $this->assertNull($row->nominal_mw);
        $this->assertSame(['Estlink2', 'Estlink1'], array_column(json_decode($row->assets, true), 'name'));

        // Backfill uses a plain period query (no update filter).
        Http::assertSent(function (Request $request) {
            $q = $this->queryOf($request);

            return $q['periodStart'] === '202607010000' && $q['periodEnd'] === '202608010000'
                && ! isset($q['periodStartUpdate']);
        });
    }

    public function test_outage_query_pages_with_offset_when_a_page_is_full(): void
    {
        // Revision 1, so no version-history queries run.
        $template = str_replace('<revisionNumber>2</revisionNumber>', '<revisionNumber>1</revisionNumber>', $this->fixture('outage-a80-se3-cancelled.xml'));
        $page = [];
        for ($i = 1; $i <= 200; $i++) {
            $page["{$i}.xml"] = str_replace('WuQqOp7X1PEypTpXW4_9IA', "doc-{$i}", $template);
        }
        $fullPage = $this->zip($page);
        $lastPage = $this->zip(['1.xml' => str_replace('WuQqOp7X1PEypTpXW4_9IA', 'doc-201', $template)]);

        Http::fake(function (Request $request) use ($fullPage, $lastPage) {
            return Http::response($this->queryOf($request)['offset'] === '0' ? $fullPage : $lastPage, 200);
        });

        $this->artisan('entsoe:fetch --dataset=outages --zone=SE3 --from=2026-08-01 --to=2026-08-31')->assertExitCode(0);

        $this->assertSame(201, DB::table('entsoe_outages')->where('document_type', 'A80')->count());
        Http::assertSent(fn (Request $request) => ($this->queryOf($request)['offset'] ?? null) === '200');
    }

    public function test_generation_expands_a03_and_stores_hourly_means_per_direction(): void
    {
        $body = $this->fixture('generation-a75-de-lu.xml');
        Http::fake(function () use (&$body) {
            return Http::response($body, 200, ['Content-Type' => 'text/xml']);
        });

        $this->artisan('entsoe:fetch --dataset=generation --zone=DE-LU')->assertExitCode(0);

        $rows = DB::table('entsoe_generation_hourly')->orderBy('psr_type')->orderBy('direction')->orderBy('timestamp')->get();
        $value = fn (string $psr, string $direction, string $hour) => $rows->first(
            fn ($row) => $row->psr_type === $psr && $row->direction === $direction && $row->utc_datetime === $hour
        );

        // B09 sends one A03 point that lasts for the whole period.
        $this->assertEqualsWithDelta(19.0, $value('B09', 'generation', '2026-10-30 01:00:00')->value_mw, 0.0001);
        $this->assertSame(4, (int) $value('B09', 'generation', '2026-10-30 01:00:00')->sample_count);
        // B19 positions 3 and 4 are omitted: position 2 lasts until position 5.
        $this->assertEqualsWithDelta((3919.13831 + 3 * 3900.93352) / 4, $value('B19', 'generation', '2026-10-30 00:00:00')->value_mw, 0.0001);
        $this->assertEqualsWithDelta((4055.86252 + 4134.97083 + 4236.99457 + 4395.97754) / 4, $value('B19', 'generation', '2026-10-30 01:00:00')->value_mw, 0.0001);
        // Pumped-storage pumping (outBiddingZone) is stored apart from generation.
        $this->assertEqualsWithDelta((932.8818 + 1425.6861 + 1465.6119 + 1549.6046) / 4, $value('B10', 'consumption', '2026-10-30 00:00:00')->value_mw, 0.0001);
        $this->assertEqualsWithDelta((62.29 + 54.5394 + 56.6783 + 61.432) / 4, $value('B10', 'generation', '2026-10-30 00:00:00')->value_mw, 0.0001);
        $this->assertCount(8, $rows);
        $this->assertSame(Carbon::parse('2026-10-30 00:00:00', 'UTC')->timestamp, (int) $rows[0]->timestamp);

        Http::assertSent(function (Request $request) {
            $q = $this->queryOf($request);

            return $q['documentType'] === 'A75' && $q['processType'] === 'A16' && $q['in_Domain'] === '10Y1001A1001A82H'
                && $q['periodStart'] === '202610280800' && $q['periodEnd'] === '202611010800';
        });

        // ENTSO-E revises data: a rerun replaces the hour.
        $body = str_replace('<quantity>19</quantity>', '<quantity>25</quantity>', $body);
        $this->artisan('entsoe:fetch --dataset=generation --zone=DE-LU')->assertExitCode(0);
        $this->assertSame(8, DB::table('entsoe_generation_hourly')->count());
        $this->assertEqualsWithDelta(25.0, DB::table('entsoe_generation_hourly')->where('psr_type', 'B09')->value('value_mw'), 0.0001);
    }

    public function test_reservoir_weeks_keep_their_monday_start_across_dst(): void
    {
        Http::fake(fn () => Http::response($this->fixture('reservoir-a72-no.xml'), 200));

        $this->artisan('entsoe:fetch --dataset=reservoirs --zone=NO')->assertExitCode(0);

        $rows = DB::table('entsoe_reservoir_weeks')->orderBy('timestamp')->get();
        $this->assertCount(13, $rows);
        $this->assertSame('2026-07-26 22:00:00', $rows[0]->week_start_utc);
        $this->assertSame([2026, 31], [(int) $rows[0]->iso_year, (int) $rows[0]->iso_week]);
        $this->assertEqualsWithDelta(55055761.0, $rows[0]->stored_energy_mwh, 0.5);
        // Week 43 starts Monday 2026-10-19 00:00 CEST.
        $this->assertSame('2026-10-18 22:00:00', $rows[12]->week_start_utc);
        $this->assertSame(43, (int) $rows[12]->iso_week);
    }

    public function test_a_failed_zone_does_not_stop_others_and_logs_no_token(): void
    {
        Log::spy();
        Http::fake(function (Request $request) {
            if ($this->queryOf($request)['in_Domain'] === '10YSE-1--------K') {
                return Http::response('<html>stalled</html>', 503);
            }

            return Http::response($this->fixture('reservoir-a72-no.xml'), 200);
        });

        $this->artisan('entsoe:fetch --dataset=reservoirs')->assertExitCode(1);

        // NO and FI succeeded (both answered with the fixture, stored under their own zone).
        $this->assertSame(['FI', 'NO'], DB::table('entsoe_reservoir_weeks')->distinct()->orderBy('zone')->pluck('zone')->all());
        // Every failed attempt is retried (3 attempts).
        $this->assertCount(3, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '10YSE-1--------K')));
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
            return $message === 'ENTSO-E transparency fetch failed'
                && $context['zone'] === 'SE' && $context['document_type'] === 'A72'
                && ! str_contains(json_encode($context), 'secret-token');
        });
    }

    public function test_rejects_unknown_dataset_and_zone(): void
    {
        Http::fake();

        $this->artisan('entsoe:fetch --dataset=prices')->assertExitCode(1);
        $this->artisan('entsoe:fetch --dataset=generation --zone=FI')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_curve_segments_expand_a03_minute_points(): void
    {
        $xml = new SimpleXMLElement($this->fixture('outage-a77-fi-aanekoski.xml'));
        $period = $xml->TimeSeries->Available_Period;

        $this->assertSame([
            ['start' => '2026-01-07 16:00:00', 'end' => '2026-01-09 08:30:00', 'mw' => 0.0],
            ['start' => '2026-01-09 08:30:00', 'end' => '2026-01-09 09:00:00', 'mw' => 40.0],
            ['start' => '2026-01-09 09:00:00', 'end' => '2026-01-09 10:00:00', 'mw' => 80.0],
        ], EntsoeCurve::segments($period, 'A03'));
    }

    /** The argument form of the S forecast workflow (orchestration s_forecast.py OUTAGE_FETCH). */
    private const S_FORECAST_OUTAGES = 'entsoe:fetch --dataset=outages --zone=FI --zone=SE3 --zone=SE1_FI --zone=SE3_FI --zone=EE_FI';

    /**
     * FI A80 answers rev 7 of Olkiluoto 3 (rev 6 through its mRID history query), SE3 A80 a
     * cancelled version, reservoirs the NO fixture with $reservoirValue; everything else "no data".
     */
    private function fakeEntsoe(?string $reservoirValue = null): void
    {
        $noData = $this->fixture('acknowledgement-no-data.xml');
        $reservoir = $this->fixture('reservoir-a72-no.xml');
        if ($reservoirValue !== null) {
            $reservoir = preg_replace('#<quantity>[^<]*</quantity>#', "<quantity>{$reservoirValue}</quantity>", $reservoir, 1);
        }

        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($noData, $reservoir) {
            $q = $this->queryOf($request);
            if ($q['documentType'] === 'A72') {
                return Http::response($reservoir, 200);
            }
            if (($q['biddingZone_Domain'] ?? null) === '10YFI-1--------U' && $q['documentType'] === 'A80') {
                return Http::response($this->zip(isset($q['mRID'])
                    ? ['001.xml' => $this->fixture('outage-a80-ol3-rev7.xml'), '002.xml' => $this->fixture('outage-a80-ol3-rev6.xml')]
                    : ['001.xml' => $this->fixture('outage-a80-ol3-rev7.xml')]), 200, ['Content-Type' => 'application/zip']);
            }
            if (($q['biddingZone_Domain'] ?? null) === '10Y1001A1001A46L' && $q['documentType'] === 'A80') {
                return Http::response($this->zip(['001.xml' => $this->fixture('outage-a80-se3-cancelled.xml')]), 200);
            }

            return Http::response($noData, 200, ['Content-Type' => 'text/xml']);
        });
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->fakeEntsoe();
        $this->assertSame(0, Artisan::call(self::S_FORECAST_OUTAGES));
        $line = $this->lastJsonLine();
        $this->assertSame(['stored', true, ['outages' => 3, 'generation' => 0, 'reservoirs' => 0]], [$line['status'], $line['complete'], $line['counts']]);
        $defaultRows = $this->storedRows();
        $this->assertCount(3, $defaultRows[0]);

        DB::table('entsoe_outages')->delete();
        $this->assertSame(0, Artisan::call(self::S_FORECAST_OUTAGES.' --fetch-only'));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, DB::table('entsoe_outages')->count(), 'Fetch only writes no rows.');

        // The fetch planned the history of both revision > 1 documents from the parsed first pages
        // and the empty table (S19).
        $header = $this->payloadLines($payload['path'])[0];
        $this->assertSame('2026-11-01T08:30:00Z', $header['facts']['window_minute']);
        $this->assertSame([
            ['zone' => 'FI', 'document_type' => 'A80', 'mrid' => 'coo--hR7U1v3g7eOuR4Tzg', 'start' => '2026-09-09T22:00:00Z'],
            ['zone' => 'SE3', 'document_type' => 'A80', 'mrid' => 'WuQqOp7X1PEypTpXW4_9IA', 'start' => '2026-08-10T05:00:00Z'],
        ], $header['facts']['history']);
        // The repeated --zone option round-trips through the header.
        $this->assertSame(['FI', 'SE3', 'SE1_FI', 'SE3_FI', 'EE_FI'], $header['options']['zone']);

        // A store with other data options is refused.
        $this->assertSame(1, Artisan::call('entsoe:fetch --dataset=outages --zone=FI --from-payload='.$payload['path']));
        $refused = $this->lastJsonLine();
        $this->assertSame(['payload_invalid', 'options_mismatch'], [$refused['error'], $refused['reason']]);

        // The store runs two hours later and keeps the fetch time (S5).
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 10, 30, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call(self::S_FORECAST_OUTAGES." --from-payload={$payload['path']} --payload-sha256={$payload['sha256']}"));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        $this->assertSame(['2026-11-01 08:30:00'], DB::table('entsoe_outages')->distinct()->pluck('fetched_at')->all());
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        $this->fakeEntsoe();
        Log::spy();
        // The reservoir upsert fails after the outage inserts of the same run succeeded.
        DB::statement("CREATE TRIGGER fail_reservoirs BEFORE INSERT ON entsoe_reservoir_weeks BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('entsoe:fetch --zone=FI'));
        $output = Artisan::output();

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'entsoe_reservoir_weeks'], $this->lastJsonLine($output));
        foreach (['forced failure', 'Olkiluoto', '55055761'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, DB::table('entsoe_outages')->count() + DB::table('entsoe_reservoir_weeks')->count(), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: entsoe:fetch', $message);
            $this->assertSame(['store', '23000', 19, 'entsoe_reservoir_weeks'], [$context['step'], $context['sqlstate'], $context['driver_code'], $context['table']]);
            foreach (['forced failure', 'Olkiluoto', '55055761', 'secret-token'] as $binding) {
                $this->assertStringNotContainsString($binding, json_encode($context));
            }

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/entsoe-transparency/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_reservoirs'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(0, Artisan::call('entsoe:fetch --zone=FI --from-payload='.$payloads[0]));
        Http::assertNothingSent();
        $this->assertSame(2, DB::table('entsoe_outages')->count());
        $this->assertSame(13, DB::table('entsoe_reservoir_weeks')->where('zone', 'FI')->count());
    }

    public function test_a_stored_previous_revision_means_no_history_request(): void
    {
        app(OutageImporter::class)->import('A80', 'FI', [$this->fixture('outage-a80-ol3-rev6.xml')], Carbon::now('UTC'));
        $this->fakeEntsoe();

        $this->assertSame(0, Artisan::call('entsoe:fetch --dataset=outages --zone=FI --fetch-only'));

        $this->assertSame([], $this->payloadLines($this->lastJsonLine()['payload']['path'])[0]['facts']['history']);
        Http::assertNotSent(fn (Request $request) => isset($this->queryOf($request)['mRID']));
        $this->assertSame(1, DB::table('entsoe_outages')->count(), 'The fetch step writes no row.');
    }

    public function test_the_security_token_never_reaches_the_payload_and_a_failed_unit_keeps_the_others(): void
    {
        Http::fake(function (Request $request) {
            if ($this->queryOf($request)['in_Domain'] === '10YSE-1--------K') {
                return Http::response('<html>stalled</html>', 503);
            }

            return Http::response($this->fixture('reservoir-a72-no.xml'), 200);
        });

        $this->assertSame(1, Artisan::call('entsoe:fetch --dataset=reservoirs'));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false], [$line['status'], $line['step'], $line['error'], $line['complete']]);
        $this->assertSame(26, $line['counts']['reservoirs'], 'NO and FI are stored; SE failed (S10).');

        $decompressed = (string) gzdecode((string) file_get_contents($line['payload']['path']));
        $this->assertStringNotContainsString('secret-token', $decompressed);
        $requests = array_values(array_filter($this->payloadLines($line['payload']['path']), fn (array $l) => $l['type'] === 'request'));
        $this->assertCount(3, $requests);
        foreach ($requests as $request) {
            $this->assertSame('A72', $request['query']['documentType'] ?? null);
            $this->assertArrayNotHasKey('securityToken', $request['query']);
        }
        $this->assertSame(503, $requests[1]['status']);
    }

    /**
     * S9: not date-bound. An older payload from the same day is accepted. Outages are insert-only,
     * so it adds only versions that are missing; reservoir and generation rows are upserted, so
     * they take the older values and fetch time until the next fetch.
     */
    public function test_an_older_same_day_payload_is_accepted(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 6, 0, 0, 'UTC'));
        $this->fakeEntsoe('1000');
        Artisan::call('entsoe:fetch --zone=FI --fetch-only');
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 9, 0, 0, 'UTC'));
        $this->fakeEntsoe('2000');
        $this->assertSame(0, Artisan::call('entsoe:fetch --zone=FI'));

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 12, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('entsoe:fetch --zone=FI --from-payload='.$older));

        $this->assertSame(['outages' => 0, 'generation' => 0, 'reservoirs' => 13], $this->lastJsonLine()['counts']);
        $this->assertSame(['2026-11-01 09:00:00'], DB::table('entsoe_outages')->distinct()->pluck('fetched_at')->all());
        $first = DB::table('entsoe_reservoir_weeks')->orderBy('timestamp')->first();
        $this->assertEqualsWithDelta(1000.0, $first->stored_energy_mwh, 0.001);
        $this->assertSame('2026-11-01 06:00:00', $first->fetched_at);
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function storedRows(): array
    {
        $strip = fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at']));

        return [
            DB::table('entsoe_outages')->orderBy('document_mrid')->orderBy('revision_number')->get()->map($strip)->all(),
            DB::table('entsoe_generation_hourly')->orderBy('zone')->orderBy('psr_type')->orderBy('direction')->orderBy('timestamp')->get()->map($strip)->all(),
            DB::table('entsoe_reservoir_weeks')->orderBy('zone')->orderBy('timestamp')->get()->map($strip)->all(),
        ];
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
