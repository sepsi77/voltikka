<?php

namespace Tests\Unit;

use App\Models\SpotFutures;
use PHPUnit\Framework\TestCase;

class SpotFuturesTest extends TestCase
{
    /**
     * Test model has correct table name.
     */
    public function test_model_has_correct_table_name(): void
    {
        $futures = new SpotFutures();
        $this->assertEquals('spot_futures', $futures->getTable());
    }

    /**
     * Test model has composite primary key.
     * The legacy table has (date, price) as composite primary key.
     * Laravel doesn't natively support composite keys, so we use date as primary
     * and handle composite keys in setKeysForSaveQuery().
     */
    public function test_model_uses_date_as_primary_key(): void
    {
        $futures = new SpotFutures();
        $this->assertEquals('date', $futures->getKeyName());
        $this->assertFalse($futures->getIncrementing());
    }

    /**
     * Test model does not use timestamps.
     */
    public function test_model_has_no_timestamps(): void
    {
        $futures = new SpotFutures();
        $this->assertFalse($futures->usesTimestamps());
    }

    /**
     * Test date field is fillable.
     */
    public function test_date_is_fillable(): void
    {
        $futures = new SpotFutures();
        $this->assertContains('date', $futures->getFillable());
    }

    /**
     * Test price field is fillable.
     */
    public function test_price_is_fillable(): void
    {
        $futures = new SpotFutures();
        $this->assertContains('price', $futures->getFillable());
    }

    /**
     * Test date field is cast correctly.
     */
    public function test_date_field_is_cast_to_date(): void
    {
        $futures = new SpotFutures();
        $casts = $futures->getCasts();
        $this->assertEquals('date', $casts['date']);
    }

    /**
     * Test price field is cast correctly.
     */
    public function test_price_field_is_cast_to_float(): void
    {
        $futures = new SpotFutures();
        $casts = $futures->getCasts();
        $this->assertEquals('float', $casts['price']);
    }

    /**
     * Test model has setKeysForSaveQuery method for composite key handling.
     */
    public function test_model_has_composite_key_save_query_method(): void
    {
        $futures = new SpotFutures();
        // This test just verifies the method exists; the method is protected
        // so we use reflection to check it exists
        $reflection = new \ReflectionClass($futures);
        $this->assertTrue($reflection->hasMethod('setKeysForSaveQuery'));
    }
}
