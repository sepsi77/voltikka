<?php

namespace Tests\Unit;

use App\Models\Postcode;
use PHPUnit\Framework\TestCase;

class PostcodeTest extends TestCase
{
    /**
     * Test model configuration - correct table name.
     */
    public function test_model_has_correct_table_name(): void
    {
        $postcode = new Postcode();
        $this->assertEquals('postcodes', $postcode->getTable());
    }

    /**
     * Test model has correct primary key settings.
     * The postcode field is the string primary key.
     */
    public function test_model_has_string_primary_key(): void
    {
        $postcode = new Postcode();
        $this->assertEquals('postcode', $postcode->getKeyName());
        $this->assertEquals('string', $postcode->getKeyType());
        $this->assertFalse($postcode->getIncrementing());
    }

    /**
     * Test model does not use timestamps.
     * The legacy table doesn't have created_at/updated_at columns.
     */
    public function test_model_has_no_timestamps(): void
    {
        $postcode = new Postcode();
        $this->assertFalse($postcode->usesTimestamps());
    }

    /**
     * Test all expected Finnish name fields are fillable.
     */
    public function test_finnish_name_fields_are_fillable(): void
    {
        $postcode = new Postcode();
        $fillable = $postcode->getFillable();

        $finnishFields = [
            'postcode',
            'postcode_fi_name',
            'postcode_fi_name_slug',
            'postcode_abbr_fi',
        ];

        foreach ($finnishFields as $field) {
            $this->assertContains($field, $fillable, "Field '$field' should be fillable");
        }
    }

    /**
     * Test all expected Swedish name fields are fillable.
     */
    public function test_swedish_name_fields_are_fillable(): void
    {
        $postcode = new Postcode();
        $fillable = $postcode->getFillable();

        $swedishFields = [
            'postcode_sv_name',
            'postcode_sv_name_slug',
            'postcode_abbr_sv',
        ];

        foreach ($swedishFields as $field) {
            $this->assertContains($field, $fillable, "Field '$field' should be fillable");
        }
    }

    /**
     * Test all expected area fields are fillable.
     */
    public function test_area_fields_are_fillable(): void
    {
        $postcode = new Postcode();
        $fillable = $postcode->getFillable();

        $areaFields = [
            'type_code',
            'ad_area_code',
            'ad_area_fi',
            'ad_area_fi_slug',
            'ad_area_sv',
            'ad_area_sv_slug',
        ];

        foreach ($areaFields as $field) {
            $this->assertContains($field, $fillable, "Field '$field' should be fillable");
        }
    }

    /**
     * Test all expected municipality fields are fillable.
     */
    public function test_municipality_fields_are_fillable(): void
    {
        $postcode = new Postcode();
        $fillable = $postcode->getFillable();

        $municipalityFields = [
            'municipal_code',
            'municipal_name_fi',
            'municipal_name_fi_slug',
            'municipal_name_sv',
            'municipal_name_sv_slug',
            'municipal_language_ratio_code',
        ];

        foreach ($municipalityFields as $field) {
            $this->assertContains($field, $fillable, "Field '$field' should be fillable");
        }
    }

    /**
     * Test that the contracts relationship method exists.
     * The actual relationship test would require database access, but we can test method existence.
     */
    public function test_contracts_relationship_method_exists(): void
    {
        $postcode = new Postcode();
        $this->assertTrue(method_exists($postcode, 'contracts'));
    }

    /**
     * Test slug generation for Finnish names.
     * Matches the Python name_to_slug function behavior.
     */
    public function test_slug_generation_handles_finnish_characters(): void
    {
        // Test basic Finnish city names
        $this->assertEquals('helsinki', Postcode::generateSlug('Helsinki'));
        $this->assertEquals('jyvaskyla', Postcode::generateSlug('Jyväskylä'));
        $this->assertEquals('oulu', Postcode::generateSlug('Oulu'));

        // Test with ä, ö, å
        $this->assertEquals('vaasa', Postcode::generateSlug('Vaasa'));
        $this->assertEquals('hameenlinna', Postcode::generateSlug('Hämeenlinna'));
        $this->assertEquals('naantali', Postcode::generateSlug('Naantali'));
    }

    /**
     * Test slug generation with compound names.
     */
    public function test_slug_generation_handles_compound_names(): void
    {
        $this->assertEquals('pohjois-haaga', Postcode::generateSlug('Pohjois-Haaga'));
        $this->assertEquals('etela-helsinki', Postcode::generateSlug('Etelä-Helsinki'));
        $this->assertEquals('ita-pasila', Postcode::generateSlug('Itä-Pasila'));
    }

    /**
     * Test slug generation matches Python implementation.
     */
    public function test_slug_matches_python_implementation(): void
    {
        // These test cases should match the Python name_to_slug function behavior
        $this->assertEquals('espoo', Postcode::generateSlug('Espoo'));
        $this->assertEquals('vantaa', Postcode::generateSlug('Vantaa'));
        $this->assertEquals('turku', Postcode::generateSlug('Turku'));
        $this->assertEquals('tampere', Postcode::generateSlug('Tampere'));
    }

    /**
     * Test that query scope for searching by municipality exists.
     */
    public function test_municipality_scope_method_exists(): void
    {
        $postcode = new Postcode();
        $this->assertTrue(method_exists($postcode, 'scopeInMunicipality'));
    }

    /**
     * Test that query scope for searching by Finnish name exists.
     */
    public function test_search_scope_method_exists(): void
    {
        $postcode = new Postcode();
        $this->assertTrue(method_exists($postcode, 'scopeSearch'));
    }
}
