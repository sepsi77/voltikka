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

        $result = $component->resultArray;

        $this->assertNotNull($result, 'Expected a computed comparison result.');
        $this->assertTrue($result['is_overpaying'], 'User paying 200 € for 1500 kWh should be overpaying vs a 5 c/kWh contract.');
        $this->assertSame(3, $result['user_rank'], 'User should rank 3rd of 3 (cheap, expensive, user).');
        $this->assertSame(3, $result['total_contracts']);
        $this->assertGreaterThan(0, $result['monthly_saving_eur']);
        $this->assertGreaterThan(0, $result['annual_saving_eur']);

        // Cheapest row is the cheap fixed contract.
        $this->assertSame('cheap-contract', $result['rows'][0]['contract_id']);
        $this->assertSame('Halpa Kiinteä', $result['rows'][0]['name']);

        $component->assertSee('Maksat liikaa');
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

        $result = $component->resultArray;

        $this->assertNotNull($result);
        $this->assertFalse($result['is_overpaying']);
        $this->assertSame(1, $result['user_rank']);

        $component->assertSee('kilpailukykyinen');
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
            ->resultArray;

        $preVat = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 100.00)
            ->set('includesVat', false)
            ->resultArray;

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
            ->resultArray;

        $overridden = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', $lastMonth->toDateString())
            ->set('endDate', $end->toDateString())
            ->set('kwh', 1500)
            ->set('totalEur', 200.00)
            ->set('annualKwh', 50000) // much larger than the seasonal estimate
            ->resultArray;

        $this->assertNotSame($estimated['annual_kwh'], $overridden['annual_kwh']);
        $this->assertEquals(50000, $overridden['annual_kwh']);
        // User annual cost scales with the overridden annual kWh.
        $this->assertEquals(round((200.00 / 1500) * 50000, 2), $overridden['user_annual_cost']);
    }
}
