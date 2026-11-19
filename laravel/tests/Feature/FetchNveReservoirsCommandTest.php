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

class FetchNveReservoirsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WEEKS_URL = 'biapi.nve.no/magasinstatistikk/api/Magasinstatistikk/HentOffentligData';

    private const REFERENCES_URL = 'biapi.nve.no/magasinstatistikk/api/Magasinstatistikk/HentOffentligDataMinMaxMedian';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 7, 30, 0, 'UTC'));
        config([
            'nve.request_delay_seconds' => 0,
            'nve.retry_sleep_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Shape of a real HentOffentligData row (NO total, week 43 of 2026: 69.5 %). */
    private function week(int $year, float $ratio, string $type = 'NO', int $no = 0): array
    {
        return [
            'dato_Id' => "{$year}-10-25",
            'omrType' => $type,
            'omrnr' => $no,
            'iso_aar' => $year,
            'iso_uke' => 43,
            'fyllingsgrad' => $ratio,
            'kapasitet_TWh' => 87.43758,
            'fylling_TWh' => round($ratio * 87.43758, 6),
            'neste_Publiseringsdato' => '2026-11-04T13:00:00',
            'fyllingsgrad_forrige_uke' => $ratio - 0.0028,
            'endring_fyllingsgrad' => 0.0028,
        ];
    }

    /** History 2004-2026 for NO week 43, a VASS row that must be ignored. */
    private function history(): array
    {
        $rows = [];
        foreach (range(2004, 2025) as $year) {
            $rows[] = $this->week($year, 0.60 + ($year - 2004) * 0.01);
        }
        $rows[] = $this->week(2026, 0.69537306);
        $rows[] = $this->week(2026, 0.6404667, 'VASS', 1);

        return $rows;
    }

    /** Reference for 2006-2025: min 0.62, median (0.71 + 0.72) / 2, max 0.81. */
    private function references(float $median = 0.715): array
    {
        return [
            ['omrType' => 'NO', 'omrnr' => 0, 'iso_uke' => 43, 'minFyllingsgrad' => 0.62, 'minFyllingTWH' => 54.2, 'medianFyllingsGrad' => $median, 'medianFylling_TWH' => 62.5, 'maxFyllingsgrad' => 0.81, 'maxFyllingTWH' => 70.8],
            ['omrType' => 'NO', 'omrnr' => 0, 'iso_uke' => 53, 'minFyllingsgrad' => 0.43962312, 'minFyllingTWH' => 38.439583, 'medianFyllingsGrad' => 0.6715162, 'medianFylling_TWH' => 58.71575, 'maxFyllingsgrad' => 0.823581, 'maxFyllingTWH' => 72.01193],
            ['omrType' => 'VASS', 'omrnr' => 1, 'iso_uke' => 43, 'minFyllingsgrad' => 0.5, 'minFyllingTWH' => 1.0, 'medianFyllingsGrad' => 0.6, 'medianFylling_TWH' => 1.0, 'maxFyllingsgrad' => 0.7, 'maxFyllingTWH' => 1.0],
        ];
    }

    public function test_default_run_stores_recent_weeks_and_the_proved_reference(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references()),
            self::WEEKS_URL => Http::response($this->history()),
        ]);

        $this->artisan('nve:fetch-reservoirs')->assertExitCode(0);

        $this->assertSame([2025, 2026], DB::table('nve_reservoir_weeks')->orderBy('iso_year')->pluck('iso_year')->map(fn ($y) => (int) $y)->all());
        $latest = DB::table('nve_reservoir_weeks')->where('iso_year', 2026)->first();
        $this->assertSame('NO', $latest->area_type);
        $this->assertSame(43, (int) $latest->iso_week);
        $this->assertSame('2026-10-25', $latest->week_end_date);
        $this->assertEqualsWithDelta(0.69537306, $latest->filling_ratio, 0.0000001);

        $references = DB::table('nve_reservoir_week_references')->orderBy('iso_week')->get();
        $this->assertCount(2, $references);
        $this->assertSame([2006, 2025], [(int) $references[0]->reference_from_year, (int) $references[0]->reference_to_year]);
        $this->assertEqualsWithDelta(0.715, $references[0]->median_ratio, 0.0000001);
        $this->assertSame(53, (int) $references[1]->iso_week);
    }

    public function test_reference_is_not_stored_when_its_period_cannot_be_proved(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references(0.70)),
            self::WEEKS_URL => Http::response($this->history()),
        ]);

        $this->artisan('nve:fetch-reservoirs --all')->assertExitCode(1);

        $this->assertSame(23, DB::table('nve_reservoir_weeks')->count());
        $this->assertSame(0, DB::table('nve_reservoir_week_references')->count());
    }

    public function test_failed_history_fails_the_run_and_stores_no_reference(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references()),
            self::WEEKS_URL => Http::response(['title' => 'boom'], 503),
        ]);

        $this->artisan('nve:fetch-reservoirs')->assertExitCode(1);

        $this->assertSame(0, DB::table('nve_reservoir_weeks')->count());
        $this->assertSame(0, DB::table('nve_reservoir_week_references')->count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'HentOffentligDataMinMaxMedian'));
    }

    public function test_rerun_replaces_a_revised_week(): void
    {
        $revised = $this->history();
        $revised[22] = $this->week(2026, 0.70);

        Http::fakeSequence(self::WEEKS_URL)->push($this->history())->push($revised);
        Http::fake([self::REFERENCES_URL => Http::response($this->references())]);

        $this->artisan('nve:fetch-reservoirs --from-year=2026')->assertExitCode(0);
        $this->artisan('nve:fetch-reservoirs --from-year=2026')->assertExitCode(0);

        $this->assertSame(1, DB::table('nve_reservoir_weeks')->count());
        $this->assertEqualsWithDelta(0.70, DB::table('nve_reservoir_weeks')->value('filling_ratio'), 0.0000001);
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->fakeNve($this->history());

        $this->assertSame(0, Artisan::call('nve:fetch-reservoirs'));
        $defaultRows = $this->storedRows();
        $this->assertSame(['weeks' => 2, 'references' => 2], $this->lastJsonLine()['counts']);

        DB::table('nve_reservoir_weeks')->delete();
        DB::table('nve_reservoir_week_references')->delete();
        $this->assertSame(0, Artisan::call('nve:fetch-reservoirs', ['--fetch-only' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame([0, 0], [DB::table('nve_reservoir_weeks')->count(), DB::table('nve_reservoir_week_references')->count()], 'Fetch only writes no rows.');

        // A store in the next ISO year keeps the fetch's current year and fetch time (S5): the
        // reference period stays 2006-2025 and the default range stays from 2025.
        Carbon::setTestNow(Carbon::create(2027, 1, 6, 9, 0, 0, 'UTC'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('nve:fetch-reservoirs', ['--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256']]));
        Http::assertNothingSent();

        $this->assertSame($defaultRows, $this->storedRows());
        $this->assertSame(['2026-11-01 07:30:00'], DB::table('nve_reservoir_weeks')->distinct()->pluck('fetched_at')->all());
    }

    public function test_store_failure_writes_no_row_keeps_the_payload_reports_safe_sql_facts_and_replays(): void
    {
        $this->fakeNve($this->history());
        Log::spy();
        // The reference upsert fails after the weekly upsert succeeded inside the same transaction.
        DB::statement("CREATE TRIGGER fail_nve_reference BEFORE INSERT ON nve_reservoir_week_references BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('nve:fetch-reservoirs'));
        $output = Artisan::output();

        $this->assertSame(['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'nve_reservoir_week_references'], $this->lastJsonLine($output));
        foreach (['forced failure', '0.715', '0.69537306'] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }
        $this->assertSame(0, DB::table('nve_reservoir_weeks')->count(), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: nve:fetch-reservoirs', $message);
            $this->assertSame(['23000', 19, 'nve_reservoir_week_references', 'store'], [$context['sqlstate'], $context['driver_code'], $context['table'], $context['step']]);
            $this->assertStringNotContainsString('forced failure', json_encode($context));
            $this->assertStringNotContainsString('0.715', json_encode($context));

            return true;
        });

        $payloads = glob(config('import_payloads.path').'/nve/2026-11-01/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_nve_reference'); // the fix
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('nve:fetch-reservoirs', ['--from-payload' => $payloads[0]]));
        $this->assertSame([2, 2], [DB::table('nve_reservoir_weeks')->count(), DB::table('nve_reservoir_week_references')->count()]);
        Http::assertNothingSent();
    }

    public function test_a_failed_history_is_recorded_and_the_store_keeps_nothing_of_the_reference(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references()),
            self::WEEKS_URL => Http::response(['title' => 'not a list']),
        ]);

        $this->assertSame(0, Artisan::call('nve:fetch-reservoirs', ['--fetch-only' => true]));
        $reference = $this->lastJsonLine()['payload'];
        $this->assertSame([2, 1, false], [$reference['requests'], $reference['failed_units'], $reference['complete']]);

        $this->assertSame(1, Artisan::call('nve:fetch-reservoirs', ['--from-payload' => $reference['path']]));
        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete'], [$line['status'], $line['step'], $line['error']]);
        $this->assertSame(0, DB::table('nve_reservoir_week_references')->count());
    }

    public function test_an_unproved_reference_period_keeps_the_weeks_and_fails_with_its_own_key(): void
    {
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references(0.70)),
            self::WEEKS_URL => Http::response($this->history()),
        ]);

        $this->assertSame(1, Artisan::call('nve:fetch-reservoirs'));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'store', 'reference_period_unproved', true], [$line['status'], $line['step'], $line['error'], $line['complete']]);
        $this->assertSame(['weeks' => 2, 'references' => 0], $line['counts']);
        $this->assertSame(2, DB::table('nve_reservoir_weeks')->count());
    }

    /**
     * S9: this source is not date-bound. An older payload from the same day is accepted and its
     * upsert puts the older values (and its older fetched_at) back until the next fetch.
     */
    public function test_an_older_same_day_payload_is_accepted_and_restores_its_values(): void
    {
        $this->fakeNve($this->history());
        Artisan::call('nve:fetch-reservoirs', ['--from-year' => '2026']);
        $older = $this->lastJsonLine()['payload']['path'];

        $revised = $this->history();
        $revised[22] = $this->week(2026, 0.70);
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $this->fakeNve($revised);
        Artisan::call('nve:fetch-reservoirs', ['--from-year' => '2026']);
        $this->assertEqualsWithDelta(0.70, DB::table('nve_reservoir_weeks')->value('filling_ratio'), 0.0000001);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('nve:fetch-reservoirs', ['--from-year' => '2026', '--from-payload' => $older]));

        $row = DB::table('nve_reservoir_weeks')->sole();
        $this->assertEqualsWithDelta(0.69537306, $row->filling_ratio, 0.0000001);
        $this->assertSame('2026-11-01 07:30:00', $row->fetched_at);
    }

    private function fakeNve(array $history): void
    {
        Http::swap(new Factory);
        Http::fake([
            self::REFERENCES_URL => Http::response($this->references()),
            self::WEEKS_URL => Http::response($history),
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function storedRows(): array
    {
        $strip = fn ($row) => array_diff_key((array) $row, array_flip(['id', 'created_at', 'updated_at']));

        return [
            'weeks' => DB::table('nve_reservoir_weeks')->orderBy('area_type')->orderBy('iso_year')->orderBy('iso_week')->get()->map($strip)->all(),
            'references' => DB::table('nve_reservoir_week_references')->orderBy('area_type')->orderBy('iso_week')->get()->map($strip)->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
