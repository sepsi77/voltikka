<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\WeeklyOffersPromptFormatter;
use App\Services\WeeklyOffersVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WeeklyOffersCanonicalPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Alpha Energy Oy', 'Beta Energy Oy', 'Other Energy Oy'] as $name) {
            Company::create([
                'name' => $name,
                'name_slug' => str($name)->slug()->toString(),
                'company_url' => 'https://example.test',
            ]);
        }
    }

    public function test_canonical_api_uses_only_safe_measured_offers_and_canonical_values_in_a_bounded_batch(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $conflict = $this->createContract(
            'canonical-conflict',
            'Alpha Energy Oy',
            'Canonical conflict',
            $this->offerPhase(8.0, 10.0, 5.0, 10.0),
        );
        $this->createRelationalDiscount($conflict, price: 1.0, discount: 99.0);

        $this->createContract(
            'same-company-lower',
            'Alpha Energy Oy',
            'Same company lower benefit',
            $this->offerPhase(8.0, 8.0, 5.0, 9.0),
        );
        $this->createContract(
            'canonical-only',
            'Beta Energy Oy',
            'Canonical only',
            $this->offerPhase(9.0, 10.0, 0.0, 0.0),
        );

        $relationalOnly = $this->createContract(
            'relational-only',
            'Other Energy Oy',
            'Relational only',
            $this->plainPhase(),
        );
        $this->createRelationalDiscount($relationalOnly, price: 2.0, discount: 77.0);

        $excluded = $this->createContract(
            'excluded-deceptive',
            'Other Energy Oy',
            'Excluded deceptive',
            $this->offerPhase(2.0, 9.0, 0.0, 5.0, $this->boundary('after_months', '1')),
            calculationStatus: 'estimate_required',
            misleading: 'detected',
            issues: ['future_price_unknown'],
        );
        $this->createRelationalDiscount($excluded, price: 0.5, discount: 66.0);

        $this->createContract(
            'unsafe-listed',
            'Other Energy Oy',
            'Unsafe listed',
            $this->offerPhase(),
            misleading: 'detected',
            issues: ['other'],
        );
        $this->createContract(
            'package',
            'Other Energy Oy',
            'Monthly package',
            $this->packagePhase(),
            pricingHasDiscounts: true,
        );

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson('/api/video/weekly-offers');

        $response->assertOk()
            ->assertJsonPath('data.pricing_basis', 'canonical')
            ->assertJsonPath('data.offers_count', 2)
            ->assertJsonPath('data.offers.0.id', 'canonical-conflict')
            ->assertJsonPath('data.offers.1.id', 'canonical-only')
            ->assertJsonPath('data.offers.0.pricing_basis', 'canonical')
            ->assertJsonPath('data.offers.0.comparability', 'comparable_exact')
            ->assertJsonPath('data.offers.0.pricing.monthly_fee', 5)
            ->assertJsonPath('data.offers.0.pricing.general_kwh_price', 8)
            ->assertJsonPath('data.offers.0.consumptions.apartment.total_cost', 220)
            ->assertJsonPath('data.offers.0.consumptions.apartment.normal_total_cost', 320)
            ->assertJsonPath('data.offers.0.consumptions.apartment.avg_monthly_cost', 18.33)
            ->assertJsonPath('data.offers.0.consumptions.apartment.customer_benefit_eur', 100)
            ->assertJsonPath('data.offers.0.consumptions.townhouse.total_cost', 460)
            ->assertJsonPath('data.offers.0.consumptions.townhouse.normal_total_cost', 620)
            ->assertJsonPath('data.offers.0.consumptions.townhouse.customer_benefit_eur', 160)
            ->assertJsonPath('data.offers.0.consumptions.house.total_cost', 860)
            ->assertJsonPath('data.offers.0.consumptions.house.normal_total_cost', 1120)
            ->assertJsonPath('data.offers.0.consumptions.house.customer_benefit_eur', 260)
            ->assertJsonPath('data.offers.0.consumptions.townhouse.total_basis', 'first_12_months')
            ->assertJsonPath('data.offers.0.consumptions.townhouse.availability', 'available')
            ->assertJsonPath('data.offers.0.selection.metric', 'measured_customer_benefit')
            ->assertJsonPath('data.offers.0.selection.consumption_kwh', 5000)
            ->assertJsonPath('data.offers.0.selection.measured_customer_benefit_eur', 160)
            ->assertJsonMissingPath('data.offers.0.discount')
            ->assertJsonMissingPath('data.offers.0.costs')
            ->assertJsonMissingPath('data.offers.0.savings');

        $ids = collect($response->json('data.offers'))->pluck('id')->all();
        $this->assertSame(['canonical-conflict', 'canonical-only'], $ids);
        $this->assertLessThanOrEqual(7, count($queries), implode("\n", $queries));
        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'price_components'),
        )));
    }

    public function test_short_fixed_term_payload_and_prompt_use_the_real_term_benefit(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $contract = $this->createContract(
            'six-month',
            'Alpha Energy Oy',
            'Six month offer',
            $this->offerPhase(8.0, 8.0, 5.0, 10.0, $this->boundary('after_months', '6')),
            calculationStatus: 'incomplete',
            misleading: 'detected',
            issues: ['future_price_omitted', 'future_price_unknown'],
            contractType: 'FixedTerm',
            fixedTimeRange: 'Fixed6',
        );
        $this->createRelationalDiscount($contract, price: 1.0, discount: 99.0);

        $data = app(WeeklyOffersVideoService::class)->getWeeklyOffersData();
        $offer = $data['offers'][0];
        $townhouse = $offer['consumptions']['townhouse'];

        $this->assertSame('annualized_contract_term', $townhouse['total_basis']);
        $this->assertEqualsWithDelta(460.0, $townhouse['total_cost'], 0.01);
        $this->assertEqualsWithDelta(520.0, $townhouse['normal_total_cost'], 0.01);
        $this->assertEqualsWithDelta(60.0, $townhouse['comparison_measured_saving'], 0.01);
        $this->assertEqualsWithDelta(30.0, $townhouse['customer_benefit_eur'], 0.01);
        $this->assertSame(6, $townhouse['customer_benefit_basis_months']);
        $this->assertEqualsWithDelta(230.0, $townhouse['contract_term']['total_cost'], 0.01);
        $this->assertEqualsWithDelta(260.0, $townhouse['contract_term']['normal_total_cost'], 0.01);
        $this->assertEqualsWithDelta(30.0, $townhouse['contract_term']['measured_saving'], 0.01);

        $prompt = app(WeeklyOffersPromptFormatter::class)->formatPrompt($data);

        $this->assertStringContainsString('Mitattu tarjousetu:** 30,00 € / 6 kk (6 kuukauden sopimuskaudella)', $prompt);
        $this->assertStringContainsString('Vuositasolle muunnettu vertailuhinta', $prompt);
        $this->assertStringContainsString('todellinen 6 kk sopimuskausi 230,00 €', $prompt);
        $this->assertStringNotContainsString('60,00 € / 12 kk', $prompt);
        $this->assertStringNotContainsString('99,00', $prompt);
        $this->assertStringNotContainsString('c/kWh alennus', $prompt);
    }

    public function test_feature_off_keeps_the_legacy_relational_weekly_offer_payload(): void
    {
        config()->set('canonical_pricing.enabled', false);

        $this->createContract(
            'canonical-only',
            'Alpha Energy Oy',
            'Canonical only',
            $this->offerPhase(),
        );
        $legacy = $this->createContract(
            'legacy-offer',
            'Beta Energy Oy',
            'Legacy offer',
            $this->plainPhase(),
        );
        $this->createRelationalDiscount($legacy, price: 8.0, discount: 2.0);

        $response = $this->getJson('/api/video/weekly-offers');

        $response->assertOk()
            ->assertJsonPath('data.pricing_basis', 'legacy_relational')
            ->assertJsonPath('data.offers_count', 1)
            ->assertJsonPath('data.offers.0.id', 'legacy-offer')
            ->assertJsonPath('data.offers.0.discount.value', 2)
            ->assertJsonPath('data.offers.0.pricing.energy_price', 8)
            ->assertJsonStructure([
                'data' => [
                    'offers' => [[
                        'costs' => ['apartment', 'townhouse', 'house'],
                        'savings' => ['apartment', 'townhouse', 'house'],
                    ]],
                ],
            ])
            ->assertJsonMissingPath('data.offers.0.consumptions');

        $legacyData = app(WeeklyOffersVideoService::class)->getWeeklyOffersData();
        $legacyPrompt = app(WeeklyOffersPromptFormatter::class)->formatPrompt($legacyData);
        $this->assertStringContainsString('Yhtiöiden nimet ja alennusprosentit', $legacyPrompt);
        $this->assertStringContainsString('**Alennus:**', $legacyPrompt);
        $this->assertStringContainsString('**Vuosikustannukset alennuksella:**', $legacyPrompt);
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<string>  $issues
     */
    private function createContract(
        string $id,
        string $company,
        string $name,
        array $phases,
        string $calculationStatus = 'exact',
        string $misleading = 'not_detected',
        array $issues = [],
        string $contractType = 'OpenEnded',
        ?string $fixedTimeRange = null,
        bool $pricingHasDiscounts = false,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $company,
            'name' => $name,
            'contract_type' => $contractType,
            'fixed_time_range' => $fixedTimeRange,
            'pricing_model' => 'FixedPrice',
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
                'status' => $calculationStatus,
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => $misleading,
                'structured_pricing_status' => 'complete',
                'issue_codes' => $issues,
            ],
        ]);

        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    private function createRelationalDiscount(ElectricityContract $contract, float $price, float $discount): void
    {
        PriceComponent::create([
            'id' => 'price-'.$contract->id,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->toDateString(),
            'price' => $price,
            'payment_unit' => 'CentPerKiwattHour',
            'has_discount' => true,
            'discount_value' => $discount,
            'discount_is_percentage' => false,
            'discount_discount_n_first_months' => 3,
        ]);
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
            'label' => 'offer',
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
}
