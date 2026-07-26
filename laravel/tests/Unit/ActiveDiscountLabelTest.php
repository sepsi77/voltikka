<?php

namespace Tests\Unit;

use App\Models\ElectricityContract;
use PHPUnit\Framework\TestCase;

/**
 * `ElectricityContract::formatActiveDiscountValue()` names the unit of a
 * promotion, and the component TYPE decides it, not the stored `payment_unit`.
 *
 * The upstream payload writes `payment_unit` verbatim, and 563 stored `Monthly`
 * rows across 25 contracts carry `CentPerKiwattHour`. Trusting that printed
 * "-5,90 c/kWh alennus" for a base-fee waiver on two live contracts, which reads
 * as roughly 295 EUR/yr off at 5 000 kWh instead of the real 71 EUR/yr.
 *
 * `ContractPriceCalculator` already costs such a component on the monthly branch
 * (`$paymentUnit === 'EurPerMonth' || $componentType === 'Monthly'`), so before
 * this the label and the arithmetic disagreed about the same promotion.
 */
class ActiveDiscountLabelTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function label(array $overrides): ?string
    {
        return (new ElectricityContract)->formatActiveDiscountValue(array_merge([
            'value' => 5.90,
            'is_percentage' => false,
            'n_first_months' => 2,
            'until_date' => null,
            'price_component_type' => 'Monthly',
            'payment_unit' => 'EurPerMonth',
        ], $overrides));
    }

    public function test_a_monthly_component_is_a_monthly_fee_whatever_the_stored_unit_says(): void
    {
        $this->assertSame(
            '-5,90 €/kk alennus',
            $this->label(['payment_unit' => 'CentPerKiwattHour']),
            'A base-fee waiver must never be advertised as a per-kWh discount.'
        );
    }

    public function test_a_monthly_component_with_no_unit_is_still_a_monthly_fee(): void
    {
        $this->assertSame('-5,90 €/kk alennus', $this->label(['payment_unit' => null]));
    }

    public function test_an_energy_component_keeps_its_per_kwh_unit(): void
    {
        $this->assertSame(
            '-5,90 c/kWh alennus',
            $this->label(['price_component_type' => 'General', 'payment_unit' => 'CentPerKiwattHour'])
        );
    }

    public function test_an_energy_component_billed_monthly_is_a_monthly_fee(): void
    {
        $this->assertSame(
            '-5,90 €/kk alennus',
            $this->label(['price_component_type' => 'General', 'payment_unit' => 'EurPerMonth'])
        );
    }

    public function test_a_percentage_discount_never_names_a_unit(): void
    {
        $this->assertSame(
            '-25% alennus',
            $this->label(['value' => 25.0, 'is_percentage' => true, 'payment_unit' => 'CentPerKiwattHour'])
        );
    }

    public function test_an_unknown_unit_on_a_non_monthly_component_drops_the_unit(): void
    {
        $this->assertSame(
            '-5,90 alennus',
            $this->label(['price_component_type' => 'General', 'payment_unit' => 'SomethingNew'])
        );
    }
}
