<?php

namespace Tests\Unit;

use App\Models\ElectricityContract;
use PHPUnit\Framework\TestCase;

class ElectricityContractTest extends TestCase
{
    /**
     * Test that Finnish characters are properly converted in slugs.
     */
    public function test_slug_generation_handles_finnish_characters(): void
    {
        // Test typical Finnish contract names
        $this->assertEquals('fortum-tarkka', ElectricityContract::generateSlug('Fortum Tarkka'));
        $this->assertEquals('helen-sahko', ElectricityContract::generateSlug('Helen Sähkö'));
        $this->assertEquals('vaasan-sahko-yleinen', ElectricityContract::generateSlug('Vaasan Sähkö Yleinen'));

        // Test with ä, ö, å
        $this->assertEquals('tampereen-sahkolaitos-perushinta', ElectricityContract::generateSlug('Tampereen Sähkölaitos Perushinta'));
        $this->assertEquals('pohjois-karjalan-sahko', ElectricityContract::generateSlug('Pohjois-Karjalan Sähkö'));
    }

    /**
     * Test that the slug generation matches the Python implementation.
     */
    public function test_slug_matches_python_implementation(): void
    {
        // These test cases match the Python name_to_slug function behavior
        $this->assertEquals('spot-hinta', ElectricityContract::generateSlug('Spot Hinta'));
        $this->assertEquals('kiintea-hinta-12kk', ElectricityContract::generateSlug('Kiinteä Hinta 12kk'));
        $this->assertEquals('aikasiirto-sopimus', ElectricityContract::generateSlug('Aikasiirto Sopimus'));
    }

    /**
     * Test that special characters are handled correctly.
     */
    public function test_slug_removes_special_characters(): void
    {
        // Test with parentheses
        $this->assertEquals('sopimus-spot', ElectricityContract::generateSlug('Sopimus (Spot)'));
        // Test with plus sign
        $this->assertEquals('fortum-tarkka', ElectricityContract::generateSlug('Fortum Tarkka+'));
        // Test with percentage
        $this->assertEquals('vihrea-100-tuulivoima', ElectricityContract::generateSlug('Vihreä 100% Tuulivoima'));
    }

    /**
     * Test that underscores are handled correctly.
     */
    public function test_slug_replaces_underscores(): void
    {
        $this->assertEquals('test-contract', ElectricityContract::generateSlug('test_contract'));
        $this->assertEquals('my-test-contract', ElectricityContract::generateSlug('my_test_contract'));
    }

    /**
     * Test that consecutive spaces/hyphens are collapsed.
     */
    public function test_slug_collapses_consecutive_separators(): void
    {
        $this->assertEquals('test-contract', ElectricityContract::generateSlug('test  contract'));
        $this->assertEquals('test-contract', ElectricityContract::generateSlug('test - contract'));
    }

    /**
     * Test model configuration.
     */
    public function test_model_has_correct_table_name(): void
    {
        $contract = new ElectricityContract();
        $this->assertEquals('electricity_contracts', $contract->getTable());
    }

    /**
     * Test model has correct primary key settings.
     */
    public function test_model_has_string_primary_key(): void
    {
        $contract = new ElectricityContract();
        $this->assertEquals('id', $contract->getKeyName());
        $this->assertEquals('string', $contract->getKeyType());
        $this->assertFalse($contract->getIncrementing());
    }

    /**
     * Test model does not use timestamps.
     */
    public function test_model_has_no_timestamps(): void
    {
        $contract = new ElectricityContract();
        $this->assertFalse($contract->usesTimestamps());
    }

    /**
     * Test JSONB fields are cast to arrays.
     */
    public function test_jsonb_fields_are_cast_to_arrays(): void
    {
        $contract = new ElectricityContract();
        $casts = $contract->getCasts();

        $this->assertEquals('array', $casts['billing_frequency']);
        $this->assertEquals('array', $casts['time_period_definitions']);
        $this->assertEquals('array', $casts['transparency_index']);
    }

    /**
     * Test boolean fields are properly cast.
     */
    public function test_boolean_fields_are_cast(): void
    {
        $contract = new ElectricityContract();
        $casts = $contract->getCasts();

        $this->assertEquals('boolean', $casts['pricing_has_discounts']);
        $this->assertEquals('boolean', $casts['consumption_control']);
        $this->assertEquals('boolean', $casts['pre_billing']);
        $this->assertEquals('boolean', $casts['available_for_existing_users']);
        $this->assertEquals('boolean', $casts['delivery_responsibility_product']);
        $this->assertEquals('boolean', $casts['availability_is_national']);
        $this->assertEquals('boolean', $casts['microproduction_buys']);
    }

    /**
     * Test float fields are properly cast.
     */
    public function test_float_fields_are_cast(): void
    {
        $contract = new ElectricityContract();
        $casts = $contract->getCasts();

        $this->assertEquals('float', $casts['consumption_limitation_min_x_kwh_per_y']);
        $this->assertEquals('float', $casts['consumption_limitation_max_x_kwh_per_y']);
    }

