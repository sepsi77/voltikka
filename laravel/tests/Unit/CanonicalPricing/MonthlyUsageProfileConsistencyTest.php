<?php

namespace Tests\Unit\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\Support\MonthlyUsageProfileBuilder;
use App\Services\DTO\EnergyUsage;
use PHPUnit\Framework\TestCase;

class MonthlyUsageProfileConsistencyTest extends TestCase
{
    public function test_default_monthly_consumption_is_flat_for_every_tariff(): void
    {
        $builder = new MonthlyUsageProfileBuilder;
        foreach ([MeteringType::General, MeteringType::Time, MeteringType::Season] as $metering) {
            foreach ([false, true] as $spot) {
                $profile = $builder->build($metering, new EnergyUsage(total: 12000, basicLiving: 12000), $spot);
                foreach ($profile as $month) {
                    $this->assertEqualsWithDelta(1000, array_sum($month), 0.0001);
                }
            }
        }
    }

    public function test_explicit_heating_and_cooling_keep_the_same_shape_across_tariffs(): void
    {
        $usage = new EnergyUsage(
            total: 2400,
            basicLiving: 1200,
            roomHeating: 900,
            cooling: 300,
            heatingElectricityUseByMonth: [500, 300, 100, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        );
        $expected = [600, 400, 200, 100, 100, 200, 200, 200, 100, 100, 100, 100];
        $builder = new MonthlyUsageProfileBuilder;
        foreach ([MeteringType::General, MeteringType::Time, MeteringType::Season] as $metering) {
            $profile = $builder->build($metering, $usage);
            $totals = array_map(array_sum(...), $profile);
            $this->assertEquals($expected, $totals);
            $this->assertEqualsWithDelta(2400, array_sum($totals), 0.0001);
        }
    }
}
