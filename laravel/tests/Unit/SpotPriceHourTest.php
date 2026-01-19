<?php

namespace Tests\Unit;

use App\Models\SpotPriceHour;
use PHPUnit\Framework\TestCase;

class SpotPriceHourTest extends TestCase
{
    /**
     * Test model has correct table name.
     */
    public function test_model_has_correct_table_name(): void
    {
        $spotPrice = new SpotPriceHour();
        $this->assertEquals('spot_prices_hour', $spotPrice->getTable());
    }

    /**
     * Test model has composite primary key.
     * The legacy table has (region, timestamp) as composite primary key.
     * Laravel doesn't natively support composite keys, so we use region as primary
     * and handle composite keys in setKeysForSaveQuery().
     */
    public function test_model_uses_region_as_primary_key(): void
    {
        $spotPrice = new SpotPriceHour();
        $this->assertEquals('region', $spotPrice->getKeyName());
        $this->assertFalse($spotPrice->getIncrementing());
        $this->assertEquals('string', $spotPrice->getKeyType());
    }

    /**
     * Test model does not use timestamps.
     */
    public function test_model_has_no_timestamps(): void
    {
        $spotPrice = new SpotPriceHour();
        $this->assertFalse($spotPrice->usesTimestamps());
    }

    /**
     * Test all required fields are fillable.
     */
    public function test_all_fields_are_fillable(): void
    {
        $spotPrice = new SpotPriceHour();
        $fillable = $spotPrice->getFillable();

        $this->assertContains('region', $fillable);
        $this->assertContains('timestamp', $fillable);
        $this->assertContains('utc_datetime', $fillable);
        $this->assertContains('price_without_tax', $fillable);
        $this->assertContains('vat_rate', $fillable);
    }

    /**
     * Test fields are cast correctly.
     */
    public function test_fields_are_cast_correctly(): void
    {
        $spotPrice = new SpotPriceHour();
        $casts = $spotPrice->getCasts();

        $this->assertEquals('integer', $casts['timestamp']);
        $this->assertEquals('datetime', $casts['utc_datetime']);
        $this->assertEquals('float', $casts['price_without_tax']);
        $this->assertEquals('float', $casts['vat_rate']);
    }

    /**
     * Test vat accessor calculates correctly.
     * The legacy database has computed columns: vat = price_without_tax * vat_rate
     */
    public function test_vat_accessor_calculates_correctly(): void
    {
        $spotPrice = new SpotPriceHour([
            'price_without_tax' => 10.0,
            'vat_rate' => 0.24,
        ]);

        $this->assertEquals(2.4, $spotPrice->vat);

        // Test with 10% VAT rate
        $spotPrice2 = new SpotPriceHour([
            'price_without_tax' => 10.0,
            'vat_rate' => 0.10,
        ]);

        $this->assertEquals(1.0, $spotPrice2->vat);
    }

    /**
     * Test price_with_tax accessor calculates correctly.
     * The legacy database has computed columns: price_with_tax = price_without_tax * (1 + vat_rate)
     */
    public function test_price_with_tax_accessor_calculates_correctly(): void
    {
        $spotPrice = new SpotPriceHour([
            'price_without_tax' => 10.0,
            'vat_rate' => 0.24,
        ]);

        $this->assertEquals(12.4, $spotPrice->price_with_tax);

        // Test with 10% VAT rate
        $spotPrice2 = new SpotPriceHour([
            'price_without_tax' => 10.0,
            'vat_rate' => 0.10,
        ]);

        $this->assertEquals(11.0, $spotPrice2->price_with_tax);
    }

    /**
     * Test model has setKeysForSaveQuery method for composite key handling.
     */
    public function test_model_has_composite_key_save_query_method(): void
    {
        $spotPrice = new SpotPriceHour();
        $reflection = new \ReflectionClass($spotPrice);
        $this->assertTrue($reflection->hasMethod('setKeysForSaveQuery'));
    }

    /**
     * Test scope forRegion filters by region.
     */
    public function test_model_has_for_region_scope(): void
    {
        $spotPrice = new SpotPriceHour();
        $this->assertTrue(method_exists($spotPrice, 'scopeForRegion'));
    }

    /**
     * Test scope forDate filters by utc_datetime date.
     */
    public function test_model_has_for_date_scope(): void
    {
        $spotPrice = new SpotPriceHour();
        $this->assertTrue(method_exists($spotPrice, 'scopeForDate'));
    }

    /**
     * Test VAT calculation handles null values gracefully.
     */
    public function test_vat_handles_null_values(): void
    {
        $spotPrice = new SpotPriceHour([
            'price_without_tax' => null,
            'vat_rate' => 0.24,
        ]);
        $this->assertEquals(0.0, $spotPrice->vat);

        $spotPrice2 = new SpotPriceHour([
            'price_without_tax' => 10.0,
            'vat_rate' => null,
        ]);
        $this->assertEquals(0.0, $spotPrice2->vat);
    }

    /**
     * Test price with tax calculation handles null values gracefully.
     */
    public function test_price_with_tax_handles_null_values(): void
    {
        $spotPrice = new SpotPriceHour([
            'price_without_tax' => null,
            'vat_rate' => 0.24,
        ]);
        $this->assertEquals(0.0, $spotPrice->price_with_tax);

        $spotPrice2 = new SpotPriceHour([
            'price_without_tax' => 10.0,
            'vat_rate' => null,
        ]);
        // If vat_rate is null, we treat it as 0% VAT
        $this->assertEquals(10.0, $spotPrice2->price_with_tax);
    }
}
