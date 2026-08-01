<?php

namespace Tests\Feature;

use App\Livewire\SahkosopimusIndex;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\MisleadingState;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CanonicalPricingListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Company::create(['name' => 'Reilu Energia Oy', 'name_slug' => 'reilu-energia-oy', 'company_url' => 'https://reilu.fi']);
        Company::create(['name' => 'Viekas Energia Oy', 'name_slug' => 'viekas-energia-oy', 'company_url' => 'https://viekas.fi']);
    }

    /** @param array<string, mixed> $canonicalAttributes */
    private function createContract(
        string $id,
        string $name,
        string $company,
        array $canonicalAttributes,
    ): ElectricityContract {
        return ElectricityContract::factory()
            ->forCompany($company)
            ->active()
            ->withRelationalPrices([[
                'id' => 'pc-gen-'.$id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 6.0,
                'payment_unit' => 'c/kWh',
            ]])
            ->create([
                'id' => $id,
                'name' => $name,
                'contract_type' => 'OpenEnded',
                'pricing_model' => 'FixedPrice',
                'metering' => 'General',
                'target_group' => 'Household',
                'short_description' => null,
                'pricing_name' => null,
                'pricing_has_discounts' => null,
                'consumption_control' => null,
                'pre_billing' => null,
                'available_for_existing_users' => null,
                'delivery_responsibility_product' => null,
                'order_link' => null,
                'product_link' => null,
                'availability_is_national' => true,
                'microproduction_buys' => null,
                ...$canonicalAttributes,
            ]);
    }

    public function test_honest_contract_is_listed_and_deceptive_is_labelled_and_unknown_is_hidden(): void
    {
        // Honest single-price contract.
        $this->createContract(
            'honest-1',
            'Reilu Perussähkö',
            'Reilu Energia Oy',
            CanonicalPricingFixture::attributes(
                phases: [CanonicalPricingFixture::phase(
                    label: 'current',
                    kind: PhaseKind::CurrentStructured,
                    starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                    ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
                    components: [CanonicalPricingFixture::component(
                        ComponentType::EnergyGeneral,
                        7.0,
                        ComponentUnit::CentsPerKwh,
                    )],
                )],
                calculationStatus: CalculationStatus::Exact,
                misleading: MisleadingState::NotDetected,
                structuredPricingStatus: 'complete',
                issueCodes: [],
            ),
        );

        // Deceptive promo with a KNOWN later price → listed, ranked by true cost, labelled.
        $this->createContract(
            'deceptive-1',
            'Viekas Tarjoushinta',
            'Viekas Energia Oy',
            CanonicalPricingFixture::attributes(
                phases: [
                    CanonicalPricingFixture::phase(
                        label: 'intro',
                        kind: PhaseKind::Introductory,
                        starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                        ends: CanonicalPricingFixture::boundary(BoundaryKind::Date, now()->addDays(30)->format('Y-m-d')),
                        components: [CanonicalPricingFixture::component(
                            ComponentType::EnergyGeneral,
                            3.0,
                            ComponentUnit::CentsPerKwh,
                            PriceRole::Introductory,
                        )],
                    ),
                    CanonicalPricingFixture::phase(
                        label: 'normal',
                        kind: PhaseKind::Normal,
                        starts: CanonicalPricingFixture::boundary(BoundaryKind::Date, now()->addDays(31)->format('Y-m-d')),
                        ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
                        components: [CanonicalPricingFixture::component(
                            ComponentType::EnergyGeneral,
                            15.0,
                            ComponentUnit::CentsPerKwh,
                            PriceRole::Normal,
                        )],
                    ),
                ],
                calculationStatus: CalculationStatus::Exact,
                misleading: MisleadingState::Detected,
                structuredPricingStatus: 'complete',
                issueCodes: ['structured_matches_intro_only', 'future_price_omitted'],
            ),
        );

        // Deceptive promo with an UNKNOWN later price → excluded from listings.
        $this->createContract(
            'unknown-1',
            'Viekas Piilohinta',
            'Viekas Energia Oy',
            CanonicalPricingFixture::attributes(
                phases: [CanonicalPricingFixture::phase(
                    label: 'intro',
                    kind: PhaseKind::Introductory,
                    starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                    ends: CanonicalPricingFixture::boundary(BoundaryKind::AfterMonths, '1'),
                    components: [CanonicalPricingFixture::component(
                        ComponentType::EnergyGeneral,
                        2.0,
                        ComponentUnit::CentsPerKwh,
                        PriceRole::Introductory,
                    )],
                )],
                calculationStatus: CalculationStatus::EstimateRequired,
                misleading: MisleadingState::Detected,
                structuredPricingStatus: 'complete',
                issueCodes: ['promotion_metadata_missing', 'future_price_unknown'],
            ),
        );

        // Malformed package data must fail closed even when a relational rate is available.
        $malformedPackagePhase = [
            'label' => 'package',
            'phase_kind' => PhaseKind::CurrentStructured->value,
            'starts' => CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
            'ends' => CanonicalPricingFixture::boundary(BoundaryKind::None),
            'components' => [],
            'package' => [
                'monthly_fee_eur' => 25.0,
                'included_kwh' => 150.0,
                'allowance_cadence' => 'annual',
                'excess_rate_cents_per_kwh' => 16.6,
            ],
            'evidence' => [],
        ];

        $this->createContract(
            'invalid-package-1',
            'Viekas Virhepaketti',
            'Viekas Energia Oy',
            CanonicalPricingFixture::attributes(
                phases: [$malformedPackagePhase],
                calculationStatus: CalculationStatus::Exact,
                misleading: MisleadingState::NotDetected,
                structuredPricingStatus: 'complete',
                issueCodes: [],
            ),
        );

        $component = Livewire::test(SahkosopimusIndex::class)->set('consumption', 5000);
        $contracts = $component->viewData('contracts');

        $this->assertSame(2, $contracts->total());
        $this->assertEqualsCanonicalizing(
            ['honest-1', 'deceptive-1'],
            $contracts->pluck('id')->all(),
        );
        $component->assertSee('Reilu Perussähkö');
        $component->assertSee('Viekas Tarjoushinta');
        // Excluded contract must not appear in the listing.
        $component->assertDontSee('Viekas Piilohinta');
        $component->assertDontSee('Viekas Virhepaketti');
        // Deceptive contract carries the price-increase warning pill.
        $component->assertSee('Hinta nousee');
    }
}
