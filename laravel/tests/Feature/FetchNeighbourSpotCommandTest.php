<?php

namespace Tests\Feature;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use App\Services\EntsoeService;
use App\Services\SpotPriceAverageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\Concerns\FakesEntsoeDayAhead;
use Tests\TestCase;

class FetchNeighbourSpotCommandTest extends TestCase
{
    use FakesEntsoeDayAhead;
    use RefreshDatabase;

    private const API_KEY = 'entsoe-neighbour-secret-4410';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 31, 12, 0, 0, 'UTC'));
        config(['services.entsoe.api_key' => self::API_KEY]);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quarterRecords(string $region, float $price): array
    {
        $start = Carbon::parse('2026-10-31 00:00:00', 'UTC');

        return array_map(fn (int $i) => [
            'region' => $region,
            'timestamp' => $start->copy()->addMinutes(15 * $i)->timestamp,
            'utc_datetime' => $start->copy()->addMinutes(15 * $i),
            'price_without_tax' => $price,
            'resolution_minutes' => 15,
        ], range(0, 3));
    }

    /**
     * Answer each zone by its EIC code: a price gives four quarters of 2026-10-31 00:00 UTC, an
     * int status an error answer, a ConnectionException a connection failure.
     *
     * @param  array<string, float|int|ConnectionException>  $answers  region => answer
     */
    private function fakeZones(array $answers): void
    {
        $regionByEic = array_flip((array) config('services.entsoe.bidding_zones'));
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($answers, $regionByEic) {
            $answer = $answers[$regionByEic[$request['in_Domain']]];
            if ($answer instanceof ConnectionException) {
                throw $answer;
            }

            return is_int($answer)
                ? Http::response('<Acknowledgement_MarketDocument/>', $answer)
                : Http::response($this->dayAheadXml($this->quarterPoints('2026-10-31 00:00', $answer)));
        });
    }

    public function test_imports_each_configured_zone_without_finnish_vat(): void
    {
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => 20.0]);

        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));

        Http::assertSentCount(4);
        $this->assertSame(16, SpotPriceQuarter::count());
        $se3 = SpotPriceHour::query()->forRegion('SE3')->firstOrFail();
        $this->assertEqualsWithDelta(9.5, $se3->price_without_tax, 0.0001); // mean of 8, 9, 10, 11
        $this->assertEqualsWithDelta(0.0, $se3->vat_rate, 0.0001);
        $this->assertSame(0, SpotPriceHour::query()->forRegion('FI')->count());
        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(['stored', true, ['records' => 16, 'regions' => 4, 'failed_regions' => 0]], [$line['status'], $line['complete'], $line['counts']]);
    }

    public function test_one_failed_zone_does_not_stop_the_others_and_fails_the_run(): void
    {
        $averages = Mockery::mock(SpotPriceAverageService::class);
        $averages->shouldNotReceive('calculateAllAverages');
        $this->app->instance(SpotPriceAverageService::class, $averages);
        Log::spy();
        $this->fakeZones(['SE1' => new ConnectionException('timeout for securityToken='.self::API_KEY), 'SE3' => 5.0, 'EE' => 5.0, 'DE-LU' => 5.0]);

        $this->assertSame(1, Artisan::call('spot:fetch-neighbours'));
        $output = Artisan::output();

        $this->assertSame(0, SpotPriceHour::query()->forRegion('SE1')->count());
        $this->assertSame(1, SpotPriceHour::query()->forRegion('EE')->count());
        $line = $this->lastJsonLine($output);
        $this->assertSame(
            ['failed', 'fetch', 'fetch_incomplete', false, ['records' => 12, 'regions' => 3, 'failed_regions' => 1]],
            [$line['status'], $line['step'], $line['error'], $line['complete'], $line['counts']],
        );
        $this->assertStringNotContainsString(self::API_KEY, $output);
        Log::shouldHaveReceived('error')->once()->with('Neighbour spot import failed', [
            'region' => 'SE1', 'exception_class' => ConnectionException::class,
        ]);
    }

    /** D1: default mode and --fetch-only then --from-payload write the same rows. */
    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 503, 'DE-LU' => 20.0]);

        $this->assertSame(1, Artisan::call('spot:fetch-neighbours'));
        $defaultRows = $this->spotRows();
        $this->assertCount(15, $defaultRows, 'Three zones of four quarters and one hourly mean.');

        SpotPriceQuarter::query()->delete();
        SpotPriceHour::query()->delete();
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours', ['--fetch-only' => true]), 'A partial fetch saves its payload.');
        $payload = $this->lastJsonLine(Artisan::output())['payload'];
        $this->assertSame([false, 1, 4], [$payload['complete'], $payload['failed_units'], $payload['requests']]);
        $this->assertSame([], $this->spotRows(), 'Fetch only writes no rows.');

        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(1, Artisan::call('spot:fetch-neighbours', [
            '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256'],
        ]), 'The stored partial run keeps its exit code.');
        Http::assertNothingSent();
        $this->assertSame($defaultRows, $this->spotRows());
    }

    /** D2 and D6: a store QueryException in one zone writes no row of any zone, keeps the payload and replays. */
    public function test_store_failure_writes_no_row_reports_safe_sql_facts_and_replays_without_http(): void
    {
        Log::spy();
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => 20.0]);
        // SE1 and SE3 are inserted first inside the same transaction; EE fails.
        DB::statement("CREATE TRIGGER fail_ee_hours BEFORE INSERT ON spot_prices_hour WHEN NEW.region = 'EE' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->assertSame(1, Artisan::call('spot:fetch-neighbours'));
        $output = Artisan::output();

        $this->assertSame(
            ['status' => 'failed', 'step' => 'store', 'error' => 'store_failed', 'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'spot_prices_hour'],
            $this->lastJsonLine($output),
        );
        $this->assertSame([], $this->spotRows(), 'A failed store writes no row of the run (S21).');
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Import failed: spot:fetch-neighbours', $message);
            $this->assertSame(['store', 'store_failed', '23000', 19, 'spot_prices_hour'], [$context['step'], $context['error'], $context['sqlstate'], $context['driver_code'], $context['table']]);
            foreach (['forced failure', '13.5', self::API_KEY] as $binding) {
                $this->assertStringNotContainsString($binding, json_encode($context));
            }

            return true;
        });
        foreach (['forced failure', '13.5', self::API_KEY] as $binding) {
            $this->assertStringNotContainsString($binding, $output);
        }

        $payloads = glob(config('import_payloads.path').'/entsoe-day-ahead/2026-10-31/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        DB::statement('DROP TRIGGER fail_ee_hours'); // the fix
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours', ['--from-payload' => $payloads[0]]));
        Http::assertNothingSent();
        $this->assertSame(16, SpotPriceQuarter::count());
        $this->assertSame(4, SpotPriceHour::count());
    }

    /** D3: the securityToken is sent but reaches no payload line, also for failed zones. */
    public function test_the_security_token_never_reaches_the_payload_file(): void
    {
        $this->fakeZones([
            'SE1' => 2.0,
            'SE3' => 401,
            'EE' => new ConnectionException('cURL error 28 for https://web-api.tp.entsoe.eu/api?securityToken='.self::API_KEY),
            'DE-LU' => 20.0,
        ]);

        $this->assertSame(0, Artisan::call('spot:fetch-neighbours', ['--fetch-only' => true]));

        Http::assertSent(fn (Request $request) => $request['securityToken'] === self::API_KEY);
        $payload = $this->decompressedPayload($this->lastJsonLine(Artisan::output())['payload']['path']);
        $this->assertSame(1, substr_count($payload, '"connection_failed":true'));
        $this->assertSame(1, substr_count($payload, '"status":401'));
        $this->assertSame(4, substr_count($payload, '"documentType":"A44"'));
        $this->assertStringNotContainsString(self::API_KEY, $payload);
        $this->assertStringNotContainsString('securityToken', $payload);
    }

    /**
     * S9: not date-bound. An older same-day payload is accepted; rows are insert-only, so it
     * inserts nothing new and the rows of the later fetch stay as they are.
     */
    public function test_an_older_same_day_payload_is_accepted_and_inserts_nothing_new(): void
    {
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => 20.0]);
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours', ['--fetch-only' => true]));
        $older = $this->lastJsonLine(Artisan::output())['payload']['path'];

        Carbon::setTestNow(Carbon::create(2026, 10, 31, 14, 0, 0, 'UTC'));
        $this->fakeZones(['SE1' => 3.0, 'SE3' => 9.0, 'EE' => 13.0, 'DE-LU' => 21.0]);
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));
        $rows = $this->spotRows();

        Carbon::setTestNow(Carbon::create(2026, 10, 31, 16, 0, 0, 'UTC'));
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours', ['--from-payload' => $older]));

        $this->assertSame(['records' => 16, 'regions' => 4, 'failed_regions' => 0], $this->lastJsonLine(Artisan::output())['counts']);
        $this->assertSame($rows, $this->spotRows());
        $this->assertEqualsWithDelta(10.5, SpotPriceHour::query()->forRegion('SE3')->value('price_without_tax'), 0.0001);
    }

    public function test_backfill_accepts_a_neighbour_region_and_skips_finnish_averages(): void
    {
        $averages = Mockery::mock(SpotPriceAverageService::class);
        $averages->shouldNotReceive('calculateAllAverages');
        $this->app->instance(SpotPriceAverageService::class, $averages);

        $service = Mockery::mock(EntsoeService::class);
        $service->shouldReceive('fetchDayAheadPrices')
            ->once()
            ->withArgs(fn ($start, $end, $region) => $region === 'EE')
            ->andReturn($this->quarterRecords('EE', 9.0));
        $this->app->instance(EntsoeService::class, $service);

        $this->artisan('spot:backfill --start-date=2026-10-31 --end-date=2026-10-31 --region=EE')->assertExitCode(0);

        $this->assertSame(1, SpotPriceHour::query()->forRegion('EE')->count());
    }

    public function test_backfill_rejects_an_unknown_region(): void
    {
        $this->artisan('spot:backfill --start-date=2026-10-31 --end-date=2026-10-31 --region=XX')->assertExitCode(1);
    }
}
