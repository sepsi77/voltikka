<?php

namespace Tests\Unit;

use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractRankingService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class ContractRankingTypedMetricsTest extends TestCase
{
    public function test_a_cached_metric_without_a_total_fails_before_it_can_become_a_zero_euro_recommendation(): void
    {
        Cache::flush();
        Cache::put('contract_list_metrics:v1:s13:c0r0:5000', [
            'contracts' => [
                'cheap' => [
                    'calculated_cost' => $this->legacyPricingWithoutTotal(),
                    'emission_factor' => 0.0,
                    'exceeds_consumption_limit' => false,
                    'total_cost' => 0.0,
                    'comparability' => null,
                    'is_listed' => true,
                    'sort_key' => 0.0,
                    'pricing_integrity' => null,
                ],
            ],
            'sorted_ids' => ['cheap'],
            'excluded_ids' => [],
            'consumption' => 5000,
        ]);

        $canonical = $this->createMock(CanonicalContractPricingService::class);
        $canonical->method('enabled')->willReturn(false);
        $mode = new PricingMode(canonicalPricingEnabled: false, resetForwardShiftEnabled: false);
        $listCache = new ContractListCacheService(
            $this->createMock(ContractPriceCalculator::class),
            $this->createMock(CO2EmissionsCalculator::class),
            $canonical,
            $mode,
        );
        $ranking = new ContractRankingService(
            $this->createMock(ContractPriceCalculator::class),
            $listCache,
            $canonical,
            $mode,
        );

        $this->expectException(InvalidArgumentException::class);
        $ranking->getCheaperContracts('viewed', 5000);
    }

    private function legacyPricingWithoutTotal(): array
    {
        return [
            'avg_monthly_cost' => 45.0,
            'monthly_costs' => array_fill(0, 12, 45.0),
            'monthly_fixed_fee' => 4.0,
            'spot_price_margin' => null,
            'general_kwh_price' => 9.84,
            'nighttime_kwh_price' => null,
            'daytime_kwh_price' => null,
            'seasonal_winter_day_kwh_price' => null,
            'seasonal_other_kwh_price' => null,
            'spot_price_day_avg' => null,
            'spot_price_night_avg' => null,
            'is_spot_contract' => false,
            'base_total_cost' => 540.0,
            'base_avg_monthly_cost' => 45.0,
            'base_monthly_costs' => array_fill(0, 12, 45.0),
            'discount_savings_total' => 0.0,
            'monthly_discount_savings' => array_fill(0, 12, 0.0),
            'includes_discounts' => false,
        ];
    }
}
