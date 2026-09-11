<?php

namespace Tests\Unit;

use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class VersionedRelationalAnnualProfileTest extends TestCase
{
    public function test_uniform_option_changes_season_weighting_but_keeps_component_discount_scope(): void
    {
        $components = [
            ['price_component_type' => 'SeasonalWinterDay', 'price' => 12.0, 'payment_unit' => 'c/kWh'],
            [
                'price_component_type' => 'SeasonalOther', 'price' => 6.0, 'payment_unit' => 'c/kWh',
                'has_discount' => true, 'discount_value' => 2.0, 'discount_is_percentage' => false,
                'discount_type' => 'NFirstKwh', 'discount_discount_n_first_kwh' => 1000,
            ],
        ];
        $contract = ['pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', 'metering' => 'Season'];
        $usage = new EnergyUsage(total: 12000, basicLiving: 12000);
        $date = CarbonImmutable::parse('2026-06-01', 'Europe/Helsinki');
        $legacy = (new ContractPriceCalculator)->calculate($components, $contract, $usage, calculationStartDate: $date);
        $uniform = (new ContractPriceCalculator(uniformSeasonalConsumption: true))->calculate($components, $contract, $usage, calculationStartDate: $date);

        $this->assertEqualsWithDelta(975.0, $uniform->baseTotalCost, 0.00001);
        $this->assertEqualsWithDelta(20.0, $uniform->discountSavingsTotal, 0.00001);
        $this->assertEqualsWithDelta(955.0, $uniform->totalCost, 0.00001);
        $this->assertGreaterThan($uniform->baseTotalCost, $legacy->baseTotalCost);
        $explicitLegacy = (new ContractPriceCalculator(uniformSeasonalConsumption: false))->calculate($components, $contract, $usage, calculationStartDate: $date);
        $this->assertSame($legacy->totalCost, $explicitLegacy->totalCost);
    }
}
