<?php

namespace Tests\Feature;

use App\Services\Fmi\FmiMultiPointCoverageParser;
use App\Services\Fmi\FmiRequestException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchFmiDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HOURLY = 'fmi::observations::weather::hourly::multipointcoverage';

    private const RADIATION = 'fmi::observations::radiation::multipointcoverage';

    private const EDITED = 'fmi::forecast::edited::weather::scandinavia::point::multipointcoverage';

    private const ECMWF = 'ecmwf::forecast::surface::point::multipointcoverage';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 12, 30, 0, 'UTC'));
        config([
            'fmi.request_delay_seconds' => 0,
            'fmi.retry_sleep_ms' => 0,
            'fmi.weather_stations' => [
                101004 => ['name' => 'Helsinki Kumpula', 'latitude' => 60.20307, 'longitude' => 24.96131],
                100908 => ['name' => 'Parainen Utö', 'latitude' => 59.77909, 'longitude' => 21.37479],
            ],
            'fmi.radiation_stations' => [
                101004 => ['name' => 'Helsinki Kumpula', 'latitude' => 60.20307, 'longitude' => 24.96131],
            ],
            'fmi.neighbour_points' => [
                'ee01_hiiumaa' => ['latitude' => 58.8960, 'longitude' => 22.5605],
            ],
            'fmi.ecmwf_points' => [
                'de02_niedersachsen' => ['latitude' => 52.6367, 'longitude' => 9.8451],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A minimal FMI multipointcoverage document.
     *
     * @param  list<string>  $fields
     * @param  list<array{0: string, 1: float, 2: float}>  $points  gml:id, lat, lon
     * @param  list<array{0: float, 1: float, 2: string, 3: list<string>}>  $rows  lat, lon, ISO time, values
     */
    private function coverage(array $fields, array $points, array $rows, ?string $resultTime = null): string
    {
        $pointXml = '';
        foreach ($points as [$id, $lat, $lon]) {
            $pointXml .= "<gml:pointMember><gml:Point gml:id=\"{$id}\"><gml:pos>{$lat} {$lon} </gml:pos></gml:Point></gml:pointMember>";
        }
        $positions = '';
        $values = '';
        foreach ($rows as [$lat, $lon, $time, $tuple]) {
            $positions .= "{$lat} {$lon}  ".Carbon::parse($time, 'UTC')->timestamp."\n";
            $values .= implode(' ', $tuple)." \n";
        }
        $fieldXml = implode('', array_map(fn ($f) => "<swe:field name=\"{$f}\" />", $fields));
        $result = $resultTime !== null
            ? "<om:resultTime><gml:TimeInstant gml:id=\"t\"><gml:timePosition>{$resultTime}</gml:timePosition></gml:TimeInstant></om:resultTime>"
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:om="http://www.opengis.net/om/2.0" xmlns:omso="http://inspire.ec.europa.eu/schemas/omso/3.0" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:gmlcov="http://www.opengis.net/gmlcov/1.0" xmlns:swe="http://www.opengis.net/swe/2.0" xmlns:sams="http://www.opengis.net/samplingSpatial/2.0">
  <wfs:member>
    <omso:GridSeriesObservation gml:id="obs">
      {$result}
      <om:featureOfInterest><sams:SF_SpatialSamplingFeature gml:id="sf"><sams:shape><gml:MultiPoint gml:id="mp">{$pointXml}</gml:MultiPoint></sams:shape></sams:SF_SpatialSamplingFeature></om:featureOfInterest>
      <om:result>
        <gmlcov:MultiPointCoverage gml:id="cov">
          <gml:domainSet><gmlcov:SimpleMultiPoint gml:id="smp"><gmlcov:positions>{$positions}</gmlcov:positions></gmlcov:SimpleMultiPoint></gml:domainSet>
          <gml:rangeSet><gml:DataBlock><gml:rangeParameters/><gml:doubleOrNilReasonTupleList>{$values}</gml:doubleOrNilReasonTupleList></gml:DataBlock></gml:rangeSet>
          <gmlcov:rangeType><swe:DataRecord>{$fieldXml}</swe:DataRecord></gmlcov:rangeType>
        </gmlcov:MultiPointCoverage>
      </om:result>
    </omso:GridSeriesObservation>
  </wfs:member>
</wfs:FeatureCollection>
XML;
    }

    private function emptyCollection(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" numberMatched="0" numberReturned="0"></wfs:FeatureCollection>';
    }

    private function exceptionReport(string $text): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><ExceptionReport xmlns="http://www.opengis.net/ows/1.1"><Exception exceptionCode="OperationParsingFailed">'
            ."<ExceptionText>{$text}</ExceptionText><ExceptionText>URI: /wfs?storedquery_id=secret-looking-url</ExceptionText></Exception></ExceptionReport>";
    }

    /**
     * @param  array<string, callable(Request): mixed>  $byQuery  Stored query => response factory
     */
    private function fakeFmi(array $byQuery): void
    {
        Http::fake(function (Request $request) use ($byQuery) {
            foreach ($byQuery as $query => $response) {
                if (str_contains(urldecode($request->url()), 'storedquery_id='.$query.'&')) {
                    return $response($request);
                }
            }

            return Http::response($this->emptyCollection());
        });
    }

    /**
     * @return list<string>
     */
    private function queryValues(Request $request, string $name): array
    {
        $values = [];
        foreach (explode('&', (string) parse_url($request->url(), PHP_URL_QUERY)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (rawurldecode($key) === $name) {
                $values[] = rawurldecode($value);
            }
        }

        return $values;
    }

    /**
     * @return list<Request>
     */
    private function requestsFor(string $query): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains(urldecode($request->url()), 'storedquery_id='.$query.'&'))
            ->values()
            ->all();
    }

    public function test_hourly_values_are_stored_at_the_start_of_the_hour_they_describe(): void
    {
        $this->fakeFmi([
            self::HOURLY => fn () => Http::response($this->coverage(
                ['TA_PT1H_AVG', 'WS_PT1H_AVG'],
                [['point-100908', 59.77909, 21.37479], ['point-101004', 60.20307, 24.96131]],
                [
                    [59.77909, 21.37479, '2026-10-31T08:00:00Z', ['8.4', '6.0']],
                    [60.20307, 24.96131, '2026-10-31T08:00:00Z', ['7.2', 'NaN']],
                ]
            )),
        ]);

        $this->artisan('fmi:fetch --only=observed')->assertExitCode(0);

        $rows = DB::table('fmi_hourly_observations')->orderBy('fmisid')->orderBy('parameter')->get();
        $this->assertCount(3, $rows); // the NaN wind value is not stored
        $this->assertSame([100908, 100908, 101004], $rows->pluck('fmisid')->map(fn ($v) => (int) $v)->all());
        // FMI labels the mean of 07:00-08:00 with 08:00.
        $this->assertSame('2026-10-31 07:00:00', $rows[2]->utc_datetime);
        $this->assertSame(Carbon::parse('2026-10-31 07:00:00', 'UTC')->timestamp, (int) $rows[2]->timestamp);
        $this->assertSame('TA_PT1H_AVG', $rows[2]->parameter);
        $this->assertEqualsWithDelta(7.2, $rows[2]->value, 0.0001);
        $this->assertSame(1, (int) $rows[2]->sample_count);

        $request = $this->requestsFor(self::HOURLY)[0];
        $this->assertSame(['101004', '100908'], $this->queryValues($request, 'fmisid'));
        $this->assertSame(['TA_PT1H_AVG,WS_PT1H_AVG'], $this->queryValues($request, 'parameters'));
        // Hours 2026-10-29 12:00 .. 2026-11-01 11:00 are the labels 13:00 .. 12:00.
        $this->assertSame(['2026-10-29T13:00:00Z'], $this->queryValues($request, 'starttime'));
        $this->assertSame(['2026-11-01T12:00:00Z'], $this->queryValues($request, 'endtime'));
    }

    public function test_radiation_minutes_become_hourly_means_with_minute_counts(): void
    {
        $minute = fn (string $time, string $value) => [60.20307, 24.96131, $time, [$value]];
        $this->fakeFmi([
            self::RADIATION => fn () => Http::response($this->coverage(
                ['GLOB_1MIN'],
                [['point-101004', 60.20307, 24.96131]],
                [
                    $minute('2026-10-31T10:00:00Z', '50'),   // ends 10:00 -> hour 09:00
                    $minute('2026-10-31T10:01:00Z', '100'),
                    $minute('2026-10-31T10:30:00Z', 'NaN'),  // missing minute, not filled
                    $minute('2026-10-31T11:00:00Z', '200'),  // ends 11:00 -> hour 10:00
                ]
            )),
        ]);

        $this->artisan('fmi:fetch --only=radiation')->assertExitCode(0);

        $rows = DB::table('fmi_hourly_observations')->orderBy('timestamp')->get();
        $this->assertSame(['2026-10-31 09:00:00', '2026-10-31 10:00:00'], $rows->pluck('utc_datetime')->all());
        $this->assertSame(['GLOB_PT1H_AVG'], $rows->pluck('parameter')->unique()->values()->all());
        $this->assertEqualsWithDelta(150.0, $rows[1]->value, 0.0001);
        $this->assertSame(2, (int) $rows[1]->sample_count);
        $this->assertSame(1, (int) $rows[0]->sample_count);

        $request = $this->requestsFor(self::RADIATION)[0];
        $this->assertSame(['2026-10-29T12:01:00Z'], $this->queryValues($request, 'starttime'));
        $this->assertSame(['2026-11-01T12:00:00Z'], $this->queryValues($request, 'endtime'));
    }

    public function test_forecast_vintages_are_append_only_per_point(): void
    {
        $edited = function (string $kumpula0) {
            return fn () => Http::response($this->coverage(
                ['temperature', 'windspeedms', 'totalcloudcover'],
                [['point--16000138', 60.20307, 24.96131], ['point-588555', 58.89600, 22.56050]],
                [
                    [60.20307, 24.96131, '2026-11-01T12:00:00Z', [$kumpula0, '4.3', '97.2']],
                    [60.20307, 24.96131, '2026-11-01T13:00:00Z', ['8.5', 'NaN', '97.6']],
                    [60.20307, 24.96131, '2026-11-01T14:00:00Z', ['NaN', 'NaN', 'NaN']],
                    [58.89600, 22.56050, '2026-11-01T12:00:00Z', ['NaN', 'NaN', 'NaN']],
                    [58.89600, 22.56050, '2026-11-01T13:00:00Z', ['7.4', '1.4', '99.6']],
                ],
                '2026-11-01T11:44:45Z'
            ));
        };
        $ecmwf = fn () => Http::response($this->coverage(
            ['Temperature', 'WindUMS', 'WindVMS'],
            [['point-1', 52.63670, 9.84510]],
            [
                [52.63670, 9.84510, '2026-11-01T12:00:00Z', ['10.6', '3', '4']],
                [52.63670, 9.84510, '2026-11-01T13:00:00Z', ['10.4', 'NaN', '1']],
            ],
            '2026-11-01T08:02:31Z'
        ));

        $this->fakeFmi([self::EDITED => $edited('8.9'), self::ECMWF => $ecmwf]);
        // 100908 is configured but not in the response, so the run fails; the others are stored.
        $this->artisan('fmi:fetch --only=forecast')->assertExitCode(1);

        $kumpula = DB::table('hourly_forecast_runs')->where('point', '101004')->first();
        $this->assertSame('fmi', $kumpula->source);
        $this->assertSame('edited', $kumpula->product);
        $this->assertSame('2026-11-01 11:44:45', $kumpula->issued_at);
        $this->assertSame('2026-11-01 12:00:00', $kumpula->first_utc_datetime);
        $this->assertSame(2, (int) $kumpula->hour_count); // the all-NaN last hour is trimmed
        $this->assertEqualsWithDelta(60.20307, $kumpula->grid_latitude, 0.00001);
        $values = json_decode($kumpula->values, true);
        $this->assertSame([8.9, 8.5], $values['temperature_2m']);
        $this->assertSame([4.3, null], $values['wind_speed_10m']);
        $this->assertSame([97.2, 97.6], $values['total_cloud_cover']);
        $this->assertSame(hash('sha256', json_encode([(int) $kumpula->first_timestamp, $values])), $kumpula->payload_hash);

        $hiiumaa = DB::table('hourly_forecast_runs')->where('point', 'ee01_hiiumaa')->first();
        $this->assertSame('2026-11-01 13:00:00', $hiiumaa->first_utc_datetime); // leading all-NaN hour trimmed
        $this->assertSame(1, (int) $hiiumaa->hour_count);

        $de02 = DB::table('hourly_forecast_runs')->where('point', 'de02_niedersachsen')->first();
        $this->assertSame('ecmwf', $de02->product);
        $de02Values = json_decode($de02->values, true);
        $this->assertSame(['temperature_2m', 'wind_u_10m', 'wind_v_10m', 'wind_speed_10m'], array_keys($de02Values));
        $this->assertEquals([5.0, null], $de02Values['wind_speed_10m']);

        $request = $this->requestsFor(self::EDITED)[0];
        $this->assertSame(['101004', '100908'], $this->queryValues($request, 'fmisid'));
        $this->assertSame(['58.896,22.5605'], $this->queryValues($request, 'latlon'));
        $this->assertSame(['2026-11-01T12:00:00Z'], $this->queryValues($request, 'starttime'));
        $this->assertSame(['2026-11-11T12:00:00Z'], $this->queryValues($request, 'endtime'));
        $this->assertSame(['60'], $this->queryValues($request, 'timestep'));

        $this->assertSame(3, DB::table('hourly_forecast_runs')->count());
    }

    public function test_an_unchanged_forecast_is_not_stored_again_and_a_changed_one_is(): void
    {
        config(['fmi.weather_stations' => [101004 => ['name' => 'Kumpula', 'latitude' => 60.20307, 'longitude' => 24.96131]], 'fmi.neighbour_points' => [], 'fmi.forecasts.ecmwf.latlon_points' => null]);
        config(['fmi.forecasts' => ['edited' => config('fmi.forecasts.edited')]]);

        Http::fakeSequence()
            ->push($this->coverage(['temperature'], [['p', 60.20307, 24.96131]], [[60.20307, 24.96131, '2026-11-01T12:00:00Z', ['8.9']]], '2026-11-01T11:44:45Z'))
            ->push($this->coverage(['temperature'], [['p', 60.20307, 24.96131]], [[60.20307, 24.96131, '2026-11-01T12:00:00Z', ['8.9']]], '2026-11-01T11:44:45Z'))
            ->push($this->coverage(['temperature'], [['p', 60.20307, 24.96131]], [[60.20307, 24.96131, '2026-11-01T12:00:00Z', ['9.1']]], '2026-11-01T12:44:45Z'));

        $this->artisan('fmi:fetch --only=forecast')->assertExitCode(0);
        $this->artisan('fmi:fetch --only=forecast')->assertExitCode(0);
        $this->assertSame(1, DB::table('hourly_forecast_runs')->count());

        $this->artisan('fmi:fetch --only=forecast')->assertExitCode(0);
        $this->assertSame(2, DB::table('hourly_forecast_runs')->count());
    }

    public function test_missing_forecast_point_fails_the_run(): void
    {
        $this->fakeFmi([
            self::EDITED => fn () => Http::response($this->coverage(
                ['temperature'],
                [['p', 60.20307, 24.96131]],
                [[60.20307, 24.96131, '2026-11-01T12:00:00Z', ['8.9']]]
            )),
            self::ECMWF => fn () => Http::response($this->coverage(
                ['Temperature', 'WindUMS', 'WindVMS'],
                [['p', 52.6367, 9.8451]],
                [[52.6367, 9.8451, '2026-11-01T12:00:00Z', ['10', '1', '1']]]
            )),
        ]);

        $this->artisan('fmi:fetch --only=forecast')
            ->expectsOutputToContain('no values for point(s) 100908, ee01_hiiumaa')
            ->assertExitCode(1);

        $this->assertSame(['101004', 'de02_niedersachsen'], DB::table('hourly_forecast_runs')->orderBy('point')->pluck('point')->all());
    }

    public function test_fmi_exception_fails_only_its_part_and_the_log_has_no_url(): void
    {
        Log::spy();
        $this->fakeFmi([
            self::HOURLY => fn () => Http::response($this->exceptionReport('No more than 744 hours allowed.'), 400),
            self::RADIATION => fn () => Http::response($this->coverage(
                ['GLOB_1MIN'],
                [['point-101004', 60.20307, 24.96131]],
                [[60.20307, 24.96131, '2026-10-31T10:01:00Z', ['100']]]
            )),
        ]);

        $this->artisan('fmi:fetch --only=observed --only=radiation')
            ->expectsOutputToContain('No more than 744 hours allowed.')
            ->assertExitCode(1);

        $this->assertSame(1, DB::table('fmi_hourly_observations')->where('parameter', 'GLOB_PT1H_AVG')->count());
        $this->assertCount(1, $this->requestsFor(self::HOURLY)); // a 400 is not retried

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            return $message === 'FMI fetch failed'
                && $context['part'] === 'observed'
                && $context['exception_class'] === FmiRequestException::class
                && $context['message'] === 'FMI request failed: No more than 744 hours allowed.'
                && ! str_contains(json_encode($context), 'storedquery_id')
                && ! str_contains(json_encode($context), 'http');
        })->once();
    }

    public function test_server_errors_are_retried(): void
    {
        $attempts = 0;
        $this->fakeFmi([
            self::HOURLY => function () use (&$attempts) {
                $attempts++;

                return $attempts < 3
                    ? Http::response('busy', 503)
                    : Http::response($this->coverage(['TA_PT1H_AVG', 'WS_PT1H_AVG'], [['point-101004', 60.20307, 24.96131]], [[60.20307, 24.96131, '2026-10-31T08:00:00Z', ['7.2', '2.6']]]));
            },
        ]);

        $this->artisan('fmi:fetch --only=observed')->assertExitCode(0);

        $this->assertSame(3, $attempts);
        $this->assertSame(2, DB::table('fmi_hourly_observations')->count());
    }

    public function test_backfill_uses_744_and_168_hour_pieces(): void
    {
        $this->fakeFmi([]);

        // 2026-10-01 .. 2026-10-31 = 744 hours: one hourly request, five radiation requests.
        $this->artisan('fmi:fetch --from=2026-10-01 --to=2026-10-31')->assertExitCode(0);

        $hourly = $this->requestsFor(self::HOURLY);
        $this->assertCount(1, $hourly);
        $this->assertSame(['2026-10-01T01:00:00Z'], $this->queryValues($hourly[0], 'starttime'));
        $this->assertSame(['2026-11-01T00:00:00Z'], $this->queryValues($hourly[0], 'endtime'));

        $radiation = $this->requestsFor(self::RADIATION);
        $this->assertCount(5, $radiation);
        $this->assertSame(['2026-10-08T00:01:00Z'], $this->queryValues($radiation[1], 'starttime'));
        $this->assertSame(['2026-10-15T00:00:00Z'], $this->queryValues($radiation[1], 'endtime'));

        $this->assertCount(0, $this->requestsFor(self::EDITED));
    }

    public function test_rejects_unknown_parts_and_forecast_backfills(): void
    {
        Http::fake();

        $this->artisan('fmi:fetch --only=snow')->assertExitCode(1);
        $this->artisan('fmi:fetch --only=forecast --from=2026-10-01')->assertExitCode(1);
        $this->artisan('fmi:fetch --from=2026-13-01')->assertExitCode(1);
        $this->artisan('fmi:fetch --from=2026-10-01 --to=2026-02-30')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_parser_rejects_a_doctype(): void
    {
        $this->expectException(FmiRequestException::class);

        app(FmiMultiPointCoverageParser::class)->parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
    }

    public function test_parser_rejects_mismatched_value_counts(): void
    {
        $xml = $this->coverage(['TA_PT1H_AVG', 'WS_PT1H_AVG'], [['point-1', 60.0, 25.0]], [[60.0, 25.0, '2026-10-31T08:00:00Z', ['7.2']]]);

        $this->expectException(FmiRequestException::class);
        app(FmiMultiPointCoverageParser::class)->parse($xml);
    }
}