    /**
     * Helper to create a contract with consumption limits without triggering database casts.
     */
    private function makeContractWithLimits(?float $min, ?float $max): ElectricityContract
    {
        $contract = new ElectricityContract();
        // Set attributes directly to avoid triggering database-dependent casts
        $contract->setRawAttributes([
            'consumption_limitation_min_x_kwh_per_y' => $min,
            'consumption_limitation_max_x_kwh_per_y' => $max,
        ]);
        return $contract;
    }

    /**
     * Test isConsumptionInRange returns true when no limits are set.
     */
    public function test_consumption_in_range_with_no_limits(): void
    {
        $contract = $this->makeContractWithLimits(null, null);

        $this->assertTrue($contract->isConsumptionInRange(0));
        $this->assertTrue($contract->isConsumptionInRange(5000));
        $this->assertTrue($contract->isConsumptionInRange(100000));
    }

    /**
     * Test isConsumptionInRange respects minimum limit.
     */
    public function test_consumption_in_range_with_min_limit(): void
    {
        $contract = $this->makeContractWithLimits(2000, null);

        $this->assertFalse($contract->isConsumptionInRange(1000));
        $this->assertFalse($contract->isConsumptionInRange(1999));
        $this->assertTrue($contract->isConsumptionInRange(2000));
        $this->assertTrue($contract->isConsumptionInRange(5000));
        $this->assertTrue($contract->isConsumptionInRange(100000));
    }

    /**
     * Test isConsumptionInRange respects maximum limit.
     */
    public function test_consumption_in_range_with_max_limit(): void
    {
        $contract = $this->makeContractWithLimits(null, 10000);

        $this->assertTrue($contract->isConsumptionInRange(0));
        $this->assertTrue($contract->isConsumptionInRange(5000));
        $this->assertTrue($contract->isConsumptionInRange(10000));
        $this->assertFalse($contract->isConsumptionInRange(10001));
        $this->assertFalse($contract->isConsumptionInRange(50000));
    }

    /**
     * Test isConsumptionInRange respects both min and max limits.
     */
    public function test_consumption_in_range_with_both_limits(): void
    {
        $contract = $this->makeContractWithLimits(2000, 10000);

        $this->assertFalse($contract->isConsumptionInRange(1000));
        $this->assertFalse($contract->isConsumptionInRange(1999));
        $this->assertTrue($contract->isConsumptionInRange(2000));
        $this->assertTrue($contract->isConsumptionInRange(5000));
        $this->assertTrue($contract->isConsumptionInRange(10000));
        $this->assertFalse($contract->isConsumptionInRange(10001));
        $this->assertFalse($contract->isConsumptionInRange(50000));
    }

    /**
     * Test isConsumptionInRange handles edge case with zero limit.
     */
    public function test_consumption_in_range_with_zero_min_limit(): void
    {
        $contract = $this->makeContractWithLimits(0, null);

        // Zero is explicitly set, so it should be treated as a limit
        $this->assertTrue($contract->isConsumptionInRange(0));
        $this->assertTrue($contract->isConsumptionInRange(5000));
    }

    /**
     * Test hasConsumptionLimits returns false when no limits are set.
     */
    public function test_has_consumption_limits_with_no_limits(): void
    {
        $contract = $this->makeContractWithLimits(null, null);

        $this->assertFalse($contract->hasConsumptionLimits());
    }

    /**
     * Test hasConsumptionLimits returns true when min limit is set.
     */
    public function test_has_consumption_limits_with_min_limit(): void
    {
        $contract = $this->makeContractWithLimits(2000, null);

        $this->assertTrue($contract->hasConsumptionLimits());
    }

    /**
     * Test hasConsumptionLimits returns true when max limit is set.
     */
    public function test_has_consumption_limits_with_max_limit(): void
    {
        $contract = $this->makeContractWithLimits(null, 10000);

        $this->assertTrue($contract->hasConsumptionLimits());
    }

    /**
     * Test all expected fields are fillable.
     */
    public function test_expected_fields_are_fillable(): void
    {
        $contract = new ElectricityContract();
        $fillable = $contract->getFillable();

        $expectedFields = [
            'id',
            'company_name',
            'name',
            'name_slug',
            'contract_type',
            'spot_price_selection',
            'fixed_time_range',
            'metering',
            'pricing_model',
            'target_group',
            'short_description',
            'long_description',
            'pricing_name',
            'pricing_has_discounts',
            'consumption_control',
            'consumption_limitation_min_x_kwh_per_y',
            'consumption_limitation_max_x_kwh_per_y',
            'pre_billing',
            'available_for_existing_users',
            'delivery_responsibility_product',
            'order_link',
            'product_link',
            'billing_frequency',
            'time_period_definitions',
            'transparency_index',
            'extra_information_default',
            'extra_information_fi',
            'extra_information_en',
            'extra_information_sv',
            'availability_is_national',
            'microproduction_buys',
            'microproduction_default',
            'microproduction_fi',
            'microproduction_sv',
            'microproduction_en',
        ];

        foreach ($expectedFields as $field) {
            $this->assertContains($field, $fillable, "Field '$field' should be fillable");
        }
    }
}
