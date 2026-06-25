<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'name' => 'Halpa Energia Oy',
            'name_slug' => 'halpa-energia-oy',
            'company_url' => 'https://halpa.fi',
            'logo_url' => 'https://storage.example.com/logos/halpa.png',
        ]);

        Company::create([
            'name' => 'Kallis Energia Oy',
            'name_slug' => 'kallis-energia-oy',
            'company_url' => 'https://kallis.fi',
            'logo_url' => 'https://storage.example.com/logos/kallis.png',
        ]);
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

    public function test_page_is_accessible(): void
    {
        $response = $this->get('/maksatko-liikaa');

        $response->assertStatus(200);
        $response->assertSee('Maksatko sähköstä');
    }

    public function test_overpaying_user_is_ranked_last_and_sees_savings(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);
        $this->createFixedContract('expensive-contract', 'Kallis Kiinteä', 'Kallis Energia Oy', 12.0, 9.00);

        $lastMonth = Carbon::today('Europe/Helsinki')->subMonthNoOverflow()->startOfMonth();
        $end = $lastMonth->copy()->endOfMonth();

        $component = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 200.00)
            ->set('includesHeating', false);

        $result = $component->viewData('resultArray');

        $this->assertNotNull($result, 'Expected a computed comparison result.');
        $this->assertTrue($result['is_overpaying'], 'User paying 200 € for 1500 kWh should be overpaying vs a 5 c/kWh contract.');
        $this->assertSame(3, $result['user_rank'], 'User should rank 3rd of 3 (cheap, expensive, user).');
        $this->assertSame(3, $result['total_contracts']);
        $this->assertGreaterThan(0, $result['monthly_saving_eur']);
        $this->assertGreaterThan(0, $result['annual_saving_eur']);

        // Cheapest row is the cheap fixed contract.
        $this->assertSame('cheap-contract', $result['rows'][0]['contract_id']);
        $this->assertSame('Halpa Kiinteä', $result['rows'][0]['name']);

        $component->assertSee('Voisit säästää');
        $component->assertSee('Halpa Kiinteä');
    }

    public function test_competitive_user_is_not_flagged_as_overpaying(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);
        $this->createFixedContract('expensive-contract', 'Kallis Kiinteä', 'Kallis Energia Oy', 12.0, 9.00);

        $lastMonth = Carbon::today('Europe/Helsinki')->subMonthNoOverflow()->startOfMonth();
        $end = $lastMonth->copy()->endOfMonth();

        // User pays less than the cheapest market contract for this period.
        $component = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 50.00);

        $result = $component->viewData('resultArray');

        $this->assertNotNull($result);
        $this->assertFalse($result['is_overpaying']);
        $this->assertSame(1, $result['user_rank']);

        $component->assertSee('kilpailukykyinen');
    }

    public function test_user_implied_cents_per_kwh_is_not_shown_as_an_energy_price(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);
        $this->createFixedContract('expensive-contract', 'Kallis Kiinteä', 'Kallis Energia Oy', 10.0, 3.00);

        $lastMonth = Carbon::today('Europe/Helsinki')->subMonthNoOverflow()->startOfMonth();
        $end = $lastMonth->copy()->endOfMonth();

        // 40 € / 300 kWh = 13,33 c/kWh as a blended bill average, not a known
        // energy price. It should not be rendered for the user's own row.
        Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 300)
            ->set('totalEur', 40.00)
            ->assertSee('Sinun sopimuksesi')
            ->assertDontSee('13,33');
    }

    public function test_ranking_table_savings_use_same_period_basis_as_period_price(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);
        $this->createFixedContract('expensive-contract', 'Kallis Kiinteä', 'Kallis Energia Oy', 10.0, 3.00);

        // Exactly 30 days means one monthly fee in the period-cost path.
        $component = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-30')
            ->set('kwh', 300)
            ->set('totalEur', 40.00)
            ->assertSee('Säästö jaksolta')
            ->assertDontSee('Säästö €/kk');

        $result = $component->viewData('resultArray');
        $cheap = collect($result['rows'])->firstWhere('contract_id', 'cheap-contract');

        // Cheap period cost is 5 c/kWh * 300 kWh + 3 €/month = 18 €, so the
        // table saving must be 40 € - 18 € = 22 € for the same bill period.
        $this->assertSame(18.00, $cheap['period_cost_eur']);
        $this->assertSame(22.00, $cheap['period_saving_eur']);
    }

    public function test_pre_vat_total_is_normalized_to_with_vat_basis(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);

        $lastMonth = Carbon::today('Europe/Helsinki')->subMonthNoOverflow()->startOfMonth();
        $end = $lastMonth->copy()->endOfMonth();

        // 100 € pre-VAT should be treated as 125.5 € with-VAT for comparison.
        $withVat = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 125.50)
            ->set('includesVat', true)
            ->viewData('resultArray');

        $preVat = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 100.00)
            ->set('includesVat', false)
            ->viewData('resultArray');

        $this->assertSame($withVat['user_annual_cost'], $preVat['user_annual_cost']);
        $this->assertSame($withVat['user_rank'], $preVat['user_rank']);
    }

    public function test_monthly_presets_exclude_current_month(): void
    {
        $component = Livewire::test('bill-comparison');

        // The current month must not appear as a preset option.
        $currentLabel = $component->presetLabels['last_month'] ?? '';
        $today = Carbon::today('Europe/Helsinki');
        $fiMonths = [1 => 'tammikuu', 2 => 'helmikuu', 3 => 'maaliskuu', 4 => 'huhtikuu',
            5 => 'toukokuu', 6 => 'kesäkuu', 7 => 'heinäkuu', 8 => 'elokuu',
            9 => 'syyskuu', 10 => 'lokakuu', 11 => 'marraskuu', 12 => 'joulukuu'];
        $currentMonthLabel = ucfirst($fiMonths[(int) $today->format('n')]).' '.$today->format('Y');

        $labels = array_values(array_diff($component->presetLabels, ['Muu jakso']));
        $this->assertNotContains($currentMonthLabel, $labels, 'Current (unbilled) month must not be a preset.');

        // Default preset resolves to last completed month.
        $lastMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $this->assertSame($lastMonthStart->toDateString(), $component->startDate);
        $this->assertSame($lastMonthStart->copy()->endOfMonth()->toDateString(), $component->endDate);
    }

    public function test_result_is_not_synced_into_the_livewire_snapshot(): void
    {
        // Regression guard: the large DB-derived result must never become a
        // public/synced property again. Livewire's deep-array dehydration
        // produced a self-inconsistent checksum that raised
        // CorruptComponentPayloadException on every update (the page froze on
        // stale numbers). Keeping it out of the snapshot keeps updates working
        // and keeps the payload tiny.
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);
        $this->createFixedContract('expensive-contract', 'Kallis Kiinteä', 'Kallis Energia Oy', 12.0, 9.00);

        $component = Livewire::test('bill-comparison')
            ->set('kwh', 1500)
            ->set('totalEur', 200.00);

        $snapshot = $component->snapshot;

        $this->assertArrayNotHasKey(
            'resultArray',
            $snapshot['data'],
            'resultArray must stay out of the synced snapshot (see CorruptComponentPayloadException regression).'
        );
        $this->assertLessThan(
            5000,
            strlen(json_encode($snapshot)),
            'Bill-comparison snapshot should stay tiny; a large snapshot means derived data leaked back into sync.'
        );

        // A follow-up update must still hydrate cleanly and re-render results.
        $component->set('includesHeating', true)
            ->assertSee('Markkinoiden halvimmat');
    }

    public function test_consumption_capped_contracts_respect_their_annual_kwh_limit(): void
    {
        // A flat-fee tier (no per-kWh energy, monthly fee) capped at 1200 kWh/y,
        // like Helen Helpposähkö. It must drop out above the cap (otherwise its
        // consumption-immune flat fee sorts to the top and freezes the ranking)
        // and remain eligible within it.
        $capped = $this->createFixedContract('capped-flat', 'Litteä Tariffi', 'Halpa Energia Oy', 0.0, 13.90);
        $capped->consumption_limitation_max_x_kwh_per_y = 1200;
        $capped->save();

        $this->createFixedContract('normal', 'Tavallinen', 'Kallis Energia Oy', 6.0, 3.00);

        $lastMonth = Carbon::today('Europe/Helsinki')->subMonthNoOverflow()->startOfMonth();
        $end = $lastMonth->copy()->endOfMonth();

        // Annual consumption above the cap (override is deterministic) -> excluded.
        $high = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 200.00)
            ->set('annualKwh', 8000)
            ->viewData('resultArray');

        $highNames = array_column($high['rows'], 'name');
        $this->assertNotContains('Litteä Tariffi', $highNames, 'Capped flat-fee tier must drop out above its annual cap.');
        $this->assertContains('Tavallinen', $highNames);

        // Annual consumption within the cap -> eligible and shown.
        $low = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 90)
            ->set('totalEur', 20.00)
            ->set('annualKwh', 1000)
            ->viewData('resultArray');

        $lowNames = array_column($low['rows'], 'name');
        $this->assertContains('Litteä Tariffi', $lowNames, 'Capped flat-fee tier must be eligible within its annual cap.');
    }

    public function test_annual_kwh_override_replaces_seasonal_annualization(): void
    {
        $this->createFixedContract('cheap-contract', 'Halpa Kiinteä', 'Halpa Energia Oy', 5.0, 3.00);

        $lastMonth = Carbon::today('Europe/Helsinki')->subMonthNoOverflow()->startOfMonth();
        $end = $lastMonth->copy()->endOfMonth();

        $estimated = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 200.00)
            ->set('annualKwh', null)
            ->viewData('resultArray');

        $overridden = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 200.00)
            ->set('annualKwh', 50000) // much larger than the seasonal estimate
            ->viewData('resultArray');

        $this->assertNotSame($estimated['annual_kwh'], $overridden['annual_kwh']);
        $this->assertEquals(50000, $overridden['annual_kwh']);
        // User annual cost scales with the overridden annual kWh.
        $this->assertEquals(round((200.00 / 1500) * 50000, 2), $overridden['user_annual_cost']);
    }
}
