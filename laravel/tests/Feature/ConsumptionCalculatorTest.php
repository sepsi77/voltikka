<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConsumptionCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculator_page_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
    }

    public function test_calculator_page_renders_livewire_component(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertSeeLivewire('consumption-calculator');
    }

    public function test_calculator_shows_building_type_options(): void
    {
        Livewire::test('consumption-calculator')
            ->assertSee('Asuntotyyppi')
            ->assertSee('Asuinpinta-ala');
    }

    public function test_selecting_building_type_updates_state(): void
    {
        Livewire::test('consumption-calculator')
            ->call('selectBuildingType', 'detached_house')
            ->assertSet('buildingType', 'detached_house');
    }

    public function test_toggling_heating_shows_heating_options(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->assertSet('includeHeating', false)
            ->call('toggleIncludeHeating')
            ->assertSet('includeHeating', true);
    }

    public function test_changing_living_area_recalculates(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 100)
            ->set('numPeople', 2);

        $result = $component->get('calculationResult');

        // Basic living: 2 * 400 + 100 * 30 = 3800
        $this->assertEquals(3800, $result['basic_living']);
    }

    public function test_sauna_usage_adds_to_total(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->set('saunaUsagePerWeek', 2);

        $result = $component->get('calculationResult');

        // Sauna: 2 * 7.5 * 52 = 780
        $this->assertEquals(780, $result['sauna']);
    }

    public function test_electric_vehicle_adds_to_total(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->set('electricVehicleKmsPerMonth', 1000);

        $result = $component->get('calculationResult');

        // EV: 1000 * 0.199 * 12 = 2388
        // Note: Python code has a bug where EV is added twice
        $this->assertEquals(2388, $result['electricity_vehicle']);
    }

    public function test_cooling_adds_fixed_amount(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->call('toggleCooling');

        $result = $component->get('calculationResult');

        // Cooling: fixed 240 kWh
        $this->assertEquals(240, $result['cooling']);
    }

    public function test_bathroom_heating_adds_based_on_area(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->set('bathroomHeatingArea', 5);

        $result = $component->get('calculationResult');

        // Bathroom: 5 * 200 = 1000
        $this->assertEquals(1000, $result['bathroom_underfloor_heating']);
    }

    public function test_heating_calculation_with_electric_heat(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 100)
            ->set('numPeople', 4)
            ->set('buildingType', 'detached_house')
            ->call('toggleIncludeHeating')
            ->set('heatingMethod', 'electricity')
            ->set('buildingRegion', 'central')
            ->set('buildingEnergyEfficiency', '2000');

        $result = $component->get('calculationResult');

        // Should have heating and water included
        $this->assertArrayHasKey('room_heating', $result);
        $this->assertArrayHasKey('water', $result);
        $this->assertGreaterThan(5000, $result['total']);
    }

    public function test_heating_calculation_with_heat_pump(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 150)
            ->set('numPeople', 4)
            ->set('buildingType', 'detached_house')
            ->call('toggleIncludeHeating')
            ->set('heatingMethod', 'ground_heat_pump')
            ->set('buildingRegion', 'south')
            ->set('buildingEnergyEfficiency', '2010');

        $result = $component->get('calculationResult');

        // Heat pump reduces electricity need
        $this->assertArrayHasKey('room_heating', $result);
        // With ground heat pump (COP 2.9), heating should be lower than with direct electric
    }

    public function test_compare_contracts_redirects_with_consumption(): void
    {
        Livewire::test('consumption-calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->call('compareContracts')
            ->assertRedirect('/sahkosopimus?consumption='.(2 * 400 + 80 * 30));
    }

    public function test_page_has_correct_title(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        // The title targets the price-intent queries and carries the current year, which
        // must come from the clock rather than a literal that silently goes stale.
        $response->assertSee(
            '<title>Sähkön hinta laskuri '.now()->year.' – laske kulutus ja vuosihinta</title>',
            false,
        );
        $response->assertSee('Sähkönkulutuslaskuri');
    }

    public function test_consumption_level_pages_are_linked_from_the_calculator(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);

        foreach (['2000', '5000', '10000', '18000', '20000'] as $level) {
            $response->assertSee('/sahkosopimus/kulutus/'.$level.'-kwh', false);
        }
    }

    public function test_faq_answers_the_fixed_kwh_amount_questions(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertSee('Paljonko 20 000 kWh sähköä maksaa vuodessa?', false);
        $response->assertSee('Paljonko 10 000 kWh sähköä maksaa vuodessa?', false);
        $response->assertSee('Mikä kodin laite kuluttaa eniten sähköä?', false);
    }

    public function test_minimum_values_are_enforced(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 5)  // Below minimum of 10
            ->set('numPeople', 0);  // Below minimum of 1

        // The calculate method should enforce and display minimums
        $result = $component->get('calculationResult');
        // Basic living with minimums: 1 * 400 + 20 * 30 = 1000
        $this->assertEquals(1000, $result['basic_living']);
        $component->assertSet('livingArea', 20)
            ->assertSet('numPeople', 1);
    }

    public function test_blank_numeric_values_fall_back_to_displayed_minimums(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', '')
            ->set('numPeople', null)
            ->set('bathroomHeatingArea', '')
            ->set('saunaUsagePerWeek', null)
            ->set('electricVehicleKmsPerMonth', '');

        $result = $component->get('calculationResult');

        // Minimums: 1 * 400 + 20 * 30 = 1000
        $this->assertEquals(1000, $result['basic_living']);
        $this->assertSame(1000, $result['total']);
        $component->assertSet('livingArea', 20)
            ->assertSet('numPeople', 1)
            ->assertSet('bathroomHeatingArea', 0)
            ->assertSet('saunaUsagePerWeek', 0)
            ->assertSet('electricVehicleKmsPerMonth', 0);
    }

    public function test_negative_optional_numeric_values_are_displayed_as_zero(): void
    {
        Livewire::test('consumption-calculator')
            ->set('bathroomHeatingArea', -5)
            ->set('saunaUsagePerWeek', -2)
            ->set('electricVehicleKmsPerMonth', -100)
            ->assertSet('bathroomHeatingArea', 0)
            ->assertSet('saunaUsagePerWeek', 0)
            ->assertSet('electricVehicleKmsPerMonth', 0);
    }

    public function test_blank_or_invalid_select_values_fall_back_to_safe_defaults(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('toggleIncludeHeating')
            ->set('buildingType', '')
            ->set('heatingMethod', '')
            ->set('buildingRegion', 'not-a-region')
            ->set('buildingEnergyEfficiency', null)
            ->set('supplementaryHeating', 'not-a-method');

        $result = $component->get('calculationResult');

        $this->assertArrayHasKey('basic_living', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThan(0, $result['total']);
    }

    public function test_results_section_displays_breakdown(): void
    {
        Livewire::test('consumption-calculator')
            ->set('livingArea', 100)
            ->set('numPeople', 2)
            ->set('saunaUsagePerWeek', 2)
            ->assertSee('Perussähkö')
            ->assertSee('Sauna');
    }

    public function test_calculator_navigation_is_in_header(): void
    {
        $response = $this->get('/');

        $response->assertSee('Laskuri');
        $response->assertSee('/laskuri');
    }

    public function test_page_has_seo_meta_description_and_canonical(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Paljonko sähkö maksaa vuodessa?', false);
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('/sahkosopimus/laskuri', false);
    }

    public function test_page_emits_jsonld_with_webapplication_and_faq(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('"WebApplication"', false);
        $response->assertSee('"FAQPage"', false);
        $response->assertSee('"BreadcrumbList"', false);
    }

    public function test_page_renders_price_estimates_when_statistics_exist(): void
    {
        foreach ([
            ['metric_key' => 'energy_price', 'consumption_kwh' => null, 'p20_value' => 7.0, 'avg_value' => 8.0, 'median_value' => 8.5, 'p80_value' => 10.0],
            ['metric_key' => 'monthly_fee', 'consumption_kwh' => null, 'p20_value' => 2.0, 'avg_value' => 3.0, 'median_value' => 4.0, 'p80_value' => 5.0],
            ['metric_key' => 'annual_cost', 'consumption_kwh' => 5000, 'p20_value' => 800.0, 'avg_value' => 910.0, 'median_value' => 910.0, 'p80_value' => 1000.0],
        ] as $row) {
            ContractPriceDailyStatistic::create(array_merge([
                'stat_date' => '2026-05-30',
                'segment_key' => 'fixed_term_12',
                'min_value' => 1.0,
                'max_value' => 20.0,
                'contract_count' => 12,
            ], $row));
        }

        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertSee('Sähkön hinta laskuri');
        $response->assertSee('Määräaikainen 12 kk');
        // Unit-rate arithmetic would give 320 EUR. The public value must use the
        // stored annual-cost metric instead.
        $response->assertSee('910 €/v');
        $response->assertSee('76 €/kk');
    }

    public function test_kwh_amount_faq_quotes_current_statistics_and_excludes_transfer(): void
    {
        foreach ([
            ['metric_key' => 'energy_price', 'consumption_kwh' => null, 'p20_value' => 7.0, 'avg_value' => 8.0, 'median_value' => 8.5, 'p80_value' => 10.0],
            ['metric_key' => 'monthly_fee', 'consumption_kwh' => null, 'p20_value' => 2.0, 'avg_value' => 3.0, 'median_value' => 4.0, 'p80_value' => 5.0],
            ['metric_key' => 'annual_cost', 'consumption_kwh' => 18000, 'p20_value' => 1750.0, 'avg_value' => 1750.0, 'median_value' => 1750.0, 'p80_value' => 1750.0],
        ] as $row) {
            ContractPriceDailyStatistic::create(array_merge([
                'stat_date' => '2026-05-30',
                'segment_key' => 'fixed_term_12',
                'min_value' => 1.0,
                'max_value' => 20.0,
                'contract_count' => 12,
            ], $row));
        }

        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        // The nearest stored canonical annual-cost metric is 1,750 EUR. No unit-rate
        // arithmetic is used when 20,000 kWh is above the stored range.
        // Only one segment is seeded, so both ends of the range collapse to one figure.
        $response->assertSee('20 000 kWh sähköä maksaa tällä hetkellä noin 1 750 € vuodessa.', false);
        // The competing PAA answer for this query is transfer-inclusive, so our narrower
        // basis must stay stated or the figure reads as simply wrong.
        $response->assertSee('eikä siihen kuulu sähkön siirtoa', false);
    }

    public function test_current_estimates_use_only_the_basis_expected_by_the_canonical_flag(): void
    {
        $this->annualStat('2026-07-27', 'fixed_term_12', 910.0, 'canonical_calculation');
        $this->annualStat('2026-07-28', 'fixed_term_12', 111.0, 'observed_seller_data');
        $this->annualStat('2026-07-27', 'spot', 222.0, 'observed_seller_data');

        config()->set('canonical_pricing.enabled', true);
        $canonical = Livewire::test('consumption-calculator')->get('contractTypePriceEstimates');

        $this->assertSame('2026-07-27', $canonical['date']);
        $this->assertSame(910.0, collect($canonical['rows'])->firstWhere('key', 'fixed_term_12')['costs']['median']['annual']);
        $this->assertNull(collect($canonical['rows'])->firstWhere('key', 'spot'));

        config()->set('canonical_pricing.enabled', false);
        $observed = Livewire::test('consumption-calculator')->get('contractTypePriceEstimates');

        $this->assertSame('2026-07-28', $observed['date']);
        $this->assertSame(111.0, collect($observed['rows'])->firstWhere('key', 'fixed_term_12')['costs']['median']['annual']);
        $this->assertSame(3, ContractPriceDailyStatistic::count(), 'Selecting the current basis must not delete historical evidence.');
    }

    public function test_unit_statistics_without_an_annual_metric_are_unavailable(): void
    {
        foreach (['energy_price', 'monthly_fee'] as $metric) {
            ContractPriceDailyStatistic::create([
                'stat_date' => '2026-05-30',
                'segment_key' => 'fixed_term_12',
                'metric_key' => $metric,
                'consumption_kwh' => null,
                'min_value' => 1.0,
                'p20_value' => 7.0,
                'avg_value' => 8.0,
                'median_value' => 8.5,
                'p80_value' => 10.0,
                'max_value' => 20.0,
                'contract_count' => 12,
            ]);
        }

        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertDontSee('Määräaikainen 12 kk');
        $response->assertDontSee('320 €/v');
    }

    public function test_kwh_amount_faq_falls_back_when_no_statistics_exist(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertSee('Paljonko 20 000 kWh sähköä maksaa vuodessa?', false);
        $response->assertSee('20 000 kWh vuosikulutuksen hinta lasketaan kaavalla', false);
        $response->assertDontSee('maksaa tällä hetkellä noin', false);
    }

    private function annualStat(string $date, string $segment, float $value, string $pricingBasis): void
    {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => 'annual_cost',
            'pricing_basis' => $pricingBasis,
            'consumption_kwh' => 5000,
            'min_value' => $value,
            'p20_value' => $value,
            'avg_value' => $value,
            'median_value' => $value,
            'p80_value' => $value,
            'max_value' => $value,
            'contract_count' => 12,
        ]);
    }

    public function test_page_renders_seo_content_sections(): void
    {
        $response = $this->get('/sahkosopimus/laskuri');

        $response->assertStatus(200);
        $response->assertSee('Miten sähkönkulutuslaskuri toimii?');
        $response->assertSee('Sähkön kulutuksen laskeminen itse');
        $response->assertSee('Sähkön hinta ja vuosikustannus');
        $response->assertSee('Usein kysyttyä sähkönkulutuksesta');
        $response->assertSee('Kuinka paljon omakotitalo kuluttaa sähköä?');
    }
}
