<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CurrentShortDurationSafetyTest extends TestCase
{
    private function fixture(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/sulaketariffi-five-duplicate-monthly-fees.json'), true, flags: JSON_THROW_ON_ERROR);
        $phase = &$fixture['pricing']['phases'][0];
        $phase['components'] = array_slice($phase['components'], 0, 2);
        $phase['components'][0]['amount'] = 10.0;
        $phase['components'][1]['amount'] = 4.0;
        $phase['components'][1]['normal_amount'] = 6.0;
        $phase['starts'] = ['kind' => 'contract_start', 'value' => null];
        $phase['ends'] = ['kind' => 'none', 'value' => null];

        return $fixture;
    }

    private function calculate(array $fixture, string $range, ComparisonPolicy $policy)
    {
        $curve = $this->createMock(MarketReferenceCurveProvider::class);
        $calculator = new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($curve), new SupplierAdjustedPriceEstimator($curve));
        $data = (new CanonicalPricingParser)->parse($fixture['pricing'], $fixture['calculation'], $fixture['source_consistency']);

        return $calculator->calculate($data, new ContractContext('FixedPrice', 'FixedTerm', 'General', $range, 'Household'), new EnergyUsage(total: 12000, basicLiving: 12000), new SpotAssumptions(null, null), CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki'), policy: $policy);
    }

    public function test_short_ranges_do_not_prove_duration_even_with_finite_phase_ends(): void
    {
        foreach (['Below6' => 3, 'Between711' => 9] as $range => $end) {
            $fixture = $this->fixture();
            $fixture['pricing']['phases'][0]['ends'] = ['kind' => 'after_months', 'value' => $end];
            $current = $this->calculate($fixture, $range, ComparisonPolicy::Current);
            $historical = $this->calculate($fixture, $range, ComparisonPolicy::Historical);
            $this->assertSame('excluded_incomplete', $current->comparability->value);
            $this->assertContains('unknown_short_fixed_term_duration', $current->assumptions);
            $this->assertNotNull($historical->totalCost);
            $this->assertNotContains('unknown_short_fixed_term_duration', $historical->assumptions);
        }
        foreach (['Fixed6', 'Fixed12', 'Fixed24', 'Between1323', 'Over24'] as $range) {
            $fixture = $this->fixture();
            $fixture['pricing']['phases'][0]['components'][1]['normal_amount'] = null;
            $this->assertNotNull($this->calculate($fixture, $range, ComparisonPolicy::Current)->totalCost);
        }
    }

    public function test_post_term_energy_cannot_prove_energy_during_a_fee_only_term(): void
    {
        $fixture = $this->fixture();
        $future = $fixture['pricing']['phases'][0];
        $future['starts'] = ['kind' => 'after_months', 'value' => 6];
        $future['phase_kind'] = 'normal';
        $fixture['pricing']['phases'][0]['components'] = [$fixture['pricing']['phases'][0]['components'][1]];
        $fixture['pricing']['phases'][0]['ends'] = ['kind' => 'after_months', 'value' => 6];
        $fixture['pricing']['phases'][] = $future;
        $current = $this->calculate($fixture, 'Fixed6', ComparisonPolicy::Current);
        $this->assertNull($current->totalCost);
        $this->assertNotNull($this->calculate($fixture, 'Fixed6', ComparisonPolicy::Historical)->totalCost);
    }

    public function test_proven_in_term_energy_and_normal_fee_are_independent_of_post_term_price(): void
    {
        foreach ([2.0, 30.0] as $price) {
            $fixture = $this->fixture();
            $future = $fixture['pricing']['phases'][0];
            $future['starts'] = ['kind' => 'after_months', 'value' => 6];
            $future['components'][0]['amount'] = $price;
            $future['components'][1]['normal_amount'] = null;
            $fixture['pricing']['phases'][0]['ends'] = ['kind' => 'after_months', 'value' => 6];
            $fixture['pricing']['phases'][] = $future;
            $outcome = $this->calculate($fixture, 'Fixed6', ComparisonPolicy::Current);
            $this->assertEqualsWithDelta(624.0, $outcome->contractTermTotalCost, 0.001);
            $this->assertEqualsWithDelta(636.0, $outcome->contractTermBaseTotalCost, 0.001);
            $this->assertEqualsWithDelta(1248.0, $outcome->totalCost, 0.001);
            $this->assertEqualsWithDelta(1272.0, $outcome->baseTotalCost, 0.001);
        }
    }
}
