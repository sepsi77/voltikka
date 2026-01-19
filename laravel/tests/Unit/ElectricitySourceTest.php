<?php

namespace Tests\Unit;

use App\Models\ElectricitySource;
use PHPUnit\Framework\TestCase;

class ElectricitySourceTest extends TestCase
{
    /**
     * Test model has correct table name.
     */
    public function test_model_has_correct_table_name(): void
    {
        $source = new ElectricitySource();
        $this->assertEquals('electricity_sources', $source->getTable());
    }

    /**
     * Test model has correct primary key settings.
     * contract_id is the primary key as it's a one-to-one relationship.
     */
    public function test_model_has_string_primary_key(): void
    {
        $source = new ElectricitySource();
        $this->assertEquals('contract_id', $source->getKeyName());
        $this->assertEquals('string', $source->getKeyType());
        $this->assertFalse($source->getIncrementing());
    }

    /**
     * Test model does not use timestamps.
     */
    public function test_model_has_no_timestamps(): void
    {
        $source = new ElectricitySource();
        $this->assertFalse($source->usesTimestamps());
    }

    /**
     * Test renewable fields are fillable and cast correctly.
     */
    public function test_renewable_fields_are_fillable(): void
    {
        $source = new ElectricitySource();
        $fillable = $source->getFillable();

        $this->assertContains('renewable_total', $fillable);
        $this->assertContains('renewable_biomass', $fillable);
        $this->assertContains('renewable_solar', $fillable);
        $this->assertContains('renewable_wind', $fillable);
        $this->assertContains('renewable_general', $fillable);
        $this->assertContains('renewable_hydro', $fillable);
    }

    /**
     * Test fossil fields are fillable.
     */
    public function test_fossil_fields_are_fillable(): void
    {
        $source = new ElectricitySource();
        $fillable = $source->getFillable();

        $this->assertContains('fossil_total', $fillable);
        $this->assertContains('fossil_oil', $fillable);
        $this->assertContains('fossil_coal', $fillable);
        $this->assertContains('fossil_natural_gas', $fillable);
        $this->assertContains('fossil_peat', $fillable);
    }

    /**
     * Test nuclear fields are fillable.
     */
    public function test_nuclear_fields_are_fillable(): void
    {
        $source = new ElectricitySource();
        $fillable = $source->getFillable();

        $this->assertContains('nuclear_total', $fillable);
        $this->assertContains('nuclear_general', $fillable);
    }

    /**
     * Test contract_id is fillable.
     */
    public function test_contract_id_is_fillable(): void
    {
        $source = new ElectricitySource();
        $this->assertContains('contract_id', $source->getFillable());
    }

    /**
     * Test float casting is applied to percentage fields.
     */
    public function test_percentage_fields_cast_to_float(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => '45.5',
            'fossil_total' => '30.2',
            'nuclear_total' => '24.3',
        ]);

        // Check that casting returns float types
        $casts = $source->getCasts();
        $this->assertEquals('float', $casts['renewable_total']);
        $this->assertEquals('float', $casts['fossil_total']);
        $this->assertEquals('float', $casts['nuclear_total']);
    }

    /**
     * Test model has contract relationship method.
     */
    public function test_model_has_contract_relationship(): void
    {
        $source = new ElectricitySource();
        $this->assertTrue(method_exists($source, 'contract'));
    }

    /**
     * Test isFullyRenewable scope returns true when renewable_total is 100.
     */
    public function test_is_fully_renewable(): void
    {
        $source = new ElectricitySource(['renewable_total' => 100.0]);
        $this->assertTrue($source->isFullyRenewable());

        $source2 = new ElectricitySource(['renewable_total' => 99.9]);
        $this->assertFalse($source2->isFullyRenewable());
    }

    /**
     * Test isFossilFree returns true when fossil_total is 0 or null.
     */
    public function test_is_fossil_free(): void
    {
        $source = new ElectricitySource(['fossil_total' => 0.0]);
        $this->assertTrue($source->isFossilFree());

        $source2 = new ElectricitySource(['fossil_total' => null]);
        $this->assertTrue($source2->isFossilFree());

        $source3 = new ElectricitySource(['fossil_total' => 0.1]);
        $this->assertFalse($source3->isFossilFree());
    }

    /**
     * Test hasNuclear returns true when nuclear_total > 0.
     */
    public function test_has_nuclear(): void
    {
        $source = new ElectricitySource(['nuclear_total' => 24.0]);
        $this->assertTrue($source->hasNuclear());

        $source2 = new ElectricitySource(['nuclear_total' => 0.0]);
        $this->assertFalse($source2->hasNuclear());

        $source3 = new ElectricitySource(['nuclear_total' => null]);
        $this->assertFalse($source3->hasNuclear());
    }
}
