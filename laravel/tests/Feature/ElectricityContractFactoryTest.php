<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\ContractCard\Enums\PricingBucket;
use App\Services\ContractCard\PricingCategoryResolver;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ElectricityContractFactoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Älykäs Energia Oy',
            'company_url' => 'https://example.test',
        ]);
    }

    public function test_default_is_an_inactive_canonical_household_fixed_contract_with_automatic_identifiers(): void
    {
        $contract = ElectricityContract::factory()
            ->forCompany($this->company)
            ->create(['name' => 'Vakaa Ääni Öiseen Åboon']);

        $this->assertSame('Älykäs Energia Oy', $contract->company_name);
        $this->assertSame('vakaa-aani-oiseen-aboon', $contract->name_slug);
        $this->assertTrue(Str::isUuid($contract->api_id));
        $this->assertSame('OpenEnded', $contract->contract_type);
        $this->assertNull($contract->fixed_time_range);
        $this->assertSame('General', $contract->metering);
        $this->assertSame('FixedPrice', $contract->pricing_model);
        $this->assertSame('Household', $contract->target_group);
        $this->assertTrue($contract->availability_is_national);
        $this->assertFalse($contract->pricing_has_discounts);
        $this->assertFalse($contract->consumption_control);
        $this->assertNull($contract->consumption_limitation_min_x_kwh_per_y);
        $this->assertNull($contract->consumption_limitation_max_x_kwh_per_y);
        $this->assertFalse($contract->isActive());
        $this->assertCount(0, $contract->priceComponents);

        $this->parse($contract);
    }

    public function test_missing_company_fails_clearly_before_persistence(): void
    {
        try {
            ElectricityContract::factory()->create();
            $this->fail('The factory persisted a contract without an explicit company.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'ElectricityContractFactory requires forCompany() before persistence.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('electricity_contracts', 0);
    }

    public function test_for_company_string_requires_an_existing_company(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ElectricityContractFactory company [Puuttuva Oy] does not exist.');

        ElectricityContract::factory()->forCompany('Puuttuva Oy')->create();
    }

    public function test_active_and_inactive_contracts_are_explicit(): void
    {
        $inactive = ElectricityContract::factory()->forCompany($this->company)->create();
        $active = ElectricityContract::factory()->forCompany($this->company)->active()->create();

        $this->assertFalse($inactive->isActive());
        $this->assertTrue($active->isActive());
        $this->assertDatabaseHas('active_contracts', ['id' => $active->id]);
    }

    public function test_household_state_restores_household_classification(): void
    {
        $contract = ElectricityContract::factory()
            ->forCompany($this->company)
            ->state(['target_group' => 'Company', 'availability_is_national' => false])
            ->household()
            ->create();

        $this->assertSame('Household', $contract->target_group);
        $this->assertTrue($contract->availability_is_national);
    }

    public function test_pricing_states_keep_raw_and_canonical_classification_facts_consistent(): void
    {
        $spot = ElectricityContract::factory()->forCompany($this->company)->spot()->create();
        $fixedTerm = ElectricityContract::factory()->forCompany($this->company)->fixedTerm()->create();
        $hybrid = ElectricityContract::factory()->forCompany($this->company)->hybrid()->create();
        $reset = ElectricityContract::factory()->forCompany($this->company)->reset()->create();
        $package = ElectricityContract::factory()->forCompany($this->company)->package()->create();

        $this->assertSame('Spot', $spot->pricing_model);
        $this->assertSame('estimate_required', $spot->canonical_calculation['status']);
        $this->assertSame('spot_margin', $spot->canonical_pricing['phases'][0]['components'][0]['component_type']);
        $this->assertSame(PricingBucket::Spot, $this->bucket($spot));

        $this->assertSame('FixedTerm', $fixedTerm->contract_type);
        $this->assertSame('Fixed12', $fixedTerm->fixed_time_range);
        $this->assertSame('FixedPrice', $fixedTerm->pricing_model);
        $this->assertSame(PricingBucket::Fixed, $this->bucket($fixedTerm));

        $this->assertSame('Hybrid', $hybrid->pricing_model);
        $this->assertTrue($hybrid->consumption_control);
        $this->assertTrue($hybrid->canonical_pricing['consumption_effect']['present']);
        $this->assertSame('base_contract', $hybrid->canonical_pricing['consumption_effect']['applies_to']);
        $this->assertSame('unsupported', $hybrid->canonical_calculation['status']);
        $this->assertSame(PricingBucket::ConsumptionEffect, $this->bucket($hybrid));

        $this->assertSame('FixedPrice', $reset->pricing_model);
        $this->assertTrue($reset->canonical_pricing['recurring_schedule']['present']);
        $this->assertSame('quarterly', $reset->canonical_pricing['recurring_schedule']['cadence']);
        $this->assertSame('estimate_required', $reset->canonical_calculation['status']);
        $this->assertSame(PricingBucket::MarketReset, $this->bucket($reset));

        $this->assertSame('FixedPrice', $package->pricing_model);
        $this->assertSame([], $package->canonical_pricing['phases'][0]['components']);
        $this->assertSame('monthly', $package->canonical_pricing['phases'][0]['package']['allowance_cadence']);
        $this->assertEquals(150.0, $package->canonical_pricing['phases'][0]['package']['included_kwh']);
        $this->assertSame(PricingBucket::Fixed, $this->bucket($package));

        foreach ([$spot, $fixedTerm, $hybrid, $reset, $package] as $contract) {
            $this->parse($contract);
        }
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('canonicalScenarioProvider')]
    public function test_each_canonical_fixture_scenario_parses(array $attributes): void
    {
        $data = app(CanonicalPricingParser::class)->parse(
            $attributes['canonical_pricing'],
            $attributes['canonical_calculation'],
            $attributes['canonical_source_consistency'],
        );

        $this->assertNotEmpty($data->phases);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function canonicalScenarioProvider(): iterable
    {
        yield 'fixed' => [CanonicalPricingFixture::fixedAttributes()];
        yield 'spot' => [CanonicalPricingFixture::spotAttributes()];
        yield 'hybrid' => [CanonicalPricingFixture::hybridAttributes()];
        yield 'periodic reset' => [CanonicalPricingFixture::resetAttributes()];
        yield 'monthly included-energy package' => [CanonicalPricingFixture::packageAttributes()];
        yield 'introductory to normal' => [CanonicalPricingFixture::introductoryToNormalAttributes()];
    }

    public function test_canonical_only_has_no_relational_price_components(): void
    {
        $contract = ElectricityContract::factory()
            ->forCompany($this->company)
            ->spot()
            ->canonicalOnly()
            ->create();

        $this->assertSame('Spot', $contract->pricing_model);
        $this->assertSame('spot_margin', $contract->canonical_pricing['phases'][0]['components'][0]['component_type']);
        $this->assertDatabaseMissing('price_components', ['electricity_contract_id' => $contract->id]);
    }

    public function test_legacy_clears_all_canonical_fields(): void
    {
        $contract = ElectricityContract::factory()
            ->forCompany($this->company)
            ->legacy()
            ->create();

        $this->assertNull($contract->canonical_pricing);
        $this->assertNull($contract->canonical_source_consistency);
        $this->assertNull($contract->canonical_calculation);
    }

    public function test_relational_prices_preserve_explicit_component_facts(): void
    {
        $contract = ElectricityContract::factory()
            ->forCompany($this->company)
            ->withRelationalPrices([
                [
                    'price_component_type' => 'General',
                    'payment_unit' => 'CentPerKiwattHour',
                    'price' => 8.75,
                    'price_date' => '2026-08-01',
                    'has_discount' => true,
                    'discount_value' => 1.25,
                    'discount_is_percentage' => false,
                    'discount_type' => 'FirstMonths',
                    'discount_discount_n_first_kwh' => null,
                    'discount_discount_n_first_months' => 3,
                    'discount_discount_until_date' => '2026-10-31',
                ],
                [
                    'price_component_type' => 'Monthly',
                    'payment_unit' => 'EurPerMonth',
                    'price' => 4.90,
                    'price_date' => '2026-08-01',
                    'has_discount' => false,
                ],
            ])
            ->create();

        $components = $contract->priceComponents()->orderBy('price_component_type')->get();
        $general = $components->firstWhere('price_component_type', 'General');
        $monthly = $components->firstWhere('price_component_type', 'Monthly');

        $this->assertCount(2, $components);
        $this->assertSame("factory-price-0-{$contract->id}", $general->id);
        $this->assertSame('CentPerKiwattHour', $general->payment_unit);
        $this->assertSame(8.75, $general->price);
        $this->assertSame('2026-08-01', $general->price_date->toDateString());
        $this->assertTrue($general->has_discount);
        $this->assertSame(1.25, $general->discount_value);
        $this->assertFalse($general->discount_is_percentage);
        $this->assertSame('FirstMonths', $general->discount_type);
        $this->assertNull($general->discount_discount_n_first_kwh);
        $this->assertSame(3, $general->discount_discount_n_first_months);
        $this->assertSame('2026-10-31', $general->discount_discount_until_date->toDateString());
        $this->assertSame("factory-price-1-{$contract->id}", $monthly->id);
        $this->assertSame('EurPerMonth', $monthly->payment_unit);
        $this->assertSame(4.90, $monthly->price);
        $this->assertFalse($monthly->has_discount);
        $this->assertTrue($contract->pricing_has_discounts);
    }

    public function test_consumption_limits_are_explicit(): void
    {
        $unlimited = ElectricityContract::factory()->forCompany($this->company)->create();
        $limited = ElectricityContract::factory()
            ->forCompany($this->company)
            ->withConsumptionLimits(1500, 10000)
            ->create();

        $this->assertFalse($unlimited->hasConsumptionLimits());
        $this->assertFalse($unlimited->consumption_control);
        $this->assertTrue($limited->hasConsumptionLimits());
        $this->assertTrue($limited->consumption_control);
        $this->assertSame(1500.0, $limited->consumption_limitation_min_x_kwh_per_y);
        $this->assertSame(10000.0, $limited->consumption_limitation_max_x_kwh_per_y);
        $this->assertFalse($limited->isConsumptionInRange(1000));
        $this->assertTrue($limited->isConsumptionInRange(5000));
        $this->assertFalse($limited->isConsumptionInRange(12000));
    }

    #[DataProvider('invalidConsumptionLimitsProvider')]
    public function test_consumption_limits_reject_invalid_bounds(
        float|int|null $minimum,
        float|int|null $maximum,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ElectricityContract::factory()
            ->forCompany($this->company)
            ->withConsumptionLimits($minimum, $maximum);
    }

    /** @return iterable<string, array{float|int|null, float|int|null, string}> */
    public static function invalidConsumptionLimitsProvider(): iterable
    {
        yield 'negative minimum' => [-1, 10000, 'withConsumptionLimits() values must not be negative.'];
        yield 'negative maximum' => [null, -1, 'withConsumptionLimits() values must not be negative.'];
        yield 'inverted range' => [10000, 1500, 'withConsumptionLimits() minimum must not exceed maximum.'];
    }

    private function parse(ElectricityContract $contract): void
    {
        $data = app(CanonicalPricingParser::class)->parse(
            $contract->canonical_pricing,
            $contract->canonical_calculation,
            $contract->canonical_source_consistency,
        );

        $this->assertNotEmpty($data->phases);
    }

    private function bucket(ElectricityContract $contract): PricingBucket
    {
        $facts = app(PricingCategoryResolver::class)->resolve($contract);

        return PricingBucket::fromFacts($facts);
    }
}
