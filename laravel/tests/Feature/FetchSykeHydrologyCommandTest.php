<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchSykeHydrologyCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://syke.test/odata';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 8, 30, 0, 'UTC'));
        config([
            'syke.base_url' => self::BASE,
            'syke.request_delay_seconds' => 0,
            'syke.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(string $entity, array $rows, ?string $nextLink = null): array
    {
        return array_filter([
            'odata.metadata' => self::BASE.'/$metadata#'.$entity,
            'value' => $rows,
            'odata.nextLink' => $nextLink,
        ], fn ($v) => $v !== null);
    }

    public function test_daily_window_stores_both_series_with_place_and_date_filters(): void
    {
        Http::fake([
            'syke.test/odata/SadantaAlue*' => Http::response($this->page('SadantaAlue', [
                ['Paikka_Id' => 848, 'Aika' => '2026-10-16T00:00:00', 'Jakso_Id' => 2, 'Arvo' => 0],
                ['Paikka_Id' => 848, 'Aika' => '2026-10-21T00:00:00', 'Jakso_Id' => 2, 'Arvo' => 2],
                // Another Jakso, an unknown place and a null value are skipped.
                ['Paikka_Id' => 848, 'Aika' => '2026-10-01T00:00:00', 'Jakso_Id' => 3, 'Arvo' => 96],
                ['Paikka_Id' => 999, 'Aika' => '2026-10-21T00:00:00', 'Jakso_Id' => 2, 'Arvo' => 5],
                ['Paikka_Id' => 852, 'Aika' => '2026-10-21T00:00:00', 'Jakso_Id' => 2, 'Arvo' => null],
            ])),
            'syke.test/odata/LumiAlue*' => Http::response($this->page('LumiAlue', [
                ['Paikka_Id' => 233, 'Aika' => '2026-10-16T00:00:00', 'Arvo' => '13.78'],
            ])),
        ]);

        $this->artisan('syke:fetch')->assertExitCode(0);

        $rows = DB::table('syke_hydrology_values')->orderBy('series')->orderBy('date')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['precipitation_5d', 'precipitation_5d', 'snow_water_equivalent'], $rows->pluck('series')->all());
        $this->assertSame('2026-10-21', substr((string) $rows[1]->date, 0, 10));
        $this->assertEqualsWithDelta(2.0, $rows[1]->value, 0.0001);
        $this->assertSame(233, (int) $rows[2]->place_id);
        $this->assertEqualsWithDelta(13.78, $rows[2]->value, 0.0001);

        Http::assertSent(function ($request) {
            $filter = (string) ($request->data()['$filter'] ?? '');

            return str_contains($request->url(), '/SadantaAlue')
                && str_contains($filter, 'Paikka_Id eq 848 or Paikka_Id eq 852')
                && str_contains($filter, 'Jakso_Id eq 2')
                // 90 days before 2026-11-01 Helsinki, through today inclusive.
                && str_contains($filter, "Aika ge datetime'2026-08-03T00:00:00'")
                && str_contains($filter, "Aika lt datetime'2026-11-02T00:00:00'")
                && ($request->data()['$orderby'] ?? null) === 'Paikka_Id,Aika';
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), '/LumiAlue')
            && ! str_contains((string) ($request->data()['$filter'] ?? ''), 'Jakso_Id'));
    }

    public function test_follows_next_link_and_rerun_replaces_corrected_values(): void
    {
        // Syke sends an absolute, already encoded nextLink.
        $next = self::BASE.'/LumiAlue?$filter=Paikka_Id%20eq%20196&$skip=500';

        Http::fakeSequence('syke.test/odata/LumiAlue*')
            ->push($this->page('LumiAlue', [['Paikka_Id' => 196, 'Aika' => '2026-03-01T00:00:00', 'Arvo' => '139.41']], $next))
            ->push($this->page('LumiAlue', [['Paikka_Id' => 196, 'Aika' => '2026-03-16T00:00:00', 'Arvo' => '147.67']]))
            ->push($this->page('LumiAlue', [['Paikka_Id' => 196, 'Aika' => '2026-03-01T00:00:00', 'Arvo' => '140.00']]));

        $this->artisan('syke:fetch --series=snow_water_equivalent --from=2026-03-01 --to=2026-03-31')->assertExitCode(0);
        $this->assertSame(2, DB::table('syke_hydrology_values')->count());
        $this->assertTrue(collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]->url(), '/LumiAlue?')
            && str_contains($pair[0]->url(), 'skip=500')));

        $this->artisan('syke:fetch --series=snow_water_equivalent --from=2026-03-01 --to=2026-03-31')->assertExitCode(0);
        $this->assertSame(2, DB::table('syke_hydrology_values')->count());
        $this->assertEqualsWithDelta(140.0, DB::table('syke_hydrology_values')->where('date', 'like', '2026-03-01%')->value('value'), 0.0001);
    }

    public function test_next_link_outside_the_api_fails_the_series(): void
    {
        Http::fake([
            'syke.test/odata/LumiAlue*' => Http::response($this->page('LumiAlue', [], 'https://elsewhere.test/odata/LumiAlue?$skip=500')),
        ]);

        $this->artisan('syke:fetch --series=snow_water_equivalent')->assertExitCode(1);
        Http::assertSentCount(1);
    }

    public function test_backfill_defaults_to_configured_start_and_a_failed_series_fails_the_run(): void
    {
        Http::fake([
            'syke.test/odata/SadantaAlue*' => Http::response(['message' => 'boom'], 500),
            'syke.test/odata/LumiAlue*' => Http::response($this->page('LumiAlue', [
                ['Paikka_Id' => 160, 'Aika' => '2023-01-16T00:00:00', 'Arvo' => '61.20'],
            ])),
        ]);

        $this->artisan('syke:fetch --to=2023-12-31')->assertExitCode(1);

        $this->assertSame(1, DB::table('syke_hydrology_values')->where('series', 'snow_water_equivalent')->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/LumiAlue')
            && str_contains((string) ($request->data()['$filter'] ?? ''), "Aika ge datetime'2023-01-01T00:00:00'")
            && str_contains((string) ($request->data()['$filter'] ?? ''), "Aika lt datetime'2024-01-01T00:00:00'"));
    }

    public function test_rejects_unknown_series_and_invalid_ranges(): void
    {
        Http::fake();

        $this->artisan('syke:fetch --series=runoff')->assertExitCode(1);
        $this->artisan('syke:fetch --from=2026-13-01')->assertExitCode(1);
        $this->artisan('syke:fetch --from=2026-10-02 --to=2026-10-01')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->fakeSnowPages('139.41');

        $this->assertSame(0, Artisan::call('syke:fetch', ['--series' => ['snow_water_equivalent']]));
        $defaultRows = $this->storedRows();
        $this->assertSame(['rows' => 2, 'failed_series' => 0], $this->lastJsonLine()['counts']);

        DB::table('syke_hydrology_values')->delete();
        $this->fakeSnowPages('139.41');
        $this->assertSame(0, Artisan::call('syke:fetch', ['--series' => ['snow_water_equivalent'], '--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame(0, DB::table('syke_hydrology_values')->count(), 'Fetch only writes no rows.');
        $this->assertSame(2, $payload['requests'], 'Every OData page is one request line.');

        // A store on the next Helsinki day keeps the fetch time (S5) and the series plan.
        Carbon::setTestNow(Carbon::create(2026, 11, 2, 9, 0, 0, 'UTC'));
        config(['syke.series.snow_water_equivalent.places' => []]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('syke:fetch', ['--series' => ['snow_water_equivalent'], '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256']]));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        $this->assertSame(['2026-11-01 08:30:00'], DB::table('syke_hydrology_values')->distinct()->pluck('fetched_at')->all());
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        $this->fakeBothSeries();
        Log::spy();
        // The snow upsert fails after the precipitation upsert succeeded inside the same transaction.
        DB::statement("CREATE TRIGGER fail_syke_snow BEFORE INSERT ON syke_hydrology_values WHEN NEW.series = 'snow_water_equivalent' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('syke:fetch'));
        $output = Artisan::output();

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'syke_hydrology_values'], $this->lastJsonLine($output));
        foreach (['forced failure', '13.78'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, DB::table('syke_hydrology_values')->count(), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: syke:fetch', $message);
            $this->assertSame(['23000', 19, 'syke_hydrology_values', 'store'], [$context['sqlstate'], $context['driver_code'], $context['table'], $context['step']]);
            $this->assertStringNotContainsString('forced failure', json_encode($context));
            $this->assertStringNotContainsString('13.78', json_encode($context));

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/syke/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_syke_snow'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('syke:fetch', ['--from-payload' => $payloads[0]]));
        $this->assertSame(2, DB::table('syke_hydrology_values')->count());
        Http::assertNothingSent();
    }

    public function test_a_failed_page_fails_its_series_and_the_store_keeps_the_other(): void
    {
        $next = self::BASE.'/LumiAlue?$filter=Paikka_Id%20eq%20196&$skip=500';
        Http::fake([
            'syke.test/odata/SadantaAlue*' => Http::response($this->page('SadantaAlue', [
                ['Paikka_Id' => 848, 'Aika' => '2026-10-21T00:00:00', 'Jakso_Id' => 2, 'Arvo' => 2],
            ])),
            'syke.test/odata/LumiAlue*' => Http::sequence()
                ->push($this->page('LumiAlue', [['Paikka_Id' => 196, 'Aika' => '2026-10-16T00:00:00', 'Arvo' => '5.00']], $next))
                ->push(['message' => 'boom'], 500)->push(['message' => 'boom'], 500)->push(['message' => 'boom'], 500),
        ]);

        $this->assertSame(0, Artisan::call('syke:fetch', ['--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        $this->assertSame([3, 1, false], [$reference['requests'], $reference['failed_units'], $reference['complete']]);

        $this->assertSame(1, Artisan::call('syke:fetch', ['--from-payload' => $reference['path']]));
        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete'], [$line['status'], $line['step'], $line['error']]);
        $this->assertSame(['precipitation_5d'], DB::table('syke_hydrology_values')->pluck('series')->all(), 'The first page of the failed series is not stored.');
    }

    /**
     * S9: this source is not date-bound. An older payload from the same day is accepted and its
     * upsert puts the older values (and its older fetched_at) back until the next fetch.
     */
    public function test_an_older_same_day_payload_is_accepted_and_restores_its_values(): void
    {
        $this->fakeSnowPages('139.41');
        Artisan::call('syke:fetch', ['--series' => ['snow_water_equivalent']]);
        $older = $this->lastJsonLine()['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $this->fakeSnowPages('140.00');
        Artisan::call('syke:fetch', ['--series' => ['snow_water_equivalent']]);
        $this->assertEqualsWithDelta(140.0, DB::table('syke_hydrology_values')->where('date', 'like', '2026-03-01%')->value('value'), 0.0001);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('syke:fetch', ['--series' => ['snow_water_equivalent'], '--from-payload' => $older]));

        $row = DB::table('syke_hydrology_values')->where('date', 'like', '2026-03-01%')->sole();
        $this->assertEqualsWithDelta(139.41, $row->value, 0.0001);
        $this->assertSame('2026-11-01 08:30:00', $row->fetched_at);
    }

    /** Two LumiAlue pages joined by a nextLink. */
    private function fakeSnowPages(string $firstValue): void
    {
        $next = self::BASE.'/LumiAlue?$filter=Paikka_Id%20eq%20196&$skip=500';
        Http::swap(new Factory);
        Http::fakeSequence('syke.test/odata/LumiAlue*')
            ->push($this->page('LumiAlue', [['Paikka_Id' => 196, 'Aika' => '2026-03-01T00:00:00', 'Arvo' => $firstValue]], $next))
            ->push($this->page('LumiAlue', [['Paikka_Id' => 196, 'Aika' => '2026-03-16T00:00:00', 'Arvo' => '147.67']]));
    }

    private function fakeBothSeries(): void
    {
        Http::fake([
            'syke.test/odata/SadantaAlue*' => Http::response($this->page('SadantaAlue', [
                ['Paikka_Id' => 848, 'Aika' => '2026-10-21T00:00:00', 'Jakso_Id' => 2, 'Arvo' => 2],
            ])),
            'syke.test/odata/LumiAlue*' => Http::response($this->page('LumiAlue', [
                ['Paikka_Id' => 233, 'Aika' => '2026-10-16T00:00:00', 'Arvo' => '13.78'],
            ])),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        return DB::table('syke_hydrology_values')->orderBy('series')->orderBy('place_id')->orderBy('date')->get()
            ->map(fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at'])))->all();
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
