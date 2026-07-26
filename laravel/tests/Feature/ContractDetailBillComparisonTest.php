<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Vertaa nykyiseen sähkölaskuusi" on the contract detail page.
 *
 * One bill, one contract, the same billing period. Period basis only: the bill
 * total is the anchor and no annual figure is derived from it, exactly as in the
 * in-listing mode. See `app/Services/BillComparison/AGENTS.md`.
 */
class ContractDetailBillComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
        ]);
    }

    /**
     * General 5,00 c/kWh + 3,00 €/kk. For a 30-day period with 300 kWh the
     * period cost is exactly 5,00 c x 300 kWh + 3,00 € = 18,00 €.
     */
    private function createContract(
        string $id,
        string $name,
        float $generalCents,
        ?float $monthlyEur = null,
        string $pricingModel = 'FixedPrice',
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Test Energia Oy',
            'name' => $name,
            'name_slug' => \Illuminate\Support\Str::slug($name),
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => 'Household',
            'availability_is_national' => true,
            'order_link' => 'https://testenergia.fi/order/'.$id,
            'canonical_pricing' => [
                'recurring_schedule' => ['present' => false],
                'consumption_effect' => ['present' => false],
            ],
        ]);

        ActiveContract::create(['id' => $id]);

        PriceComponent::create([
            'id' => 'pc-general-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $generalCents,
            'payment_unit' => 'c/kWh',
        ]);

        if ($monthlyEur !== null) {
            PriceComponent::create([
                'id' => 'pc-monthly-'.$id,
                'electricity_contract_id' => $id,
                'price_component_type' => 'Monthly',
                'price_date' => now()->format('Y-m-d'),
                'price' => $monthlyEur,
                'payment_unit' => 'EUR/month',
            ]);
        }

        return $contract->refresh();
    }

    /**
     * A 30-day period keeps months-in-period exactly 1, so the assertions stay
     * deterministic.
     */
    private function billComponent(string $contractId, float $totalEur, float $kwh = 300)
    {
        return Livewire::test('contract-detail', ['contractId' => $contractId])
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', $kwh)
            ->set('billTotalEur', $totalEur);
    }

    public function test_module_renders_open_with_the_shared_form_and_no_result(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        $component = Livewire::test('contract-detail', ['contractId' => 'bill-detail-contract'])
            ->assertSee('Vertaa nykyiseen sähkölaskuusi')
            ->assertSee('Syötä yhden laskun tiedot, niin näytämme mitä tämä sopimus olisi maksanut samalta jaksolta.')
            // The shared bill form partial, same field ids on both surfaces.
            ->assertSeeHtml('id="detail-bill-kwh"')
            ->assertSeeHtml('id="detail-bill-total"')
            // Open by default: the module is the first section under the hero, and its
            // fields have to be visible in the server HTML, not behind a disclosure.
            ->assertSeeHtml('billOpen: true')
            // And therefore never cloaked, which would hide it until Alpine boots.
            ->assertDontSeeHtml('x-cloak class="pt-6"');

        $this->assertNull($component->instance()->billComparison);
        $this->assertFalse($component->instance()->billActive);
    }

    public function test_save_case_shows_a_neutral_slate_delta_and_no_coral_warning(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        // Period cost 18,00 € vs 40,00 € paid -> 22 € saved.
        $component = $this->billComponent('bill-detail-contract', 40.00);

        $result = $component->instance()->billComparison;
        $this->assertTrue($result['available']);
        $this->assertSame('saves', $result['verdict']);
        $this->assertEqualsWithDelta(18.00, $result['contract_cost'], 0.01);
        $this->assertEqualsWithDelta(22.00, $result['delta'], 0.01);

        $component->assertSee('Olisit säästänyt n. 22 €')
            ->assertSee('Maksoit nykyisellä sopimuksellasi')
            ->assertSee('Perus Kiinteä olisi maksanut')
            // Neutral slate pill, never green: green/red belong to the CO2 delta.
            ->assertSeeHtml('bg-slate-900 border border-slate-900 text-white')
            ->assertDontSee('Olisit maksanut n.');
    }

    public function test_pay_more_case_shows_a_coral_pill_and_links_to_the_alternatives(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);
        // A cheaper contract so the alternatives section (and its anchor) exists.
        $this->createContract('cheaper-contract', 'Halvempi Kiinteä', 3.0);

        // Period cost 18,00 € vs 10,00 € paid -> 8 € more expensive.
        $component = $this->billComponent('bill-detail-contract', 10.00);

        $result = $component->instance()->billComparison;
        $this->assertSame('costs_more', $result['verdict']);
        $this->assertEqualsWithDelta(-8.00, $result['delta'], 0.01);

        $component->assertSee('Olisit maksanut n. 8 € enemmän')
            ->assertSeeHtml('bg-coral-50 border border-coral-200 text-coral-700')
            ->assertSee('Katso halvemmat vaihtoehdot')
            ->assertSeeHtml('href="#halvemmat"');
    }

    /**
     * Period basis only: the answer is the bill's own period and nothing is
     * annualized from it (annualizing one bill's implied unit rate is biased for
     * spot, seasonal and time-of-use contracts).
     */
    public function test_the_answer_is_period_basis_only(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        $component = $this->billComponent('bill-detail-contract', 40.00);
        $result = $component->instance()->billComparison;

        foreach (['annual_cost', 'annual_saving', 'annual_saving_eur', 'monthly_saving', 'monthly_cost'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $result, "The detail bill module must not derive {$forbidden}.");
        }

        // The delta is exactly bill total minus the contract's period cost.
        $this->assertEqualsWithDelta(
            $result['user_total'] - $result['contract_cost'],
            $result['delta'],
            0.001
        );

        $component->assertSee('Sama jakso 1.5.–30.5.2026 · 300 kWh');
    }

    /**
     * A pre-VAT total is lifted to Voltikka's with-VAT basis before comparison,
     * the same normalization as the other two bill surfaces.
     */
    public function test_a_pre_vat_total_is_normalized_before_comparison(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        $result = $this->billComponent('bill-detail-contract', 40.00)
            ->set('billIncludesVat', false)
            ->instance()
            ->billComparison;

        $this->assertEqualsWithDelta(40.00 * 1.255, $result['user_total'], 0.01);
    }

    public function test_spot_contract_without_period_history_says_so(): void
    {
        $this->createContract('bill-spot-contract', 'Pörssi Perus', 0.42, null, 'Spot');

        $component = $this->billComponent('bill-spot-contract', 40.00);
        $result = $component->instance()->billComparison;

        $this->assertFalse($result['available']);
        $this->assertSame('no_spot_history', $result['reason']);

        $component->assertSee('Tälle jaksolle ei ole vielä pörssihintatietoja, joten vertailua ei voi laskea tälle sopimukselle.')
            ->assertDontSee('Olisit säästänyt')
            ->assertDontSee('Olisit maksanut');
    }

    public function test_consumption_cap_outside_the_bill_is_explained(): void
    {
        $contract = $this->createContract('bill-capped-contract', 'Rajattu Sopimus', 5.0, 3.00);
        $contract->update(['consumption_limitation_max_x_kwh_per_y' => 2000]);

        $component = $this->billComponent('bill-capped-contract', 40.00);
        $result = $component->instance()->billComparison;

        $this->assertFalse($result['available']);
        $this->assertSame('consumption_cap', $result['reason']);
        $this->assertGreaterThan(2000, $result['annual_kwh'], 'Test premise: the bill annualizes above the cap.');

        $component->assertSee('Myyjä myy tätä sopimusta vain vuosikulutuksen ollessa enintään 2 000 kWh')
            ->assertDontSee('Olisit säästänyt');
    }

    /**
     * The module is per-user compute. It must reach the view without ever being
     * written into the page's prepared (shared) payload cache.
     */
    public function test_bill_state_never_enters_the_prepared_view_data_payload(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        $component = $this->billComponent('bill-detail-contract', 40.00);
        $instance = $component->instance();

        $this->assertTrue($instance->billActive);
        $this->assertNotNull($component->viewData('billComparison'), 'The module must reach the view.');

        $build = new \ReflectionMethod($instance, 'buildContractDetailViewData');
        $build->setAccessible(true);
        $payload = $build->invoke($instance);

        foreach (array_keys($payload['view']) as $key) {
            $this->assertStringNotContainsStringIgnoringCase(
                'bill',
                $key,
                "Prepared payload key {$key} leaks bill-module state into the shared cache."
            );
        }
        $this->assertStringNotContainsString('billComparison', json_encode(array_keys($payload['view'])));

        // The cache key is identical with and without a bill, because the bill is
        // not part of what is cached.
        $keyMethod = new \ReflectionMethod($instance, 'contractDetailViewDataCacheKey');
        $keyMethod->setAccessible(true);

        $withoutBill = Livewire::test('contract-detail', ['contractId' => 'bill-detail-contract'])->instance();
        $keyWithoutBill = new \ReflectionMethod($withoutBill, 'contractDetailViewDataCacheKey');
        $keyWithoutBill->setAccessible(true);

        $this->assertSame($keyWithoutBill->invoke($withoutBill), $keyMethod->invoke($instance));
    }

    /**
     * Defence in depth: a Livewire update is a POST and can never be cached, but
     * the cacheability guard refuses an active bill explicitly as well.
     */
    public function test_a_page_with_an_active_bill_is_not_cacheable(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        $instance = $this->billComponent('bill-detail-contract', 40.00)->instance();

        $cacheable = new \ReflectionMethod($instance, 'isDefaultContractDetailCacheable');
        $cacheable->setAccessible(true);

        $this->assertFalse($cacheable->invoke($instance));
    }

    public function test_completing_a_bill_tracks_one_analytics_event(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        $this->billComponent('bill-detail-contract', 40.00)
            ->assertDispatched('track', function (string $name, array $params): bool {
                return $params['eventName'] === 'Bill Comparison Completed'
                    && ($params['props']['source'] ?? null) === 'contract_detail'
                    && ($params['props']['contract_id'] ?? null) === 'bill-detail-contract';
            });
    }

    public function test_clearing_the_bill_removes_the_result(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);

        $component = $this->billComponent('bill-detail-contract', 40.00);
        $this->assertNotNull($component->instance()->billComparison);

        $component->call('clearBill');

        $this->assertFalse($component->instance()->billActive);
        $this->assertNull($component->instance()->billComparison);
        $component->assertDontSee('Olisit säästänyt');
    }

    /**
     * An inactive historical contract cannot be bought, so answering "what would
     * this have cost you" about it would be misleading.
     */
    public function test_the_module_is_hidden_on_an_inactive_contract_page(): void
    {
        $this->createContract('bill-detail-contract', 'Perus Kiinteä', 5.0, 3.00);
        ActiveContract::where('id', 'bill-detail-contract')->delete();

        Livewire::test('contract-detail', ['contractId' => 'bill-detail-contract'])
            ->assertDontSee('Vertaa nykyiseen sähkölaskuusi');
    }
}
