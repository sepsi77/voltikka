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

    public function test_invalid_exact_stored_row_does_not_fall_back_to_an_older_stored_level(): void
    {
        $this->average('2025-05-31', 8760, 7.0, 8.0, 5.0);
        $this->average('2025-06-01', 1, 99.0, 99.0, 99.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertFalse($result->isAvailable());
        $this->assertSame(AsOfSpotAssumptionsResult::SOURCE_UNAVAILABLE, $result->source);
    }

    public function test_stored_coverage_below_98_percent_is_rejected(): void
    {
        $expectedHours = 8760;
        $this->average('2025-06-01', (int) floor($expectedHours * 0.979), 7.0, 8.0, 5.0);

        $result = (new AsOfSpotAssumptionsProvider)->resolve($this->date('2025-06-01'));

        $this->assertFalse($result->isAvailable());
        $this->assertSame(AsOfSpotAssumptionsResult::SOURCE_UNAVAILABLE, $result->source);
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
            'period_type' => 'rolling_365d',
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
