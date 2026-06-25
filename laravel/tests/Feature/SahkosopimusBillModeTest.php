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

/**
 * In-listing "Maksatko liikaa" bill comparison on /sahkosopimus.
 *
 * Period-basis mode: every contract is priced for the user's exact billing
 * period + kWh (facts), the user's bill is anchored into the ranking, and each
 * card shows the period €/kk + savings vs the bill. See
 * tasks/promote-bill-comparison-in-listings.
 */
class SahkosopimusBillModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Halpa Energia Oy', 'name_slug' => 'halpa-energia-oy', 'company_url' => 'https://halpa.fi']);
        Company::create(['name' => 'Kallis Energia Oy', 'name_slug' => 'kallis-energia-oy', 'company_url' => 'https://kallis.fi']);
    }

    private function createFixedContract(string $id, string $name, string $company, float $generalCents, float $monthlyEur): ElectricityContract
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
        ]);

        PriceComponent::create([
            'id' => 'pc-gen-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $generalCents,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-mon-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => $monthlyEur,
            'payment_unit' => 'EUR/month',
        ]);

        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    /**
     * A flat-fee capped tier: included energy up to an annual kWh cap, priced
     * as a monthly fee (General price 0). Mirrors Helen Helpposähkö.
     */
    private function createCappedContract(string $id, string $name, string $company, float $monthlyEur, int $maxKwhPerYear): ElectricityContract
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
            'consumption_limitation_max_x_kwh_per_y' => $maxKwhPerYear,
        ]);

        PriceComponent::create([
            'id' => 'pc-gen-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-mon-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => $monthlyEur,
            'payment_unit' => 'EUR/month',
        ]);

        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    private function billComponent()
    {
        // 30-day period so months-in-period is exactly 1 and €/kk equals the
        // period cost, keeping the period-cost assertions deterministic.
        return Livewire::test(SahkosopimusIndex::class)
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 300)
            ->set('billTotalEur', 40.00);
    }

    public function test_bill_entry_form_is_shown_on_sahkosopimus(): void
    {
        $this->get('/sahkosopimus')
            ->assertStatus(200)
            ->assertSee('Maksatko nykyisestä sopimuksestasi liikaa?');
    }

    public function test_entering_a_bill_activates_period_mode_and_ranks_the_user(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);
        $this->createFixedContract('expensive-contract', 'Kallis Kiinteä', 'Kallis Energia Oy', 12.0, 9.00);

        $component = $this->billComponent();

        $this->assertTrue($component->instance()->isBillModeActive());
        $component->assertDispatched('track', function (string $name, array $params): bool {
            return $name === 'track'
                && $params['eventName'] === 'Bill Comparison Completed'
                && ($params['props']['source'] ?? null) === 'contract_listing'
                && ($params['props']['period_preset'] ?? null) === 'custom';
        });

        $summary = $component->instance()->billSummary;
        $this->assertNotNull($summary);

        // cheap period cost = 5 c * 300 kWh + 3 € = 18 €; expensive = 45 €; user = 40 €.
        // Only the cheap contract beats the user, so user ranks 2nd of 3 (incl. self).
        $this->assertSame(2, $summary['user_rank']);
        $this->assertSame(3, $summary['total_ranked']);
        $this->assertEqualsWithDelta(22.0, $summary['cheapest_saving'], 0.01);
        $this->assertTrue($summary['is_overpaying']);

        // The cheapest contract carries a period_comparison payload on its card.
        $contracts = $component->viewData('contracts');
        $first = $contracts->first();
        $this->assertSame('cheap-contract', $first->id);
        $this->assertEqualsWithDelta(18.0, $first->period_comparison['period_cost'], 0.01);
        $this->assertEqualsWithDelta(22.0, $first->period_comparison['period_saving'], 0.01);

        $component->assertSee('Sinun sopimuksesi')
            ->assertSee('Säästö');
    }

    public function test_clearing_the_bill_returns_to_normal_listing(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);

        $component = $this->billComponent();
        $this->assertTrue($component->instance()->isBillModeActive());

        $component->call('clearBill');

        $this->assertFalse($component->instance()->isBillModeActive());
        // Normal annual credibility bar is back.
        $component->assertSee('Näin laskemme');
    }

    public function test_spot_contract_without_period_history_is_omitted(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);

        // Spot contract: margin-only General component, no SpotPriceHour rows for
        // the period, so it cannot be priced and must drop out (not show €0).
        $spot = ElectricityContract::create([
            'id' => 'spot-contract',
            'company_name' => 'Kallis Energia Oy',
            'name' => 'Pörssi Sopimus',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'Spot',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);
        PriceComponent::create([
            'id' => 'pc-gen-spot-contract',
            'electricity_contract_id' => 'spot-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.40,
            'payment_unit' => 'c/kWh',
        ]);
        ActiveContract::create(['id' => 'spot-contract']);

        $component = $this->billComponent();

        $ids = $component->viewData('contracts')->pluck('id')->all();
        $this->assertContains('cheap-contract', $ids);
        $this->assertNotContains('spot-contract', $ids, 'Spot contract without period history must be omitted from bill-mode ranking.');
    }

    /**
     * In bill mode the relevant consumption is the bill's annualized kWh, not
     * the annual slider (default 5000) set before the bill. A capped tier that
     * fits the bill-annualized consumption must appear even though the stale
     * 5000 slider would exclude it; caps are enforced by the service on the
     * bill-derived basis instead.
     */
    public function test_bill_mode_ignores_stale_annual_slider_for_consumption_caps(): void
    {
        $this->createFixedContract('normal-contract', 'Normaali Kiinteä', 'Halpa Energia Oy', 6.0, 3.00);
        $this->createCappedContract('capped-tier', 'Capped Tier', 'Halpa Energia Oy', 8.0, 4500);

        // Small May bill that annualizes well below the 4500 cap.
        $component = Livewire::test(SahkosopimusIndex::class)
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 200)
            ->set('billTotalEur', 30.00);

        $this->assertTrue($component->instance()->isBillModeActive());
        $this->assertLessThan(4500, $component->instance()->billSummary['annual_kwh'], 'Test premise: bill annualizes below the cap.');

        $ids = $component->viewData('contracts')->pluck('id')->all();
        $this->assertContains('capped-tier', $ids, 'Capped tier fitting the bill-annualized consumption must appear in bill mode.');

        // Sanity: in normal mode at the default 5000 kWh slider it stays filtered out.
        $normal = Livewire::test(SahkosopimusIndex::class);
        $this->assertFalse($normal->instance()->isBillModeActive());
        $this->assertNotContains('capped-tier', $normal->viewData('contracts')->pluck('id')->all());
    }

    /**
     * Rollout: bill comparison is enabled on household SEO listing pages but
     * not on the business page (a household bill vs business contracts is not
     * meaningful).
     */
    public function test_bill_form_shown_on_household_seo_page_and_hidden_on_business(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);

        $this->get('/sahkosopimus/porssisahko')
            ->assertStatus(200)
            ->assertSee('Maksatko nykyisestä sopimuksestasi liikaa?');

        $this->get('/sahkosopimus/yritykselle')
            ->assertStatus(200)
            ->assertDontSee('Maksatko nykyisestä sopimuksestasi liikaa?');
    }
}
