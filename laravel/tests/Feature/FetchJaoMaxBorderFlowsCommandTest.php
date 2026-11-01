<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        $this->artisan('jao:fetch --from=2026-13-01')->assertExitCode(1);
        $this->artisan('jao:fetch --from=2026-11-05')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
