<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\Enums\AllowanceCadence;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\MisleadingState;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The contract detail page is the third consumer of ContractCard\ContractCardPresenter.
 *
 * It became one because the page had drifted BELOW the honesty of the listing card that
 * links to it: a Hybrid showed "Energiahinta 0,00 c/kWh" with no consumption-effect row, a
 * Spot contract's flat promotional price was labelled "Marginaali", a null margin printed
 * the bare market average as if it were the contract's energy price, a consumption cap
 * warned on the card and nowhere on the page, and one contract's page had no call to action
 * at all. Every assertion here is one of those defects.
 */
class ContractDetailPresenterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->company = Company::create([
            'name' => 'Testi Energia Oy',
            'name_slug' => 'testi-energia-oy',
            'company_url' => 'https://testienergia.fi',
        ]);

        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => now()->subDays(365),
            'period_end' => now(),
            'avg_price_with_tax' => 7.77,
            'avg_price_without_tax' => 6.19,
            'day_avg_with_tax' => 7.77,
            'night_avg_with_tax' => 5.89,
            'hours_count' => 8760,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, float>  $components
     * @param  list<array<string, mixed>>  $additionalComponents
     */
    private function contract(
        string $id,
        array $attributes = [],
        array $components = ['General' => 7.2, 'Monthly' => 3.9],
        array $additionalComponents = [],
    ): ElectricityContract {
        $relationalComponents = [];

        foreach ($components as $type => $price) {
            $relationalComponents[] = [
                'id' => $id.'-'.strtolower($type),
                'price_component_type' => $type,
                'price_date' => now()->format('Y-m-d'),
                'price' => $price,
                'payment_unit' => $type === 'Monthly' ? 'EUR/month' : 'c/kWh',
            ];
        }

        $factory = ElectricityContract::factory()
            ->forCompany($this->company)
            ->legacy()
            ->active();

        if ($relationalComponents !== [] || $additionalComponents !== []) {
            $factory = $factory->withRelationalPrices([
                ...$relationalComponents,
                ...$additionalComponents,
            ]);
        }

        return $factory->create([
            'id' => $id,
            'name' => 'Testisopimus '.$id,
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'order_link' => 'https://testienergia.fi/tilaa',
            ...$attributes,
        ]);
    }

    /**
     * @param  list<array{0: ComponentType, 1: float|null, 2?: ComponentUnit, 3?: float|null, 4?: PriceRole}>  $components
     * @return array<string, mixed>
     */
    private function phase(
        PhaseKind $kind,
        BoundaryKind $starts,
        BoundaryKind $ends,
        array $components,
        ?string $startsValue = null,
        ?string $endsValue = null,
    ): array {
        return CanonicalPricingFixture::phase(
            label: $kind->value,
            kind: $kind,
            starts: CanonicalPricingFixture::boundary($starts, $startsValue),
            ends: CanonicalPricingFixture::boundary($ends, $endsValue),
            components: array_map(
                fn (array $component): array => CanonicalPricingFixture::component(
                    type: $component[0],
                    amount: $component[1],
                    unit: $component[2] ?? ComponentUnit::CentsPerKwh,
                    priceRole: $component[4] ?? PriceRole::Current,
                    normalAmount: $component[3] ?? null,
                ),
                $components,
            ),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<string>  $issueCodes
     * @return array<string, mixed>
     */
    private function canonicalAttributes(
        array $phases,
        CalculationStatus $status = CalculationStatus::Exact,
        ?array $recurringSchedule = null,
        ?array $consumptionEffect = null,
        MisleadingState $misleading = MisleadingState::NotDetected,
        string $structuredPricingStatus = 'complete',
        array $issueCodes = [],
    ): array {
        return CanonicalPricingFixture::attributes(
            phases: $phases,
            calculationStatus: $status,
            recurringSchedule: $recurringSchedule,
            consumptionEffect: $consumptionEffect,
            misleading: $misleading,
            structuredPricingStatus: $structuredPricingStatus,
            issueCodes: $issueCodes,
        );
    }

    // ------------------------------------------------------------------- category band

    public function test_the_page_states_the_same_pricing_category_as_the_card(): void
    {
        $fixed = $this->contract('band-fixed');
        Livewire::test('contract-detail', ['contractId' => $fixed->id])
            ->assertSee('Energian hinta ei muutu')
            // The fixed band is deliberately slate: certainty is the default state.
            ->assertSeeHtml('bg-slate-100 text-slate-700 border-slate-200');

        $spot = $this->contract('band-spot', ['pricing_model' => 'Spot'], ['General' => 0.45, 'Monthly' => 4.99]);
        Livewire::test('contract-detail', ['contractId' => $spot->id])
            ->assertSee('Hinta seuraa pörssin tuntihintaa')
            ->assertSeeHtml('bg-sky-100 text-sky-700 border-sky-200');

        $hybrid = $this->contract('band-hybrid', ['pricing_model' => 'Hybrid'], ['General' => 6.1, 'Monthly' => 3.9]);
        Livewire::test('contract-detail', ['contractId' => $hybrid->id])
            ->assertSee('Kiinteä hinta + kulutusvaikutus')
            ->assertSeeHtml('bg-violet-100 text-violet-700 border-violet-200');
    }

    // ------------------------------------------------------------------- receipt rows

    public function test_a_hybrid_states_its_consumption_effect_instead_of_a_zero_energy_price(): void
    {
        // The page used to print "Energiahinta 0,00 c/kWh" here, with no effect row at all.
        $contract = $this->contract('effect-contract', [
            'pricing_model' => 'Hybrid',
            ...$this->canonicalAttributes(
                phases: [],
                status: CalculationStatus::Unsupported,
                consumptionEffect: CanonicalPricingFixture::consumptionEffect(
                    appliesTo: 'base_contract',
                    cadence: 'none',
                    expectedCentsPerKwh: null,
                    typicalMinCentsPerKwh: null,
                    typicalMaxCentsPerKwh: null,
                    hardMinCentsPerKwh: null,
                    hardMaxCentsPerKwh: null,
                    uncapped: null,
                ),
            ),
        ], ['General' => 8.59, 'Monthly' => 0.0]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Perushinta')
            ->assertSee('Kulutusvaikutus')
            ->assertSee('± käyttöajan mukaan');
    }

    public function test_a_spot_contract_labels_its_margin_and_its_market_baseline(): void
    {
        $contract = $this->contract('spot-contract', ['pricing_model' => 'Spot'], ['General' => 0.45, 'Monthly' => 4.99]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Pörssin toteutunut päiväkeskiarvo 12 kk')
            ->assertSee('Marginaali')
            // The old block computed "Energiahinta (arvio) (spot + marginaali)" here.
            ->assertDontSee('spot + marginaali');
    }

    public function test_canonical_current_values_win_over_relational_values_on_all_detail_surfaces(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $contract = $this->contract('canonical-conflict', $this->canonicalAttributes([
            $this->phase(PhaseKind::CurrentStructured, BoundaryKind::ContractStart, BoundaryKind::None, [
                [ComponentType::EnergyGeneral, 8.4],
                [ComponentType::MonthlyFee, 4.2, ComponentUnit::EurPerMonth],
            ]),
        ]), ['General' => 1.11, 'Monthly' => 0.55], [[
            'id' => 'canonical-conflict-general-old',
            'price_component_type' => 'General',
            'price_date' => now()->subMonth()->format('Y-m-d'),
            'price' => 2.22,
            'payment_unit' => 'c/kWh',
        ]]);

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();
        $receiptValues = array_map(fn ($line) => $line->value, $component->card->receiptLines);
        $offers = collect($component->productSchema['offers'] ?? [])->keyBy('name');

        $this->assertSame(['8,40', '8,40', '4,20'], $receiptValues);
        $this->assertStringContainsString('8,40 c/kWh', $component->pageTitle);
        $this->assertStringContainsString('maksaa nyt 8,40 c/kWh + 4,20 €/kk', $component->metaDescription);
        $this->assertSame(8.4, $offers['Energiahinta']['priceSpecification']['price']);
        $this->assertSame(4.2, $offers['Perusmaksu']['priceSpecification']['price']);

        // Historical surfaces remain the observed relational record.
        $this->assertSame(1.11, $component->priceHistory['General'][0]['price']);
        $this->assertContains(1.11, collect($component->contractHistory[0]['prices'])->pluck('price')->all());
    }

    public function test_canonical_missing_unit_value_is_omitted_instead_of_filled_from_relational_data(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $contract = $this->contract('canonical-missing-unit', $this->canonicalAttributes([
            $this->phase(PhaseKind::CurrentStructured, BoundaryKind::ContractStart, BoundaryKind::None, [
                [ComponentType::MonthlyFee, 4.2, ComponentUnit::EurPerMonth],
            ]),
        ]), ['General' => 1.11, 'Monthly' => 0.55]);

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();
        $offers = collect($component->productSchema['offers'] ?? [])->keyBy('name');

        $this->assertSame(['Perusmaksu'], array_map(fn ($line) => $line->label, $component->card->receiptLines));
        $this->assertStringNotContainsString('1,11 c/kWh', $component->pageTitle);
        $this->assertStringNotContainsString('maksaa nyt 1,11 c/kWh', $component->metaDescription);
        $this->assertFalse($offers->has('Energiahinta'));
        $this->assertSame(4.2, $offers['Perusmaksu']['priceSpecification']['price']);
    }

    public function test_a_canonical_package_labels_its_included_energy_and_excess_rate_without_an_ordinary_energy_price(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $phase = CanonicalPricingFixture::packagePhase(
            label: PhaseKind::CurrentStructured->value,
            kind: PhaseKind::CurrentStructured,
            starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
            ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
            monthlyFeeEur: 21.0,
            includedKwh: 150.0,
            allowanceCadence: AllowanceCadence::Monthly,
            excessRateCentsPerKwh: 16.6,
        );
        $contract = $this->contract(
            'canonical-package',
            $this->canonicalAttributes([$phase]),
            ['General' => 1.11, 'Monthly' => 0.55],
        );

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();
        $offers = collect($component->productSchema['offers'] ?? [])->keyBy('name');
        $additional = collect($component->productSchema['additionalProperty'] ?? [])->keyBy('name');
        $mechanism = collect($component->faqItems)->firstWhere('id', 'faq-miten');

        $this->assertEqualsWithDelta(783.2, $component->calculatedCost['total_cost'], 0.001);
        $this->assertSame(
            ['Kuukausipaketti', 'Sisältää', 'Ylittävä kulutus'],
            array_map(fn ($line) => $line->label, $component->card->receiptLines),
        );
        $this->assertStringNotContainsString('16,60 c/kWh', $component->pageTitle);
        $this->assertStringNotContainsString('maksaa nyt 16,60 c/kWh', $component->metaDescription);
        $this->assertFalse($offers->has('Energiahinta'));
        $this->assertSame(16.6, $offers['Ylittävä kulutus']['priceSpecification']['price']);
        $this->assertSame(21.0, $offers['Perusmaksu']['priceSpecification']['price']);
        $this->assertSame(150.0, $additional['includedEnergyPerMonth']['value']);
        $this->assertStringContainsString('Kuukausimaksu 21,00 €/kk sisältää 150 kWh', $component->priceQualifier);
        $this->assertSame('Miten kuukausipaketin hinta muodostuu?', $mechanism['question']);
        $this->assertStringContainsString('ylittävä kulutus maksaa 16,60 c/kWh', $mechanism['answer']);
    }

    public function test_a_canonical_only_contract_emits_its_available_current_values(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $contract = $this->contract('canonical-only', $this->canonicalAttributes([
            $this->phase(PhaseKind::CurrentStructured, BoundaryKind::ContractStart, BoundaryKind::None, [
                [ComponentType::EnergyGeneral, 7.35],
                [ComponentType::MonthlyFee, 3.25, ComponentUnit::EurPerMonth],
            ]),
        ]), []);

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();
        $offers = collect($component->productSchema['offers'] ?? [])->keyBy('name');

        $this->assertSame(['7,35', '7,35', '3,25'], array_map(fn ($line) => $line->value, $component->card->receiptLines));
        $this->assertStringContainsString('7,35 c/kWh', $component->pageTitle);
        $this->assertSame(7.35, $offers['Energiahinta']['priceSpecification']['price']);
        $this->assertSame(3.25, $offers['Perusmaksu']['priceSpecification']['price']);
    }

    public function test_an_excluded_contract_emits_no_current_unit_value_or_json_ld_offer(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $contract = $this->contract('canonical-excluded', $this->canonicalAttributes(
            phases: [],
            status: CalculationStatus::Incomplete,
        ), [
            'General' => 1.11,
            'Monthly' => 0.55,
        ]);

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();

        $this->assertTrue($component->isPricingExcluded);
        $this->assertSame([], $component->card->receiptLines);
        $this->assertStringNotContainsString('1,11 c/kWh', $component->pageTitle);
        $this->assertStringNotContainsString('maksaa nyt 1,11 c/kWh', $component->metaDescription);
        $this->assertArrayNotHasKey('offers', $component->productSchema);
    }

    public function test_feature_off_detail_surfaces_keep_relational_current_values(): void
    {
        config(['canonical_pricing.enabled' => false]);

        $contract = $this->contract('legacy-current-value', $this->canonicalAttributes([
            $this->phase(PhaseKind::CurrentStructured, BoundaryKind::ContractStart, BoundaryKind::None, [
                [ComponentType::EnergyGeneral, 8.4],
                [ComponentType::MonthlyFee, 4.2, ComponentUnit::EurPerMonth],
            ]),
        ]), ['General' => 1.11, 'Monthly' => 0.55]);

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();
        $offers = collect($component->productSchema['offers'] ?? [])->keyBy('name');

        $this->assertSame(['1,11', '0,55'], array_map(fn ($line) => $line->value, $component->card->receiptLines));
        $this->assertStringContainsString('1,11 c/kWh', $component->pageTitle);
        $this->assertSame(1.11, $offers['Energiahinta']['priceSpecification']['price']);
    }

    public function test_six_month_detail_copy_uses_the_real_term_benefit_not_the_annualized_saving(): void
    {
        $this->travelTo('2026-08-01 12:00:00');
        config(['canonical_pricing.enabled' => true]);

        $contract = $this->contract('canonical-six-month', array_merge(
            [
                'contract_type' => 'FixedTerm',
                'fixed_time_range' => 'Fixed6',
            ],
            $this->canonicalAttributes([
                $this->phase(
                    PhaseKind::Introductory,
                    BoundaryKind::ContractStart,
                    BoundaryKind::AfterMonths,
                    [
                        [ComponentType::EnergyGeneral, 7.0],
                        [ComponentType::MonthlyFee, 5.0, ComponentUnit::EurPerMonth, 10.0, PriceRole::Introductory],
                    ],
                    endsValue: '6',
                ),
            ]),
        ), ['General' => 1.11, 'Monthly' => 0.55]);

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();
        $notes = implode(' ', $component->receiptNotes);

        $this->assertSame(60.0, $component->calculatedCost['discount_savings_total']);
        $this->assertSame(30.0, $component->calculatedCost['contract_term']['discount_savings_total']);
        $this->assertStringContainsString('30 € 6 kuukauden sopimuskauden aikana', $notes);
        $this->assertStringNotContainsString('60 € ensimmäisenä vuonna', $notes);
    }

    public function test_a_promotional_flat_price_before_a_spot_margin_shows_two_dated_rows(): void
    {
        // Cheap Markkinahintasähkö: one flat month at 6,99 c/kWh with no monthly fee, then
        // the market's monthly average + 1,29 c/kWh with a 4,99 EUR/kk fee. The page hard-
        // labelled the relational General component "Marginaali", so it printed
        // "Marginaali 6,99" above the seller's own text saying the margin is 1,29.
        config(['canonical_pricing.enabled' => true]);

        $contract = $this->contract('promo-then-spot', [
            'pricing_model' => 'Spot',
            ...$this->canonicalAttributes(
                phases: [
                    $this->phase(
                        PhaseKind::Introductory,
                        BoundaryKind::ContractStart,
                        BoundaryKind::AfterMonths,
                        [
                            [ComponentType::EnergyGeneral, 6.99],
                            [ComponentType::MonthlyFee, 0.0, ComponentUnit::EurPerMonth],
                        ],
                        endsValue: '1',
                    ),
                    $this->phase(
                        PhaseKind::Continuation,
                        BoundaryKind::AfterMonths,
                        BoundaryKind::None,
                        [
                            [ComponentType::SpotMargin, 1.29],
                            [ComponentType::MonthlyFee, 4.99, ComponentUnit::EurPerMonth],
                        ],
                        startsValue: '1',
                    ),
                ],
                status: CalculationStatus::EstimateRequired,
            ),
        ], ['General' => 6.99, 'Monthly' => 0.0]);

        $switchDay = now('Europe/Helsinki')->startOfDay()->addMonth();
        $lastPromoDay = $switchDay->copy()->subDay();

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Energia '.$lastPromoDay->format('j.n.').' asti')
            ->assertSee('Marginaali '.$switchDay->format('j.n.').' alkaen')
            ->assertSee('Perusmaksu '.$switchDay->format('j.n.').' alkaen')
            ->assertSee('6,99')
            ->assertSee('1,29')
            ->assertSee('4,99')
            ->assertDontSee('Marginaali 6,99');
    }

    public function test_a_market_reset_shows_the_known_period_price_above_the_estimated_tail(): void
    {
        config([
            'canonical_pricing.enabled' => true,
            'canonical_pricing.reset_forward_shift.enabled' => true,
            'price_forecasting.fixed_term.vat_multiplier' => 1.255,
        ]);

        $periodEnd = now('Europe/Helsinki')->startOfDay()->addMonths(2)->endOfMonth()->startOfDay();
        $tradeDate = now('Europe/Helsinki')->subDay()->format('Y-m-d');

        // A rising forward curve, so the estimated tail is visibly above the current price.
        $rows = [];
        foreach (['month', 'quarter', 'year'] as $type) {
            for ($i = 0; $i < 16; $i++) {
                $delivery = now('Europe/Helsinki')->startOfMonth()->addMonths($i);
                $maturity = match ($type) {
                    'month' => $delivery->format('Ym'),
                    'quarter' => $delivery->copy()->firstOfQuarter()->format('Ym'),
                    default => $delivery->format('Y').'01',
                };

                // Quarter and year maturities repeat across delivery months; keep the first.
                $rows[$type.$maturity] ??= [
                    'short_code' => match ($type) {
                        'month' => 'FNBM', 'quarter' => 'FNBQ', default => 'FNBY'
                    },
                    'maturity' => $maturity,
                    'maturity_type' => $type,
                    'settlement_price' => 30.0 + ($i * 4),
                ];
            }
        }

        foreach ($rows as $row) {
            ElectricityFuturesEodPrice::create(array_merge([
                'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base', 'area' => 'FI',
                'trade_date' => $tradeDate,
            ], $row));
        }

        $contract = $this->contract('reset-contract', [
            ...$this->canonicalAttributes(
                phases: [
                    $this->phase(PhaseKind::RecurringPeriod, BoundaryKind::ContractStart, BoundaryKind::None, [
                        [ComponentType::EnergyGeneral, 8.0],
                        [ComponentType::MonthlyFee, 2.53, ComponentUnit::EurPerMonth],
                    ]),
                ],
                status: CalculationStatus::EstimateRequired,
                recurringSchedule: CanonicalPricingFixture::recurringSchedule(
                    cadence: 'quarterly',
                    currentPeriodStart: now('Europe/Helsinki')->startOfQuarter()->format('Y-m-d'),
                    currentPeriodEnd: $periodEnd->format('Y-m-d'),
                    futurePriceKnown: false,
                ),
                issueCodes: ['recurring_reset_requires_estimate'],
            ),
        ], ['General' => 8.0, 'Monthly' => 2.53]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Energia nyt, '.$periodEnd->format('j.n.').' asti')
            ->assertSee('Loppuvuosi, arvio')
            ->assertSee('Hinta tarkistetaan neljännesvuosittain');
    }

    public function test_supplier_adjusted_detail_keeps_current_facts_separate_and_shows_arvio_on_hold_flat(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();

        $contract = $this->contract('supplier-adjusted-detail', $this->canonicalAttributes([
            $this->phase(PhaseKind::CurrentStructured, BoundaryKind::ContractStart, BoundaryKind::None, [
                [ComponentType::EnergyGeneral, 7.4],
                [ComponentType::MonthlyFee, 4.2, ComponentUnit::EurPerMonth],
            ]),
        ]), ['General' => 1.11, 'Monthly' => 0.55]);

        $test = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Arvio')
            ->assertSee('Energia nyt')
            ->assertSee('12 kk keskihinta, arvio')
            ->assertSee('Nykyinen energianhinta on kiinteä')
            ->assertDontSee('Energian hinta ei muutu');
        $component = $test->instance();

        $this->assertSame('hold_current_supplier_price', $component->calculatedCost['estimate_method']);
        $this->assertSame('hold_flat', $component->calculatedCost['supplier_adjusted_estimate']['basis']);
        $this->assertNotNull($component->card->estimate);
        $this->assertNotSame('', trim($component->card->estimate->body));
        $this->assertSame(
            ['Energia nyt', '12 kk keskihinta, arvio', 'Perusmaksu'],
            array_map(fn ($line) => $line->label, $component->card->receiptLines),
        );
        $this->assertStringContainsString('Nykyinen energianhinta 7,40 c/kWh on myyjän julkaisema hinta.', $component->priceQualifier);
        $this->assertStringContainsString('Vertailun 12 kuukauden vastaava keskihinta 7,40 c/kWh on Voltikan arvio.', $component->priceQualifier);
        $this->assertCount(1, $component->receiptNotes);
        $this->assertStringContainsString('Tulevia energiahintoja tai niiden muutosaikataulua ei tiedetä', $component->receiptNotes[0]);

        $publicCopy = implode(' ', [
            $component->card->estimate->body,
            $component->card->band->headline,
            $component->card->band->detail,
            $component->priceQualifier,
            ...$component->receiptNotes,
        ]);
        $this->assertDoesNotMatchRegularExpression('/kuukausittain|neljännesvuosittain|kausittain|hinta tarkistetaan/ui', $publicCopy);
    }

    public function test_named_supplier_adjusted_examples_show_the_correct_band_arvio_and_exact_tariffs(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();

        $examples = [
            [
                'akhbwv-parikkalan-valo-oy-q-valo',
                'Q-Valo',
                'General',
                BoundaryKind::Unknown,
                [
                    [ComponentType::EnergyGeneral, 7.53],
                    [ComponentType::MonthlyFee, 4.65, ComponentUnit::EurPerMonth],
                ],
                ['Energia nyt', '12 kk keskihinta, arvio', 'Perusmaksu'],
                'Nykyinen energianhinta 7,53 c/kWh on myyjän julkaisema hinta.',
            ],
            [
                'gxeryx-parikkalan-valo-oy-kesto-valo-kanta-asiakas',
                'Kesto Valo kanta-asiakas',
                'Time',
                BoundaryKind::ContractStart,
                [
                    [ComponentType::EnergyDay, 8.0],
                    [ComponentType::EnergyNight, 4.0],
                    [ComponentType::MonthlyFee, 4.65, ComponentUnit::EurPerMonth],
                ],
                ['Päivä', 'Yö', '12 kk keskihinta, arvio', 'Perusmaksu'],
                'Nykyiset päivä- ja yöhinnat 8,00 ja 4,00 c/kWh ovat myyjän julkaisemia hintoja.',
            ],
            [
                'jrrlvh-parikkalan-valo-oy-kesto-valo-kanta-asiakas',
                'Kesto Valo kanta-asiakas',
                'Season',
                BoundaryKind::ContractStart,
                [
                    [ComponentType::EnergySeasonalWinter, 12.0],
                    [ComponentType::EnergySeasonalOther, 4.0],
                    [ComponentType::MonthlyFee, 4.65, ComponentUnit::EurPerMonth],
                ],
                ['Talvi', 'Muu aika', '12 kk keskihinta, arvio', 'Perusmaksu'],
                'Nykyiset talvi- ja muun ajan hinnat 12,00 ja 4,00 c/kWh ovat myyjän julkaisemia hintoja.',
            ],
        ];

        foreach ($examples as [$id, $name, $metering, $starts, $components, $receiptLabels, $qualifier]) {
            $contract = $this->contract($id, [
                'name' => $name,
                'metering' => $metering,
                ...$this->canonicalAttributes([
                    $this->phase(PhaseKind::CurrentStructured, $starts, BoundaryKind::None, $components),
                ]),
            ]);

            $test = Livewire::test('contract-detail', ['contractId' => $contract->id])
                ->assertSee('Arvio')
                ->assertSee('Nykyinen energianhinta on kiinteä')
                ->assertSee('Myyjä voi muuttaa hintaa ilmoittamalla siitä')
                ->assertDontSee('Energian hinta ei muutu');
            $component = $test->instance();

            $this->assertSame('comparable_estimate', $component->calculatedCost['comparability'], $id);
            $this->assertNotNull($component->calculatedCost['supplier_adjusted_estimate'], $id);
            $this->assertSame($receiptLabels, array_map(fn ($line) => $line->label, $component->card->receiptLines), $id);
            $this->assertStringContainsString($qualifier, $component->priceQualifier, $id);
            $this->assertStringContainsString('Voltikan arvio', $component->priceQualifier, $id);
        }
    }

    // ------------------------------------------------------------------- warnings and CTA

    public function test_a_consumption_cap_warns_on_the_page_and_not_only_on_the_card(): void
    {
        $contract = $this->contract('capped-contract', [
            'consumption_limitation_max_x_kwh_per_y' => 12000,
        ]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Max 12 000 kWh/v');
    }

    public function test_a_scheduled_price_increase_warns_on_the_page(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $change = now('Europe/Helsinki')->startOfDay()->addMonths(3);

        $contract = $this->contract('rising-contract', [
            ...$this->canonicalAttributes(
                phases: [
                    $this->phase(
                        PhaseKind::Introductory,
                        BoundaryKind::ContractStart,
                        BoundaryKind::Date,
                        [
                            [ComponentType::EnergyGeneral, 5.49],
                            [ComponentType::MonthlyFee, 2.99, ComponentUnit::EurPerMonth],
                        ],
                        endsValue: $change->copy()->subDay()->format('Y-m-d'),
                    ),
                    $this->phase(
                        PhaseKind::Normal,
                        BoundaryKind::Date,
                        BoundaryKind::None,
                        [
                            [ComponentType::EnergyGeneral, 13.65],
                            [ComponentType::MonthlyFee, 2.99, ComponentUnit::EurPerMonth],
                        ],
                        startsValue: $change->format('Y-m-d'),
                    ),
                ],
                misleading: MisleadingState::Detected,
                issueCodes: ['structured_matches_intro_only'],
            ),
        ], ['General' => 5.49, 'Monthly' => 2.99]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Hinta nousee '.$change->format('j.n.Y'))
            // Both dated prices back the warning up in the receipt.
            ->assertSee('Energia '.$change->copy()->subDay()->format('j.n.').' asti')
            ->assertSee('Energia '.$change->format('j.n.').' alkaen');
    }

    public function test_every_active_contract_offers_a_way_to_the_seller(): void
    {
        $withOrderLink = $this->contract('cta-order', [
            'order_link' => 'https://testienergia.fi/tilaa?offer=green&utm_campaign=old#checkout',
        ]);
        Livewire::test('contract-detail', ['contractId' => $withOrderLink->id])
            ->assertSee('Siirry myyjän sivuille')
            ->assertSeeHtml('href="https://testienergia.fi/tilaa?offer=green&amp;utm_source=voltikka.fi&amp;utm_medium=referral&amp;utm_campaign=voltikka_sahkovertailu#checkout"')
            ->assertSee('Tilaus tehdään suoraan sähköyhtiön sivuilla');

        // One live contract carried neither an order link nor a product link, and its page
        // rendered no call to action at all. It falls back to the seller's own site.
        $withNeither = $this->contract('cta-none', ['order_link' => null, 'product_link' => null]);
        Livewire::test('contract-detail', ['contractId' => $withNeither->id])
            ->assertSee('Siirry myyjän sivuille')
            ->assertSeeHtml('href="https://testienergia.fi?utm_source=voltikka.fi&amp;utm_medium=referral&amp;utm_campaign=voltikka_sahkovertailu"');

        $this->company->update(['company_url' => null]);
        $internalFallback = $this->contract('cta-internal', ['order_link' => null, 'product_link' => null]);
        Livewire::test('contract-detail', ['contractId' => $internalFallback->id])
            ->assertSee('Katso myyjän tiedot')
            ->assertSeeHtml('href="/sahkosopimus/sahkoyhtiot/testi-energia-oy"')
            ->assertDontSee('utm_source');
    }

    // ------------------------------------------------------------------- name normalization

    public function test_a_shouted_name_is_normalized_on_the_alternative_contract_tiles(): void
    {
        $viewed = $this->contract('name-viewed', [], ['General' => 12.0, 'Monthly' => 9.9]);
        $this->contract('name-cheaper', ['name' => 'Halpa SÄHKÖSOPIMUS TARJOUS'], ['General' => 3.0, 'Monthly' => 1.0]);

        Livewire::test('contract-detail', ['contractId' => $viewed->id])
            ->assertSee('Halpa Sähkösopimus tarjous')
            ->assertDontSee('Halpa SÄHKÖSOPIMUS TARJOUS');
    }
}
