<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CanonicalOfferSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Company::create([
            'name' => 'Offer Energy Oy',
            'name_slug' => 'offer-energy-oy',
            'company_url' => 'https://offer.example',
        ]);

        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => now()->subDays(365),
            'period_end' => now(),
            'avg_price_with_tax' => 7.0,
            'avg_price_without_tax' => 5.58,
            'day_avg_with_tax' => 7.0,
            'night_avg_with_tax' => 5.0,
            'hours_count' => 8760,
        ]);
    }

    public function test_company_offers_use_only_measured_canonical_facts(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $this->createContract('canonical-conflict', 'Canonical conflict', $this->offerPhase(), relationalDiscount: 99.0);
        $this->createContract('canonical-only', 'Canonical only', $this->offerPhase(8.0, 9.0));
        $this->createContract('relational-only', 'Relational only', $this->plainPhase(), relationalDiscount: 77.0);
        $this->createContract('excluded-offer', 'Excluded offer', $this->offerPhase(), status: 'incomplete', relationalDiscount: 66.0);
        $this->createContract('package', 'Monthly package', $this->packagePhase(), pricingHasDiscounts: true, relationalDiscount: 55.0);
        $this->createContract('phase-only-offer', 'Phase-only offer', $this->phaseOnlyOffer());
        $this->createContract('untyped-offer', 'Untyped offer', $this->unresolvedOffer());
        $this->createContract('zero-saving', 'Zero saving', $this->plainPhase(), pricingHasDiscounts: true);

        DB::enableQueryLog();
        $component = Livewire::test('company-detail', ['companySlug' => 'offer-energy-oy']);
        $names = $component->viewData('promotionContracts')->pluck('name')->all();

        $this->assertSame(['Phase-only offer', 'Canonical conflict', 'Canonical only'], $names);

        $allContracts = $component->viewData('contracts')->keyBy('name');
        $this->assertGreaterThan(0, $allContracts['Untyped offer']->calculated_cost['discount_savings_total']);
        $this->assertSame([], $allContracts['Untyped offer']->calculated_cost['offer_terms']);
        $this->assertFalse($allContracts['Zero saving']->calculated_cost['includes_discounts']);

        $component
            ->assertSee('Perusmaksu 0 €/kk ensimmäisen kuukauden')
            ->assertSee('Perusmaksu 5 €/kk ensimmäiset 12 kk')
            ->assertSee('Energiahinta 8,00 c/kWh ja perusmaksu 5 €/kk ensimmäiset 12 kk')
            ->assertSee('60 €')
            ->assertSee('12 kuukauden vertailussa')
            ->assertSee('Säästö')
            ->assertDontSee('Mitattu etu')
            ->assertDontSee('HOSTILE RAW PHASE LABEL')
            ->assertDontSee('-99,00 c/kWh alennus');

        $priceComponentQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'price_components'))
            ->values();

        $this->assertLessThanOrEqual(1, $priceComponentQueries->count());
        $this->assertTrue($priceComponentQueries->every(
            fn (string $query) => str_contains($query, 'max("price_date")'),
        ), 'Canonical mode can use only the bounded legacy update-date aggregate.');
    }

    public function test_company_short_offer_uses_the_real_six_month_benefit(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $this->createContract(
            'six-month',
            'Six month offer',
            $this->offerPhase(8.0, 8.0, 5.0, 10.0, $this->boundary('after_months', '6')),
            contractType: 'FixedTerm',
            fixedTimeRange: 'Fixed6',
        );

        $component = Livewire::test('company-detail', ['companySlug' => 'offer-energy-oy']);
        $contract = $component->viewData('promotionContracts')->first();

        $this->assertEqualsWithDelta(60.0, $contract->calculated_cost['discount_savings_total'], 0.001);
        $this->assertEqualsWithDelta(30.0, $contract->calculated_cost['contract_term']['discount_savings_total'], 0.001);
        $component
            ->assertSee('Perusmaksu 5 €/kk koko 6 kk sopimuskauden')
            ->assertSee('30 €')
            ->assertSee('6 kuukauden sopimuskaudella');

        $this->assertStringNotContainsString('Säästö 60 €', $contract->offer_fact['description']);
    }

    public function test_company_short_hybrid_offer_uses_the_real_six_month_benefit(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $this->createContract(
            'six-month-hybrid',
            'Six month hybrid offer',
            $this->shortHybridOfferPhases(),
            status: 'unsupported',
            contractType: 'FixedTerm',
            fixedTimeRange: 'Fixed6',
            pricingModel: 'Hybrid',
        );

        $component = Livewire::test('company-detail', ['companySlug' => 'offer-energy-oy']);
        $contract = $component->viewData('promotionContracts')->first();

        $this->assertSame('base_only_hybrid', $contract->calculated_cost['comparability']);
        $this->assertSame(6, $contract->calculated_cost['term_months']);
        $this->assertEqualsWithDelta(5.0, $contract->calculated_cost['contract_term']['discount_savings_total'], 0.001);
        $this->assertSame('6 kuukauden sopimuskaudella', $contract->offer_fact['basis_label']);
        $this->assertEqualsWithDelta(5.0, $contract->offer_fact['benefit_eur'], 0.001);

        $component
            ->assertSee('Perusmaksu 5 €/kk ensimmäisen kuukauden')
            ->assertSee('6 kuukauden sopimuskaudella');
    }

    public function test_company_spot_margin_offer_uses_the_absolute_end_date(): void
    {
        config()->set('canonical_pricing.enabled', true);
        $this->travelTo('2026-07-01 12:00:00');

        $this->createContract(
            'spot-margin',
            'Spot margin offer',
            $this->spotMarginOfferPhases(),
            status: 'estimate_required',
            pricingModel: 'Spot',
        );

        Livewire::test('company-detail', ['companySlug' => 'offer-energy-oy'])
            ->assertSee('Marginaali 0,20 c/kWh 31.8.2026 asti')
            ->assertDontSee('HOSTILE RAW PHASE LABEL');
    }

    public function test_company_feature_off_keeps_relational_offer_behavior(): void
    {
        config()->set('canonical_pricing.enabled', false);

        $this->createContract('canonical-only', 'Canonical only', $this->offerPhase());
        $this->createContract('legacy-offer', 'Legacy offer', $this->plainPhase(), relationalDiscount: 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'offer-energy-oy']);
        $names = $component->viewData('promotionContracts')->pluck('name')->all();

        $this->assertSame(['Legacy offer'], $names);
        $component->assertSee('-2,00 c/kWh alennus');
    }

    public function test_seo_offer_listing_uses_canonical_membership_and_json_ld(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $this->createContract('canonical-conflict', 'Canonical conflict', $this->offerPhase(), relationalDiscount: 99.0);
        $this->createContract('canonical-only', 'Canonical only', $this->offerPhase(8.0, 9.0));
        $this->createContract('relational-only', 'Relational only', $this->plainPhase(), relationalDiscount: 77.0);
        $this->createContract('excluded-offer', 'Excluded offer', $this->offerPhase(), status: 'incomplete', relationalDiscount: 66.0);
        $this->createContract('package', 'Monthly package', $this->packagePhase(), pricingHasDiscounts: true, relationalDiscount: 55.0);
        $this->createContract('phase-only-offer', 'Phase-only offer', $this->phaseOnlyOffer());
        $this->createContract('untyped-offer', 'Untyped offer', $this->unresolvedOffer());
        $this->createContract('zero-saving', 'Zero saving', $this->plainPhase(), pricingHasDiscounts: true);

        DB::enableQueryLog();
        $component = Livewire::test('seo-contracts-list', ['offerType' => 'promotion']);
        $contracts = $component->viewData('contracts');

        $this->assertSame(['Phase-only offer', 'Canonical conflict', 'Canonical only'], $contracts->pluck('name')->all());

        $descriptions = $this->jsonLdDescriptions($component->viewData('seoData')['jsonLd']);
        $this->assertStringContainsString('Perusmaksu 0 €/kk ensimmäisen kuukauden', $descriptions['Phase-only offer']);
        $this->assertStringContainsString('Perusmaksu 5 €/kk ensimmäiset 12 kk. Säästö 60 € 12 kuukauden vertailussa.', $descriptions['Canonical conflict']);
        $this->assertStringContainsString('Energiahinta 8,00 c/kWh ja perusmaksu 5 €/kk ensimmäiset 12 kk. Säästö 110 € 12 kuukauden vertailussa.', $descriptions['Canonical only']);
        $this->assertStringNotContainsString('99,00', $descriptions['Canonical conflict']);
        $this->assertArrayNotHasKey('Relational only', $descriptions);
        $this->assertArrayNotHasKey('Excluded offer', $descriptions);
        $this->assertArrayNotHasKey('Monthly package', $descriptions);
        $this->assertSame([], collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'price_components'))
            ->values()
            ->all());
    }

    public function test_seo_short_offer_json_ld_uses_actual_term_benefit(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $this->createContract(
            'six-month',
            'Six month offer',
            $this->offerPhase(8.0, 8.0, 5.0, 10.0, $this->boundary('after_months', '6')),
            contractType: 'FixedTerm',
            fixedTimeRange: 'Fixed6',
        );

        $component = Livewire::test('seo-contracts-list', ['offerType' => 'promotion']);
        $description = $this->jsonLdDescriptions($component->viewData('seoData')['jsonLd'])['Six month offer'];

        $this->assertStringContainsString('Perusmaksu 5 €/kk koko 6 kk sopimuskauden. Säästö 30 € 6 kuukauden sopimuskaudella.', $description);
        $this->assertStringNotContainsString('60 €', $description);
    }

    public function test_seo_feature_off_keeps_relational_offer_filter_and_json_ld(): void
    {
        config()->set('canonical_pricing.enabled', false);

        $this->createContract('canonical-only', 'Canonical only', $this->offerPhase());
        $this->createContract('legacy-offer', 'Legacy offer', $this->plainPhase(), relationalDiscount: 2.0);

        $component = Livewire::test('seo-contracts-list', ['offerType' => 'promotion']);
        $contracts = $component->viewData('contracts');
        $descriptions = $this->jsonLdDescriptions($component->viewData('seoData')['jsonLd']);

        $this->assertSame(['Legacy offer'], $contracts->pluck('name')->all());
        $this->assertStringContainsString('-2,00 c/kWh alennus', $descriptions['Legacy offer']);
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     */
    private function createContract(
        string $id,
        string $name,
        array $phases,
        string $status = 'exact',
        ?float $relationalDiscount = null,
        bool $pricingHasDiscounts = false,
        string $contractType = 'OpenEnded',
        ?string $fixedTimeRange = null,
        string $pricingModel = 'FixedPrice',
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Offer Energy Oy',
            'name' => $name,
            'contract_type' => $contractType,
            'fixed_time_range' => $fixedTimeRange,
            'pricing_model' => $pricingModel,
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'pricing_has_discounts' => $pricingHasDiscounts,
            'canonical_pricing' => [
                'phases' => $phases,
                'recurring_schedule' => [
                    'present' => false,
                    'cadence' => 'none',
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'future_price_known' => null,
                    'description' => null,
                    'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false,
                    'applies_to' => 'unknown',
                    'cadence' => 'none',
                    'expected_cents_per_kwh' => null,
                    'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null,
                    'uncapped' => null,
                    'description' => null,
                    'evidence' => [],
                ],
            ],
            'canonical_calculation' => [
                'status' => $status,
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'not_detected',
                'structured_pricing_status' => 'complete',
                'issue_codes' => [],
            ],
        ]);

        if ($relationalDiscount !== null) {
            PriceComponent::create([
                'id' => 'price-'.$id,
                'electricity_contract_id' => $id,
                'price_component_type' => 'General',
                'price_date' => now()->toDateString(),
                'price' => 1.0,
                'payment_unit' => 'CentPerKiwattHour',
                'has_discount' => true,
                'discount_value' => $relationalDiscount,
                'discount_is_percentage' => false,
                'discount_discount_n_first_months' => 3,
            ]);
        }

        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    /** @return list<array<string, mixed>> */
    private function offerPhase(
        float $energy = 8.0,
        float $normalEnergy = 8.0,
        float $monthlyFee = 5.0,
        float $normalMonthlyFee = 10.0,
        ?array $ends = null,
    ): array {
        return [[
            'label' => 'HOSTILE RAW PHASE LABEL <script>alert(1)</script>',
            'phase_kind' => 'introductory',
            'starts' => $this->boundary('contract_start'),
            'ends' => $ends ?? $this->boundary('after_months', '12'),
            'components' => [
                $this->canonicalComponent('energy_general', $energy, $normalEnergy),
                $this->canonicalComponent('monthly_fee', $monthlyFee, $normalMonthlyFee, 'eur_per_month'),
            ],
            'package' => null,
            'evidence' => [],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function shortHybridOfferPhases(): array
    {
        return [
            [
                'label' => 'must not render',
                'phase_kind' => 'introductory',
                'starts' => $this->boundary('contract_start'),
                'ends' => $this->boundary('after_months', '1'),
                'components' => [
                    $this->canonicalComponent('energy_general', 8.0),
                    $this->canonicalComponent('monthly_fee', 5.0, 10.0, 'eur_per_month'),
                ],
                'package' => null,
                'evidence' => [],
            ],
            [
                'label' => 'must not render either',
                'phase_kind' => 'continuation',
                'starts' => $this->boundary('after_months', '1'),
                'ends' => $this->boundary('after_months', '6'),
                'components' => [
                    $this->canonicalComponent('energy_general', 8.0),
                    $this->canonicalComponent('monthly_fee', 10.0, null, 'eur_per_month'),
                ],
                'package' => null,
                'evidence' => [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function spotMarginOfferPhases(): array
    {
        return [
            [
                'label' => 'HOSTILE RAW PHASE LABEL',
                'phase_kind' => 'introductory',
                'starts' => $this->boundary('unknown'),
                'ends' => $this->boundary('date', '2026-08-31'),
                'components' => [
                    $this->canonicalComponent('spot_margin', 0.2, 0.6),
                    $this->canonicalComponent('monthly_fee', 3.0, null, 'eur_per_month'),
                ],
                'package' => null,
                'evidence' => [],
            ],
            [
                'label' => 'normal',
                'phase_kind' => 'normal',
                'starts' => $this->boundary('date', '2026-09-01'),
                'ends' => $this->boundary('none'),
                'components' => [
                    $this->canonicalComponent('spot_margin', 0.6),
                    $this->canonicalComponent('monthly_fee', 3.0, null, 'eur_per_month'),
                ],
                'package' => null,
                'evidence' => [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function phaseOnlyOffer(): array
    {
        return [
            [
                'label' => 'intro',
                'phase_kind' => 'introductory',
                'starts' => $this->boundary('contract_start'),
                'ends' => $this->boundary('after_months', '1'),
                'components' => [
                    $this->canonicalComponent('energy_general', 8.0),
                    $this->canonicalComponent('monthly_fee', 0.0, null, 'eur_per_month'),
                ],
                'package' => null,
                'evidence' => [],
            ],
            [
                'label' => 'normal',
                'phase_kind' => 'normal',
                'starts' => $this->boundary('after_months', '1'),
                'ends' => $this->boundary('none'),
                'components' => [
                    $this->canonicalComponent('energy_general', 8.0),
                    $this->canonicalComponent('monthly_fee', 5.0, null, 'eur_per_month'),
                ],
                'package' => null,
                'evidence' => [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function unresolvedOffer(): array
    {
        return [[
            'label' => 'must not render',
            'phase_kind' => 'current_structured',
            'starts' => $this->boundary('contract_start'),
            'ends' => $this->boundary('none'),
            'components' => [
                $this->canonicalComponent('energy_general', 8.0),
                $this->canonicalComponent('monthly_fee', 5.0, 10.0, 'eur_per_month'),
            ],
            'package' => null,
            'evidence' => [],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function plainPhase(): array
    {
        return [[
            'label' => 'plain',
            'phase_kind' => 'current_structured',
            'starts' => $this->boundary('contract_start'),
            'ends' => $this->boundary('none'),
            'components' => [$this->canonicalComponent('energy_general', 8.0)],
            'package' => null,
            'evidence' => [],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function packagePhase(): array
    {
        return [[
            'label' => 'package',
            'phase_kind' => 'current_structured',
            'starts' => $this->boundary('contract_start'),
            'ends' => $this->boundary('none'),
            'components' => [],
            'package' => [
                'monthly_fee_eur' => 21.0,
                'included_kwh' => 150.0,
                'allowance_cadence' => 'monthly',
                'excess_rate_cents_per_kwh' => 16.6,
            ],
            'evidence' => [],
        ]];
    }

    /** @return array<string, mixed> */
    private function canonicalComponent(
        string $type,
        float $amount,
        ?float $normalAmount = null,
        string $unit = 'cents_per_kwh',
    ): array {
        return [
            'component_type' => $type,
            'amount' => $amount,
            'normal_amount' => $normalAmount,
            'unit' => $unit,
            'vat_status' => 'included',
            'price_role' => 'current',
            'source_kind' => 'both',
            'evidence' => [],
        ];
    }

    /** @return array{kind: string, value: string|null} */
    private function boundary(string $kind, ?string $value = null): array
    {
        return ['kind' => $kind, 'value' => $value];
    }

    /** @return array<string, string> */
    private function jsonLdDescriptions(array $jsonLd): array
    {
        $itemList = collect($jsonLd['@graph'])->firstWhere('@type', 'ItemList');

        return collect($itemList['itemListElement'])
            ->mapWithKeys(fn (array $item) => [
                $item['item']['name'] => $item['item']['description'],
            ])
            ->all();
    }
}
