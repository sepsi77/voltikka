<?php

namespace Tests\Feature;

use App\Services\ContractStatistics\AsOfSpotAssumptionsProvider;
use App\Services\ContractStatistics\DTO\AsOfSpotAssumptionsResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AsOfSpotAssumptionsProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_stored_rows_are_ignored(): void
    {
        $this->average('2025-06-01', 8760, 7.0, 8.0, 5.0);
        $this->average('2025-06-02', 8760, 99.0, 99.0, 99.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertTrue($result->isAvailable());
        $this->assertSame(AsOfSpotAssumptionsResult::SOURCE_STORED_ROLLING_365D, $result->source);
        $this->assertSame(7.0, $result->assumptions?->overallAvgWithTax);
        $this->assertSame('2024-06-02', $result->assumptions?->periodStart?->toDateString());
        $this->assertSame('2025-06-01', $result->assumptions?->periodEnd?->toDateString());
    }

    public function test_older_stored_level_is_never_carried_to_a_target_without_exact_stored_evidence(): void
    {
        $this->average('2025-05-31', 8760, 7.0, 8.0, 5.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertFalse($result->isAvailable());
        $this->assertSame(AsOfSpotAssumptionsResult::SOURCE_UNAVAILABLE, $result->source);
        $this->assertSame('incomplete_hourly_coverage', $result->unavailableReason);
    }

    public function test_sparse_exact_stored_row_retains_its_level_not_an_older_level(): void
    {
        $this->average('2025-05-31', 8760, 7.0, 8.0, 5.0);
        $this->average('2025-06-01', 1, 99.0, 99.0, 99.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertTrue($result->isAvailable());
        $this->assertSame(99.0, $result->assumptions->overallAvgWithTax);
        $this->assertContains('partial_below_threshold', $result->provenanceFlags);
    }

    public function test_stored_coverage_below_98_percent_keeps_the_level_with_honest_coverage(): void
    {
        $expectedHours = 8760;
        $this->average('2025-06-01', (int) floor($expectedHours * 0.979), 7.0, 8.0, 5.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertTrue($result->isAvailable());
        $this->assertContains('partial_below_threshold', $result->provenanceFlags);
        $this->assertSame((int) floor($expectedHours * 0.979), $result->assumptions->actualHours);
    }

    public function test_stored_coverage_at_least_98_percent_is_accepted_as_partial(): void
    {
        $expectedHours = 8760;
        $actualHours = (int) ceil($expectedHours * 0.98);
        $this->average('2025-06-01', $actualHours, 7.0, 8.0, 5.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertTrue($result->isAvailable());
        $this->assertSame($expectedHours, $result->expectedHours);
        $this->assertSame($actualHours, $result->actualHours);
        $this->assertGreaterThanOrEqual(0.98, $result->coverageRatio);
        $this->assertContains(AsOfSpotAssumptionsResult::EVIDENCE_PARTIAL_ABOVE_THRESHOLD, $result->provenanceFlags);
    }

    public function test_complete_stored_coverage_is_flagged_complete(): void
    {
        $this->average('2025-06-01', 8760, 7.0, 8.0, 5.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertTrue($result->isAvailable());
        $this->assertSame(1.0, $result->coverageRatio);
        $this->assertSame(8760, $result->expectedHours);
        $this->assertSame(8760, $result->actualHours);
        $this->assertContains(AsOfSpotAssumptionsResult::EVIDENCE_COMPLETE, $result->provenanceFlags);
    }

    public function test_partial_raw_coverage_is_unavailable_and_never_averaged(): void
    {
        $this->hour(CarbonImmutable::parse('2024-06-01 21:00:00', 'UTC'), 10.0);
        $this->hour(CarbonImmutable::parse('2025-06-01 21:00:00', 'UTC'), 99.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertFalse($result->isAvailable());
        $this->assertSame(AsOfSpotAssumptionsResult::SOURCE_UNAVAILABLE, $result->source);
        $this->assertSame('incomplete_hourly_coverage', $result->unavailableReason);
        $this->assertSame(8760, $result->expectedHours);
        $this->assertSame(1, $result->actualHours);
        $this->assertContains('raw_hourly_reconstruction_strict', $result->provenanceFlags);
        $this->assertNull($result->assumptions);
    }

    public function test_partial_raw_day_and_night_levels_remain_available_with_actual_coverage(): void
    {
        $this->hour(CarbonImmutable::parse('2025-06-01 04:00:00', 'UTC'), 10.0);
        $this->hour(CarbonImmutable::parse('2025-06-01 19:00:00', 'UTC'), 5.0);
        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));
        $this->assertTrue($result->isAvailable());
        $this->assertSame(2, $result->actualHours);
        $this->assertSame(2 / 8760, $result->coverageRatio);
        $this->assertSame('helsinki_dates_v2', $result->assumptions->windowSemantics);
        $this->assertContains('partial_below_threshold', $result->provenanceFlags);
        $this->assertSame(12.4, $result->assumptions->dayAvgWithTax);
        $this->assertSame(6.2, $result->assumptions->nightAvgWithTax);
    }

    public function test_stored_hour_count_is_leap_and_dst_aware(): void
    {
        $this->average('2024-03-30', 8762, 7.0, 8.0, 5.0);

        $invalid = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2024-03-30'));
        $this->assertFalse($invalid->isAvailable());

        DB::table('spot_price_averages')
            ->where('period_end', '2024-03-30')
            ->update(['hours_count' => 8761]);

        $valid = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2024-03-30'));
        $this->assertTrue($valid->isAvailable());
        $this->assertSame(8761, $valid->expectedHours);
        $this->assertSame(8761, $valid->actualHours);
        $this->assertSame('2023-04-01', $valid->assumptions?->periodStart?->toDateString());
    }

    public function test_exact_hourly_evidence_reconstructs_day_night_and_overall_averages(): void
    {
        $target = $this->date('2025-03-30');
        $startUtc = $target->subDays(364)->startOfDay()->utc();
        $endUtc = $target->addDay()->startOfDay()->utc();
        $rows = [];
        $dayTotal = 0.0;
        $nightTotal = 0.0;
        $dayCount = 0;
        $nightCount = 0;

        for ($instant = $startUtc; $instant->lessThan($endUtc); $instant = $instant->addHour()) {
            $localHour = $instant->setTimezone('Europe/Helsinki')->hour;
            $withoutTax = $localHour >= 7 && $localHour < 22 ? 10.0 : 5.0;
            $withTax = $withoutTax * 1.24;
            if ($localHour >= 7 && $localHour < 22) {
                $dayTotal += $withTax;
                $dayCount++;
            } else {
                $nightTotal += $withTax;
                $nightCount++;
            }

            $rows[] = [
                'region' => 'FI',
                'timestamp' => $instant->timestamp,
                'utc_datetime' => $instant->format('Y-m-d H:i:s'),
                'price_without_tax' => $withoutTax,
                'vat_rate' => 0.24,
            ];

            if (count($rows) === 500) {
                DB::table('spot_prices_hour')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('spot_prices_hour')->insert($rows);
        }

        $result = (new AsOfSpotAssumptionsProvider)->resolve($target);

        $this->assertTrue($result->isAvailable(), $result->unavailableReason ?? 'No unavailable reason.');
        $this->assertSame(AsOfSpotAssumptionsResult::SOURCE_HOURLY_RECONSTRUCTION, $result->source);
        $this->assertSame(8759, $result->expectedHours);
        $this->assertSame(8759, $result->actualHours);
        $this->assertSame(1.0, $result->coverageRatio);
        $this->assertContains(AsOfSpotAssumptionsResult::EVIDENCE_COMPLETE, $result->provenanceFlags);
        $this->assertEqualsWithDelta($dayTotal / $dayCount, $result->assumptions?->dayAvgWithTax, 0.000001);
        $this->assertEqualsWithDelta($nightTotal / $nightCount, $result->assumptions?->nightAvgWithTax, 0.000001);
        $this->assertEqualsWithDelta(
            ($dayTotal + $nightTotal) / ($dayCount + $nightCount),
            $result->assumptions?->overallAvgWithTax,
            0.000001,
        );
        $this->assertSame('2024-03-31', $result->assumptions?->periodStart?->toDateString());
        $this->assertSame('2025-03-30', $result->assumptions?->periodEnd?->toDateString());

        // This high boundary value belongs to the next Helsinki date, not this window.
        $this->hour($endUtc, 999.0);
        $timezone = date_default_timezone_get();
        try {
            foreach (['UTC', 'Europe/Helsinki'] as $zone) {
                date_default_timezone_set($zone);
                config(['app.timezone' => $zone]);
                $row = (new \App\Services\SpotPriceAverageService)->calculateRolling365DayAverage($target->toMutable());
                $stored = (new AsOfSpotAssumptionsProvider)->resolve($target);
                $this->assertSame(AsOfSpotAssumptionsResult::SOURCE_STORED_ROLLING_365D, $stored->source);
                $this->assertSame($result->actualHours, $row->hours_count);
                $this->assertEqualsWithDelta($result->assumptions->dayAvgWithTax, $row->day_avg_with_tax, 0.000001);
                $this->assertEqualsWithDelta($result->assumptions->nightAvgWithTax, $row->night_avg_with_tax, 0.000001);
                $this->assertEqualsWithDelta($result->assumptions->overallAvgWithTax, $row->avg_price_with_tax, 0.000001);
                $this->assertSame($result->assumptions->coverage(), $stored->assumptions->coverage());
            }
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_legacy_stored_window_is_not_claimed_as_exact_helsinki_evidence(): void
    {
        $this->average('2025-06-01', 8760, 7.0, 8.0, 5.0);
        DB::table('spot_price_averages')->update(['period_type' => 'rolling_365d']);
        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));
        $this->assertTrue($result->isAvailable());
        $this->assertSame('legacy_utc_dates', $result->assumptions->windowSemantics);
        $this->assertNotContains(AsOfSpotAssumptionsResult::EVIDENCE_COMPLETE, $result->provenanceFlags);
        $this->assertContains('unverified_shape_window', $result->provenanceFlags);
    }

    public function test_current_builder_carries_sparse_coverage_and_marks_legacy_windows(): void
    {
        $this->average('2025-06-01', 2, 7.0, 8.0, 5.0);
        $service = app(\App\Services\CanonicalPricing\CanonicalContractPricingService::class);
        $shape = $service->spotAssumptions();
        $this->assertSame(2, $shape->actualHours);
        $this->assertSame(8760, $shape->expectedHours);
        $this->assertSame('helsinki_dates_v2', $shape->windowSemantics);
        DB::table('spot_price_averages')->update(['period_type' => 'rolling_365d']);
        $legacy = app()->build(\App\Services\CanonicalPricing\CanonicalContractPricingService::class)->spotAssumptions();
        $this->assertNull($legacy->periodStart);
        $this->assertNull($legacy->expectedHours);
        $this->assertSame(2, $legacy->actualHours);
        $this->assertSame('legacy_utc_dates', $legacy->windowSemantics);
    }

    private function average(
        string $end,
        int $hours,
        float $overall,
        float $day,
        float $night,
    ): void {
        DB::table('spot_price_averages')->insert([
            'region' => 'FI',
            'period_type' => 'rolling_365d_local',
            'period_start' => $end,
            'period_end' => $end,
            'avg_price_without_tax' => $overall / 1.24,
            'avg_price_with_tax' => $overall,
            'day_avg_without_tax' => $day / 1.24,
            'day_avg_with_tax' => $day,
            'night_avg_without_tax' => $night / 1.24,
            'night_avg_with_tax' => $night,
            'hours_count' => $hours,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hour(CarbonImmutable $instant, float $withoutTax): void
    {
        DB::table('spot_prices_hour')->insert([
            'region' => 'FI',
            'timestamp' => $instant->timestamp,
            'utc_datetime' => $instant->format('Y-m-d H:i:s'),
            'price_without_tax' => $withoutTax,
            'vat_rate' => 0.24,
        ]);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/Helsinki')->startOfDay();
    }
}
