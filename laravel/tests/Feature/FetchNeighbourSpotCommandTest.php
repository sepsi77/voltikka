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
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesEntsoeDayAhead;
use Tests\TestCase;

class FetchNeighbourSpotCommandTest extends TestCase
{
    use FakesEntsoeDayAhead;
    use RefreshDatabase;

    private const API_KEY = 'entsoe-neighbour-secret-4410';

    private const ENERGY_CHARTS_URL = 'https://api.energy-charts.info/price';

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
     * int status an error answer, a ConnectionException a connection failure, a string that A44
     * body. Energy-Charts answers $energyCharts the same way (a string is its body); by default it
     * repeats the DE-LU quarters, so it fills nothing.
     *
     * @param  array<string, float|int|string|ConnectionException>  $answers  region => answer
     */
    private function fakeZones(array $answers, float|int|string|ConnectionException|null $energyCharts = null): void
    {
        $regionByEic = array_flip((array) config('services.entsoe.bidding_zones'));
        $energyCharts ??= is_float($answers['DE-LU']) ? $answers['DE-LU'] : $this->energyChartsJson([]);
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($answers, $regionByEic, $energyCharts) {
            if (str_starts_with($request->url(), self::ENERGY_CHARTS_URL)) {
                $answer = $energyCharts;
                if ($answer instanceof ConnectionException) {
                    throw $answer;
                }

                return match (true) {
                    is_int($answer) => Http::response('Too Many Requests', $answer),
                    is_string($answer) => Http::response($answer),
                    default => Http::response($this->energyChartsJson($this->quarterPoints('2026-10-31 00:00', $answer))),
                };
            }
            $answer = $answers[$regionByEic[$request['in_Domain']]];
            if (is_string($answer)) {
                return Http::response($answer);
            }
            if ($answer instanceof ConnectionException) {
                throw $answer;
            }

            return is_int($answer)
                ? Http::response('<Acknowledgement_MarketDocument/>', $answer)
                : Http::response($this->dayAheadXml($this->quarterPoints('2026-10-31 00:00', $answer)));
        });
    }

    /**
     * An Energy-Charts price answer; null prices stay null.
     *
     * @param  list<array{0: int, 1: float|null}>  $points  [UTC timestamp, c/kWh VAT0 or null]
     */
    private function energyChartsJson(array $points): string
    {
        return json_encode([
            'license_info' => 'CC BY 4.0 (creativecommons.org/licenses/by/4.0) from Bundesnetzagentur | SMARD.de',
            'unix_seconds' => array_map(fn (array $point) => $point[0], $points),
            'price' => array_map(fn (array $point) => $point[1] === null ? null : round($point[1] * 10, 2), $points),
            'unit' => 'EUR / MWh',
            'deprecated' => false,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Quarter-hour points with a distinct price each, from $utcStart.
     *
     * @return list<array{0: int, 1: float, 2: int}>
     */
    private function quarterSeries(string $utcStart, int $count, float $base): array
    {
        $start = Carbon::parse($utcStart, 'UTC');

        return array_map(fn (int $i) => [$start->copy()->addMinutes(15 * $i)->timestamp, $base + $i / 100, 15], range(0, $count - 1));
    }

    /** @return array<int, float> UTC timestamp => c/kWh VAT0 */
    private function storedPrices(string $table, string $region): array
    {
        return DB::table($table)->where('region', $region)->orderBy('timestamp')
            ->pluck('price_without_tax', 'timestamp')->map(fn ($price) => (float) $price)->all();
    }

    public function test_imports_each_configured_zone_without_finnish_vat(): void
    {
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => 20.0]);

        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));

        Http::assertSentCount(5);
        $this->assertSame(16, SpotPriceQuarter::count());
        $se3 = SpotPriceHour::query()->forRegion('SE3')->firstOrFail();
        $this->assertEqualsWithDelta(9.5, $se3->price_without_tax, 0.0001); // mean of 8, 9, 10, 11
        $this->assertEqualsWithDelta(0.0, $se3->vat_rate, 0.0001);
        $this->assertSame(0, SpotPriceHour::query()->forRegion('FI')->count());
        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(
            ['stored', true, ['records' => 16, 'regions' => 4, 'failed_regions' => 0, 'de_lu_filled' => 0, 'failed_fill' => 0]],
            [$line['status'], $line['complete'], $line['counts']],
        );
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
            ['failed', 'fetch', 'fetch_incomplete', false, ['records' => 12, 'regions' => 3, 'failed_regions' => 1, 'de_lu_filled' => 0, 'failed_fill' => 0]],
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
        $this->assertSame([false, 1, 5], [$payload['complete'], $payload['failed_units'], $payload['requests']]);
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
        $payloads = glob(config('import_payloads.path').'/entsoe-day-ahead/2026-10-31/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
            'sqlstate' => '23000', 'driver_code' => 19, 'table' => 'spot_prices_hour',
        ], $this->lastJsonLine($output));
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

        Http::assertSent(fn (Request $request) => ($request->data()['securityToken'] ?? null) === self::API_KEY);
        $payload = $this->decompressedPayload($this->lastJsonLine(Artisan::output())['payload']['path']);
        $this->assertSame(1, substr_count($payload, '"connection_failed":true'));
        $this->assertSame(1, substr_count($payload, '"status":401'));
        $this->assertSame(4, substr_count($payload, '"documentType":"A44"'));
        $this->assertStringNotContainsString(self::API_KEY, $payload);
        $this->assertStringNotContainsString('securityToken', $payload);
    }

    /**
     * Replay stores only the newest payload: an older same-day payload is refused (superseded)
     * while a newer payload exists, and inserts nothing.
     */
    public function test_an_older_same_day_payload_is_refused_and_inserts_nothing(): void
    {
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => 20.0]);
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours', ['--fetch-only' => true]));
        $older = $this->lastJsonLine(Artisan::output())['payload'];

        Carbon::setTestNow(Carbon::create(2026, 10, 31, 14, 0, 0, 'UTC'));
        $this->fakeZones(['SE1' => 3.0, 'SE3' => 9.0, 'EE' => 13.0, 'DE-LU' => 21.0]);
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));
        $rows = $this->spotRows();

        Carbon::setTestNow(Carbon::create(2026, 10, 31, 16, 0, 0, 'UTC'));
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(1, Artisan::call('spot:fetch-neighbours', ['--from-payload' => $older['path']]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $older['path'], 'payload_sha256' => $older['sha256'],
        ], $this->lastJsonLine(Artisan::output()));
        $this->assertSame($rows, $this->spotRows());
    }

    /**
     * S10: a non-2xx answer that the HTTP client does not count as failed (a 3xx without a
     * redirect target) is a failed region with its body, so the run cannot report a missing
     * region as complete.
     */
    public function test_a_non_2xx_answer_is_a_failed_region_with_its_body(): void
    {
        Log::spy();
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 302, 'EE' => 12.0, 'DE-LU' => 20.0]);

        $this->assertSame(1, Artisan::call('spot:fetch-neighbours'));

        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(
            ['failed', 'fetch', 'fetch_incomplete', false, ['records' => 12, 'regions' => 3, 'failed_regions' => 1, 'de_lu_filled' => 0, 'failed_fill' => 0]],
            [$line['status'], $line['step'], $line['error'], $line['complete'], $line['counts']],
        );
        $se3 = array_values(array_filter(
            array_map(fn (string $l) => json_decode($l, true), array_filter(explode("\n", $this->decompressedPayload($line['payload']['path'])))),
            fn (array $l) => ($l['unit']['region'] ?? null) === 'SE3',
        ));
        $this->assertSame([302, '<Acknowledgement_MarketDocument/>'], [$se3[0]['status'], $se3[0]['body']]);
        Log::shouldHaveReceived('error')->once()->with('Neighbour spot import failed', [
            'region' => 'SE3', 'exception_class' => RequestException::class,
        ]);
    }

    /**
     * @param  list<array{0: int, 1: float, 2: int}>  $points
     * @return list<array{0: int, 1: float, 2: int, 3: int}>
     */
    private function withSequence(array $points, int $sequence): array
    {
        return array_map(fn (array $point) => [...$point, $sequence], $points);
    }

    /**
     * Fake a DE-LU day like 2026-11-20: ENTSO-E has sequence 1 for 2026-10-31 00:00 UTC only and
     * Helsinki 2026-11-01 only as sequence 2; Energy-Charts (SMARD) has both.
     *
     * @return array<int, float> the Energy-Charts c/kWh of Helsinki 2026-11-01 by UTC timestamp
     */
    private function fakeSequenceTwoDay(): array
    {
        $sequenceOne = $this->withSequence($this->quarterSeries('2026-10-31 00:00', 4, 20.0), 1);
        $sequenceTwo = $this->withSequence($this->quarterSeries('2026-10-31 22:00', 96, 90.0), 2);
        $smard = $this->energyChartsJson([
            ...$this->quarterSeries('2026-10-31 00:00', 4, 70.0),
            ...$this->quarterSeries('2026-10-31 22:00', 96, 40.0),
        ]);
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => $this->dayAheadXml([...$sequenceOne, ...$sequenceTwo])], $smard);

        $answer = json_decode($smard, true);
        $expected = [];
        foreach ($answer['unix_seconds'] as $index => $timestamp) {
            if ($timestamp >= Carbon::parse('2026-10-31 22:00', 'UTC')->timestamp) {
                $expected[$timestamp] = $answer['price'][$index] / 10;
            }
        }

        return $expected;
    }

    /** (a) ENTSO-E sequence 1 covers every DE-LU slot: nothing is taken from Energy-Charts. */
    public function test_entsoe_sequence_one_wins_over_energy_charts(): void
    {
        $sequenceOne = $this->withSequence($this->quarterSeries('2026-10-31 00:00', 4, 20.0), 1);
        $sequenceTwo = $this->withSequence($this->quarterSeries('2026-10-31 00:00', 4, 90.0), 2);
        $this->fakeZones(
            ['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => $this->dayAheadXml([...$sequenceOne, ...$sequenceTwo])],
            $this->energyChartsJson($this->quarterSeries('2026-10-31 00:00', 4, 50.0)),
        );

        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));

        $start = Carbon::parse('2026-10-31 00:00', 'UTC')->timestamp;
        $this->assertEqualsWithDelta(
            [$start => 20.0, $start + 900 => 20.01, $start + 1800 => 20.02, $start + 2700 => 20.03],
            $this->storedPrices('spot_prices_quarter', 'DE-LU'),
            1e-9,
        );
        $this->assertEqualsWithDelta([$start => 20.015], $this->storedPrices('spot_prices_hour', 'DE-LU'), 1e-9);
        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(['stored', 16, 0, 0], [$line['status'], $line['counts']['records'], $line['counts']['de_lu_filled'], $line['counts']['failed_fill']]);
    }

    /** (b) A day that ENTSO-E has only as sequence 2 is filled from Energy-Charts with its exact values. */
    public function test_a_sequence_two_only_day_is_filled_from_energy_charts(): void
    {
        $expected = $this->fakeSequenceTwoDay();

        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));
        $output = Artisan::output();

        $quarters = $this->storedPrices('spot_prices_quarter', 'DE-LU');
        $this->assertCount(100, $quarters, 'Four sequence-1 quarters and the 96 filled quarters.');
        $this->assertEqualsWithDelta($expected, array_slice($quarters, 4, null, true), 1e-9);
        $this->assertEqualsWithDelta(20.0, $quarters[Carbon::parse('2026-10-31 00:00', 'UTC')->timestamp], 1e-9, 'Sequence 1 stays primary.');

        $hours = DB::table('spot_prices_hour')->where('region', 'DE-LU')
            ->where('timestamp', '>=', Carbon::parse('2026-10-31 22:00', 'UTC')->timestamp)
            ->orderBy('timestamp')->get();
        $this->assertCount(24, $hours, 'Helsinki 2026-11-01 has all 24 hours.');
        foreach ($hours as $hour) {
            $quarterPrices = array_map(fn (int $i) => $expected[$hour->timestamp + 900 * $i], range(0, 3));
            $this->assertEqualsWithDelta(array_sum($quarterPrices) / 4, (float) $hour->price_without_tax, 1e-6);
            $this->assertEqualsWithDelta(0.0, (float) $hour->vat_rate, 1e-9);
        }

        $line = $this->lastJsonLine($output);
        $this->assertSame(
            ['stored', true, ['records' => 112, 'regions' => 4, 'failed_regions' => 0, 'de_lu_filled' => 96, 'failed_fill' => 0]],
            [$line['status'], $line['complete'], $line['counts']],
        );
        $this->assertStringContainsString('DE-LU: 96 quarter-hours filled from Energy-Charts (SMARD).', $output);
    }

    /**
     * (c) Partial coverage: only the slots ENTSO-E sequence 1 does not cover are filled, the hour
     * is the mean of all four, null prices and the inclusive end point are skipped.
     */
    public function test_only_uncovered_slots_are_filled(): void
    {
        $hour = Carbon::parse('2026-10-31 00:00', 'UTC')->timestamp;
        $windowEnd = Carbon::parse('2026-11-01 22:00', 'UTC')->timestamp;
        $this->fakeZones(
            ['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => $this->dayAheadXml($this->withSequence($this->quarterSeries('2026-10-31 00:00', 2, 20.0), 1))],
            $this->energyChartsJson([...$this->quarterSeries('2026-10-31 00:00', 4, 50.0), [$hour + 3600, null], [$windowEnd, 60.0]]),
        );

        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));

        $this->assertEqualsWithDelta(
            [$hour => 20.0, $hour + 900 => 20.01, $hour + 1800 => 50.02, $hour + 2700 => 50.03],
            $this->storedPrices('spot_prices_quarter', 'DE-LU'),
            1e-9,
        );
        $this->assertEqualsWithDelta([$hour => (20.0 + 20.01 + 50.02 + 50.03) / 4], $this->storedPrices('spot_prices_hour', 'DE-LU'), 1e-6);
        $this->assertSame(2, $this->lastJsonLine(Artisan::output())['counts']['de_lu_filled']);
    }

    /** (d) The window runs from Helsinki yesterday through the day after tomorrow, for every request. */
    public function test_the_window_starts_at_helsinki_yesterday(): void
    {
        $regionByEic = array_flip((array) config('services.entsoe.bidding_zones'));
        $yesterday = $this->quarterPoints('2026-10-30 10:00', 3.0);
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($regionByEic, $yesterday) {
            if (str_starts_with($request->url(), self::ENERGY_CHARTS_URL)) {
                return Http::response($this->energyChartsJson([]));
            }

            return Http::response($this->dayAheadXml($regionByEic[$request['in_Domain']] === 'SE1' ? $yesterday : []));
        });

        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));

        // 2026-10-31 14:00 Helsinki (UTC+2): 2026-10-30 00:00 through 2026-11-02 00:00 Helsinki.
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::ENERGY_CHARTS_URL)
            && [$request['bzn'], $request['start'], $request['end']] === ['DE-LU', '2026-10-29T22:00Z', '2026-11-01T22:00Z']);
        $this->assertSame(4, count(Http::recorded(fn (Request $request) => ($request->data()['periodStart'] ?? null) === '202610292200' && ($request->data()['periodEnd'] ?? null) === '202611012200')));
        $line = $this->lastJsonLine(Artisan::output());
        $header = json_decode(strtok($this->decompressedPayload($line['payload']['path']), "\n"), true);
        $this->assertSame(['2026-10-29T22:00:00Z', '2026-11-01T22:00:00Z'], [$header['facts']['period_start'], $header['facts']['period_end']]);
        $this->assertSame(1, SpotPriceHour::query()->forRegion('SE1')->where('timestamp', Carbon::parse('2026-10-30 10:00', 'UTC')->timestamp)->count(), 'A late row for yesterday is stored.');
    }

    /** @return array<string, array{0: int|string|ConnectionException}> */
    public static function failedEnergyChartsAnswers(): array
    {
        return [
            'HTTP 429' => [429],
            'connection failure' => [new ConnectionException('timeout')],
            'not JSON' => ['<html>Service unavailable</html>'],
            'wrong unit' => ['{"unix_seconds":[1793404800],"price":[1.0],"unit":"EUR / kWh"}'],
            'unaligned point' => ['{"unix_seconds":[1793404801],"price":[1.0],"unit":"EUR / MWh"}'],
        ];
    }

    /** (e) A failed or malformed Energy-Charts answer is a failed unit; the ENTSO-E rows are still stored. */
    #[DataProvider('failedEnergyChartsAnswers')]
    public function test_a_failed_energy_charts_answer_fails_the_run_and_keeps_the_entsoe_rows(int|string|ConnectionException $answer): void
    {
        Log::spy();
        $this->fakeZones(['SE1' => 2.0, 'SE3' => 8.0, 'EE' => 12.0, 'DE-LU' => 20.0], $answer);

        $this->assertSame(1, Artisan::call('spot:fetch-neighbours'));

        $line = $this->lastJsonLine(Artisan::output());
        $this->assertSame(
            ['failed', 'fetch', 'fetch_incomplete', false, ['records' => 16, 'regions' => 4, 'failed_regions' => 0, 'de_lu_filled' => 0, 'failed_fill' => 1]],
            [$line['status'], $line['step'], $line['error'], $line['complete'], $line['counts']],
        );
        $this->assertSame(16, SpotPriceQuarter::count());
        $this->assertSame(4, SpotPriceHour::count());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Neighbour spot import failed'
            && $context['region'] === 'DE-LU' && $context['source'] === 'energy-charts');
    }

    /** (f) A replay of a filled payload writes the same rows without HTTP. */
    public function test_a_replay_of_a_filled_payload_writes_the_same_rows(): void
    {
        $this->fakeSequenceTwoDay();
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours'));
        $line = $this->lastJsonLine(Artisan::output());
        $rows = $this->spotRows();

        SpotPriceQuarter::query()->delete();
        SpotPriceHour::query()->delete();
        Http::preventStrayRequests();
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame(0, Artisan::call('spot:fetch-neighbours', ['--from-payload' => $line['payload']['path']]));

        Http::assertNothingSent();
        $this->assertSame($rows, $this->spotRows());
        $this->assertSame($line['counts'], $this->lastJsonLine(Artisan::output())['counts']);
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
