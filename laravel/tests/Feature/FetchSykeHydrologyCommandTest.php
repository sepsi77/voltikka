<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
}
