<?php

namespace Tests\Unit;

use App\Models\ActiveContract;
use PHPUnit\Framework\TestCase;

class ActiveContractTest extends TestCase
{
    /**
     * Test model has correct table name.
     */
    public function test_model_has_correct_table_name(): void
    {
        $activeContract = new ActiveContract();
        $this->assertEquals('active_contracts', $activeContract->getTable());
    }

    /**
     * Test model has correct primary key settings.
     * The id field is both the primary key and a foreign key to electricity_contracts.
     */
    public function test_model_has_string_primary_key(): void
    {
        $activeContract = new ActiveContract();
        $this->assertEquals('id', $activeContract->getKeyName());
        $this->assertEquals('string', $activeContract->getKeyType());
        $this->assertFalse($activeContract->getIncrementing());
    }

    /**
     * Test model does not use timestamps.
     */
    public function test_model_has_no_timestamps(): void
    {
        $activeContract = new ActiveContract();
        $this->assertFalse($activeContract->usesTimestamps());
    }

    /**
     * Test id is fillable.
     */
    public function test_id_is_fillable(): void
    {
        $activeContract = new ActiveContract();
        $this->assertContains('id', $activeContract->getFillable());
    }

    /**
     * Test model has electricityContract relationship method.
     */
    public function test_model_has_electricity_contract_relationship(): void
    {
        $activeContract = new ActiveContract();
        $this->assertTrue(method_exists($activeContract, 'electricityContract'));
    }
}
