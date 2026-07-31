<?php

namespace Tests\Feature;

use App\Livewire\SahkosopimusIndex;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
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
        Company::create(['name' => 'Reilu Energia Oy', 'name_slug' => 'reilu-energia-oy', 'company_url' => 'https://reilu.fi']);
        Company::create(['name' => 'Viekas Energia Oy', 'name_slug' => 'viekas-energia-oy', 'company_url' => 'https://viekas.fi']);
    }

    private function boundary(string $kind, ?string $value = null): array
    {
        return ['kind' => $kind, 'value' => $value];
    }

    private function priceComponent(string $type, ?float $amount, string $unit = 'cents_per_kwh', string $role = 'current'): array
    {
        return [
            'component_type' => $type, 'amount' => $amount, 'normal_amount' => null, 'unit' => $unit,
            'vat_status' => 'included', 'price_role' => $role, 'source_kind' => 'both', 'evidence' => [],
        ];
    }

    private function canonicalPricing(array $phases): array
    {
        return [
            'phases' => $phases,
            'recurring_schedule' => ['present' => false, 'cadence' => 'none', 'current_period_start' => null, 'current_period_end' => null, 'future_price_known' => null, 'description' => null, 'evidence' => []],
            'consumption_effect' => ['present' => false, 'applies_to' => 'unknown', 'cadence' => 'none', 'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null, 'typical_max_cents_per_kwh' => null, 'hard_min_cents_per_kwh' => null, 'hard_max_cents_per_kwh' => null, 'uncapped' => null, 'description' => null, 'evidence' => []],
        ];
    }

    private function createContract(string $id, string $name, string $company, array $pricing, string $status, string $misleading, array $issues): ElectricityContract
    {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $company,
            'name' => $name,
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => $pricing,
            'canonical_calculation' => ['status' => $status, 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => $misleading,
                'structured_pricing_status' => 'complete',
                'issue_codes' => $issues,
            ],
        ]);

        PriceComponent::create([
            'id' => 'pc-gen-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    public function test_honest_contract_is_listed_and_deceptive_is_labelled_and_unknown_is_hidden(): void
    {
        // Honest single-price contract.
        $this->createContract(
            'honest-1', 'Reilu Perussähkö', 'Reilu Energia Oy',
            $this->canonicalPricing([[
                'label' => 'current', 'phase_kind' => 'current_structured',
                'starts' => $this->boundary('contract_start'), 'ends' => $this->boundary('none'),
                'components' => [$this->priceComponent('energy_general', 7.0)], 'evidence' => [],
            ]]),
            'exact', 'not_detected', [],
        );

        // Deceptive promo with a KNOWN later price → listed, ranked by true cost, labelled.
        $this->createContract(
            'deceptive-1', 'Viekas Tarjoushinta', 'Viekas Energia Oy',
            $this->canonicalPricing([
                [
                    'label' => 'intro', 'phase_kind' => 'introductory',
                    'starts' => $this->boundary('contract_start'), 'ends' => $this->boundary('date', now()->addDays(30)->format('Y-m-d')),
                    'components' => [$this->priceComponent('energy_general', 3.0, 'cents_per_kwh', 'introductory')], 'evidence' => [],
                ],
                [
                    'label' => 'normal', 'phase_kind' => 'normal',
                    'starts' => $this->boundary('date', now()->addDays(31)->format('Y-m-d')), 'ends' => $this->boundary('none'),
                    'components' => [$this->priceComponent('energy_general', 15.0, 'cents_per_kwh', 'normal')], 'evidence' => [],
                ],
            ]),
            'exact', 'detected', ['structured_matches_intro_only', 'future_price_omitted'],
        );

        // Deceptive promo with an UNKNOWN later price → excluded from listings.
        $this->createContract(
            'unknown-1', 'Viekas Piilohinta', 'Viekas Energia Oy',
            $this->canonicalPricing([[
                'label' => 'intro', 'phase_kind' => 'introductory',
                'starts' => $this->boundary('contract_start'), 'ends' => $this->boundary('after_months', '1'),
                'components' => [$this->priceComponent('energy_general', 2.0, 'cents_per_kwh', 'introductory')], 'evidence' => [],
            ]]),
            'estimate_required', 'detected', ['promotion_metadata_missing', 'future_price_unknown'],
        );

        // Malformed package data must fail closed even when a relational rate is available.
        $this->createContract(
            'invalid-package-1', 'Viekas Virhepaketti', 'Viekas Energia Oy',
            $this->canonicalPricing([[
                'label' => 'package', 'phase_kind' => 'current_structured',
                'starts' => $this->boundary('contract_start'), 'ends' => $this->boundary('none'),
                'components' => [],
                'package' => [
                    'monthly_fee_eur' => 25.0,
                    'included_kwh' => 150.0,
                    'allowance_cadence' => 'annual',
                    'excess_rate_cents_per_kwh' => 16.6,
                ],
                'evidence' => [],
            ]]),
            'exact', 'not_detected', [],
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
