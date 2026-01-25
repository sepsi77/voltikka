<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractsListPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
            'logo_url' => 'https://storage.example.com/logos/test-energia.png',
        ]);
    }

    /**
     * Helper to create multiple contracts for pagination testing.
     */
    private function createContracts(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $contract = ElectricityContract::create([
                'id' => "contract-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Test Sähkö {$i}",
                'name_slug' => "test-sahko-{$i}",
                'contract_type' => 'FixedTerm',
                'metering' => 'General',
                'availability_is_national' => true,
            ]);

            PriceComponent::create([
                'id' => "pc-general-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 5.0 + ($i * 0.1), // Slightly different prices for sorting
                'payment_unit' => 'c/kWh',
            ]);

            PriceComponent::create([
                'id' => "pc-monthly-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'Monthly',
                'price_date' => now()->format('Y-m-d'),
                'price' => 2.95,
                'payment_unit' => 'EUR/month',
            ]);

            // Mark contract as active so it appears in listings
            ActiveContract::create(['id' => $contract->id]);
        }
    }

    // ========================================
    // Basic Pagination Tests
    // ========================================

    /**
     * Test that the page property is bound to URL query string.
     */
    public function test_page_parameter_is_url_bound(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->assertSet('page', 1);

        // Verify that page property has #[Url] attribute
        $reflection = new \ReflectionClass($component->instance());
        $pageProperty = $reflection->getProperty('page');
        $attributes = $pageProperty->getAttributes();

        $hasUrlAttribute = false;
        foreach ($attributes as $attribute) {
            if (str_contains($attribute->getName(), 'Url')) {
                $hasUrlAttribute = true;
                break;
            }
        }
        $this->assertTrue($hasUrlAttribute, 'The page property should have #[Url] attribute');
    }

    /**
     * Test that pagination returns correct number of items per page.
     */
    public function test_pagination_returns_correct_items_per_page(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list');
        $contracts = $component->viewData('contracts');

        // Should return 25 items per page (default perPage)
        $this->assertEquals(25, $contracts->count());
    }

    /**
     * Test that pagination shows correct page of results.
     */
    public function test_pagination_shows_correct_page(): void
    {
        $this->createContracts(60);

        // First page
        $component = Livewire::test('contracts-list')
            ->set('page', 1);

        $contracts = $component->viewData('contracts');
        $this->assertEquals(25, $contracts->count());

        // Second page
        $component2 = Livewire::test('contracts-list')
            ->set('page', 2);

        $contracts2 = $component2->viewData('contracts');
        $this->assertEquals(25, $contracts2->count());

        // Third page (partial)
        $component3 = Livewire::test('contracts-list')
            ->set('page', 3);

        $contracts3 = $component3->viewData('contracts');
        $this->assertEquals(10, $contracts3->count()); // 60 - 50 = 10 remaining
    }

    /**
     * Test that pagination total count is accurate.
     */
    public function test_pagination_total_count_is_accurate(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list');
        $contracts = $component->viewData('contracts');

        // The paginator should know the total count
        $this->assertEquals(50, $contracts->total());
    }

    /**
     * Test that pagination links are rendered in the view.
     */
    public function test_pagination_links_are_rendered(): void
    {
        $this->createContracts(50);

        Livewire::test('contracts-list')
            ->assertSee('Sivu 1')
            ->assertSee('Seuraava'); // "Next" in Finnish
    }

    // ========================================
    // SEO Title Tests
    // ========================================

    /**
     * Test that page title includes page number suffix when on page > 1.
     */
    public function test_page_title_includes_page_suffix_on_page_2(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('page', 2);

        $pageTitle = $component->get('pageTitle');
        $this->assertStringContainsString('Sivu 2', $pageTitle);
    }

    /**
     * Test that page title does NOT include suffix on page 1.
     */
    public function test_page_title_does_not_include_suffix_on_page_1(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('page', 1);

        $pageTitle = $component->get('pageTitle');
        $this->assertStringNotContainsString('Sivu', $pageTitle);
    }

    /**
     * Test that page title combines filters with page suffix.
     */
    public function test_page_title_combines_filters_with_page_suffix(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('pricingModelFilter', 'Spot')
            ->set('page', 3);

        $pageTitle = $component->get('pageTitle');
        $this->assertStringContainsString('Pörssisähkösopimukset', $pageTitle);
        $this->assertStringContainsString('Sivu 3', $pageTitle);
    }

    // ========================================
    // SEO rel="canonical" and rel="prev/next" Tests
    // ========================================

    /**
     * Test that canonical URL is correct on page 1.
     */
    public function test_canonical_url_on_page_1(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('page', 1);

        $canonical = $component->get('canonicalUrl');
        // Page 1 canonical should NOT include page parameter
        $this->assertStringNotContainsString('page=', $canonical);
    }

    /**
     * Test that canonical URL includes page on page > 1.
     */
    public function test_canonical_url_includes_page_on_page_2(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('page', 2);

        $canonical = $component->get('canonicalUrl');
        $this->assertStringContainsString('page=2', $canonical);
    }

    /**
     * Test that rel="prev" URL is provided on page > 1.
     */
    public function test_prev_url_on_page_2(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('page', 2);

        $prevUrl = $component->get('prevUrl');
        $this->assertNotNull($prevUrl);
        // Prev from page 2 should go to page 1 (no page param)
        $this->assertStringNotContainsString('page=', $prevUrl);
    }

    /**
     * Test that rel="prev" is null on page 1.
     */
    public function test_prev_url_is_null_on_page_1(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('page', 1);

        $prevUrl = $component->get('prevUrl');
        $this->assertNull($prevUrl);
    }

    /**
     * Test that rel="next" URL is provided when there are more pages.
     */
    public function test_next_url_when_more_pages_exist(): void
    {
        $this->createContracts(50);

        $component = Livewire::test('contracts-list')
            ->set('page', 1);

        $nextUrl = $component->get('nextUrl');
        $this->assertNotNull($nextUrl);
        $this->assertStringContainsString('page=2', $nextUrl);
    }

    /**
     * Test that rel="next" is null on last page.
     */
    public function test_next_url_is_null_on_last_page(): void
    {
        $this->createContracts(25); // Only 1 page

        $component = Livewire::test('contracts-list')
            ->set('page', 1);

        $nextUrl = $component->get('nextUrl');
        $this->assertNull($nextUrl);
    }

    /**
     * Test that SEO link tags are rendered in the layout.
     */
    public function test_seo_link_tags_are_rendered(): void
    {
        $this->createContracts(75); // 3 pages at 25 per page

        $response = $this->get('/?page=2');

        $response->assertStatus(200);
        $response->assertSee('rel="canonical"', false);
        $response->assertSee('rel="prev"', false);
        $response->assertSee('rel="next"', false);
    }

    // ========================================
    // Filter Integration with Pagination
    // ========================================

    /**
     * Test that applying a filter resets page to 1.
     */
    public function test_applying_filter_resets_page_to_1(): void
    {
        // Create contracts with different pricing models
        for ($i = 1; $i <= 30; $i++) {
            $contract = ElectricityContract::create([
                'id' => "spot-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Spot Sähkö {$i}",
                'pricing_model' => 'Spot',
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'availability_is_national' => true,
            ]);

            PriceComponent::create([
                'id' => "pc-spot-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 0.5,
                'payment_unit' => 'c/kWh',
            ]);
        }

        for ($i = 1; $i <= 30; $i++) {
            $contract = ElectricityContract::create([
                'id' => "fixed-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Fixed Sähkö {$i}",
                'pricing_model' => 'FixedPrice',
                'contract_type' => 'FixedTerm',
                'metering' => 'General',
                'availability_is_national' => true,
            ]);

            PriceComponent::create([
                'id' => "pc-fixed-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 5.0,
                'payment_unit' => 'c/kWh',
            ]);
        }

        $component = Livewire::test('contracts-list')
            ->set('page', 2)
            ->assertSet('page', 2)
            ->call('setPricingModelFilter', 'Spot')
            ->assertSet('page', 1); // Should reset to 1
    }

    /**
     * Test that changing consumption resets page to 1.
     */
    public function test_changing_consumption_resets_page(): void
    {
        $this->createContracts(50);

        // Test selectPreset method resets page
        $component = Livewire::test('contracts-list')
            ->set('page', 2)
            ->call('selectPreset', 'large_house_electric')
            ->assertSet('page', 1); // Should reset to 1

        // Test setConsumption method resets page
        $component2 = Livewire::test('contracts-list')
            ->set('page', 2)
            ->call('setConsumption', 10000)
            ->assertSet('page', 1); // Should reset to 1
    }

    /**
     * Test that pagination works correctly with filters applied.
     */
    public function test_pagination_works_with_filters(): void
    {
        // Create 40 spot contracts
        for ($i = 1; $i <= 40; $i++) {
            $contract = ElectricityContract::create([
                'id' => "spot-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Spot Sähkö {$i}",
                'pricing_model' => 'Spot',
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'availability_is_national' => true,
            ]);

            PriceComponent::create([
                'id' => "pc-spot-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 0.5 + ($i * 0.01),
                'payment_unit' => 'c/kWh',
            ]);
        }

        // Create 10 fixed contracts (should be filtered out)
        for ($i = 1; $i <= 10; $i++) {
            $contract = ElectricityContract::create([
                'id' => "fixed-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Fixed Sähkö {$i}",
                'pricing_model' => 'FixedPrice',
                'contract_type' => 'FixedTerm',
                'metering' => 'General',
                'availability_is_national' => true,
            ]);

            PriceComponent::create([
                'id' => "pc-fixed-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 5.0,
                'payment_unit' => 'c/kWh',
            ]);
        }

        $component = Livewire::test('contracts-list')
            ->set('pricingModelFilter', 'Spot');

        $contracts = $component->viewData('contracts');

        // Total should be 40 spot contracts
        $this->assertEquals(40, $contracts->total());
        // Per page should be 25
        $this->assertEquals(25, $contracts->perPage());
        // Should have 2 pages
        $this->assertEquals(2, $contracts->lastPage());
    }

    // ========================================
    // URL Query String Tests
    // ========================================

    /**
     * Test that page parameter is in URL on page 2.
     */
    public function test_page_parameter_appears_in_url(): void
    {
        $this->createContracts(50);

        $response = $this->get('/?page=2');
        $response->assertStatus(200);
    }

    /**
     * Test that navigating to page via URL works.
     */
    public function test_navigating_to_page_via_url_works(): void
    {
        $this->createContracts(60);

        $response = $this->get('/?page=2');
        $response->assertStatus(200);

        // The component should be on page 2
        $response->assertSeeLivewire('contracts-list');
    }

    /**
     * Test that out-of-range page numbers are handled gracefully.
     */
    public function test_out_of_range_page_number_handled_gracefully(): void
    {
        $this->createContracts(25);

        // Page 999 doesn't exist - should still return 200 (empty page)
        $response = $this->get('/?page=999');
        $response->assertStatus(200);

        // Negative page - Livewire treats this as page 1
        $response2 = $this->get('/?page=-1');
        $response2->assertStatus(200);
    }

    // ========================================
    // Performance Tests
    // ========================================

    /**
     * Test that pagination reduces the number of contracts loaded in DOM.
     */
    public function test_pagination_reduces_dom_elements(): void
    {
        $this->createContracts(100);

        $component = Livewire::test('contracts-list');
        $contracts = $component->viewData('contracts');

        // Should only have 25 contracts in memory for this page
        $this->assertEquals(25, $contracts->count());
        // But total is still 100
        $this->assertEquals(100, $contracts->total());
    }

    // ========================================
    // Pagination UI Tests
    // ========================================

    /**
     * Test that "Previous" link is disabled on page 1.
     */
    public function test_previous_link_disabled_on_page_1(): void
    {
        $this->createContracts(50);

        // On page 1, the prevUrl should be null
        $component = Livewire::test('contracts-list')
            ->set('page', 1);

        $prevUrl = $component->get('prevUrl');
        $this->assertNull($prevUrl);
    }

    /**
     * Test that "Next" link is disabled on last page.
     */
    public function test_next_link_disabled_on_last_page(): void
    {
        $this->createContracts(30); // Creates 2 pages (25 + 5)

        // On last page, the nextUrl should be null
        $component = Livewire::test('contracts-list')
            ->set('page', 2);

        $nextUrl = $component->get('nextUrl');
        $this->assertNull($nextUrl);
    }

    /**
     * Test that current page is highlighted in pagination.
     */
    public function test_current_page_is_highlighted(): void
    {
        $this->createContracts(75); // Creates 3 pages

        Livewire::test('contracts-list')
            ->set('page', 2)
            ->assertSeeHtml('aria-current="page"'); // Accessibility attribute for current page
    }
}
