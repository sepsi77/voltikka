<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\ContractPriceHistory\PriceDevelopmentPresenter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Näin hinta on kehittynyt" on the contract detail page.
 *
 * @see \App\Services\ContractPriceHistory\PriceDevelopmentPresenter
 */
class ContractDetailPriceDevelopmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-26 09:00:00'));

        Company::create([
            'name' => 'Historia Energia Oy',
            'name_slug' => 'historia-energia-oy',
            'company_url' => 'https://historia.example',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Chart: contract line + segment median overlay
    // ------------------------------------------------------------------

    public function test_chart_overlays_the_segment_median_when_statistics_exist(): void
    {
        $contract = $this->openEndedContract('history-open-ended');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 8.20,
            '2026-04-01' => 7.55,
            '2026-07-01' => 6.95,
            '2026-07-25' => 6.95,
        ]);
        $this->monthlyFee($contract->id, '2026-03-07', 4.50);
        $this->monthlyFee($contract->id, '2026-07-25', 4.50);
        $this->segmentMedians('open_ended', '2026-03-07', '2026-07-25', 8.70);

        $test = Livewire::test('contract-detail', ['contractId' => $contract->id]);
        $development = $test->viewData('priceDevelopment');

        $this->assertSame('contract', $development['variant']);
        $this->assertTrue($development['available']);
        $this->assertNotNull($development['chart']);
        $this->assertNotSame('', $development['chart']['reference_path']);

        $test
            // The contract is slate-900 ink, the median a dashed slate-500
            // reference with a direct label. Coral is never a data series.
            ->assertSeeHtml('stroke="#0f172a"')
            ->assertSeeHtml('stroke-dasharray="6 5"')
            ->assertSee('Mediaani, toistaiseksi voimassa oleva')
            ->assertSee('mediaani 8,70')
            ->assertSee('Näin hinta on kehittynyt');
    }

    public function test_contract_line_is_stepped_between_price_changes(): void
    {
        $contract = $this->openEndedContract('history-stepped');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 8.20,
            '2026-06-01' => 6.95,
            '2026-07-25' => 6.95,
        ]);

        $chart = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment')['chart'];

        // A stepped path holds the old price all the way to the change date and
        // only then drops, instead of sloping between the two known prices.
        [$first, $change] = $chart['series_points'];
        $this->assertStringContainsString("L{$change['x']},{$first['y']} L{$change['x']},{$change['y']}", $chart['series_path']);
        $this->assertStringStartsWith("M{$first['x']},{$first['y']} ", $chart['series_path']);
    }

    public function test_chart_renders_without_a_median_when_no_statistics_exist(): void
    {
        $contract = $this->openEndedContract('history-no-stats');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 8.20,
            '2026-06-01' => 6.95,
            '2026-07-25' => 6.95,
        ]);

        $development = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment');

        $this->assertTrue($development['available']);
        $this->assertSame('', $development['chart']['reference_path']);
        $this->assertStringContainsString('Vertailuryhmän mediaania ei ole vielä tallessa', (string) $development['note']);
    }

    // ------------------------------------------------------------------
    // Spot variant
    // ------------------------------------------------------------------

    public function test_spot_contract_charts_monthly_realized_averages_against_the_trailing_year(): void
    {
        $contract = $this->spotContract('history-spot');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 0.42,
            '2026-07-25' => 0.42,
        ]);
        $this->monthlySpotAverages([
            '2026-02' => 7.20,
            '2026-03' => 6.80,
            '2026-04' => 5.90,
            '2026-05' => 4.90,
            '2026-06' => 4.10,
        ]);
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => '2025-07-26',
            'period_end' => '2026-07-25',
            'avg_price_with_tax' => 6.57,
            'avg_price_without_tax' => 5.235,
            'hours_count' => 8760,
        ]);

        $test = Livewire::test('contract-detail', ['contractId' => $contract->id]);
        $development = $test->viewData('priceDevelopment');

        $this->assertSame('spot', $development['variant']);
        $this->assertTrue($development['available']);
        // Five completed months; the running month is deliberately excluded.
        $this->assertCount(5, $development['chart']['rows']);
        $this->assertSame('helmi', $development['chart']['rows'][0]['label']);
        $this->assertSame('kesä', $development['chart']['rows'][4]['label']);

        // Monthly average + this contract's margin: 4,10 + 0,42.
        $this->assertSame('4,52', $development['chart']['rows'][4]['series']);

        $test
            ->assertSee('Kuukauden keskihinta, marginaali mukana')
            ->assertSee('12 kk keskihinta')
            // Trailing-year reference 6,57 + margin 0,42.
            ->assertSee('12 kk 6,99')
            ->assertSee('Halvin kuukausi: kesäkuu 4,52 c/kWh')
            ->assertSee('Kallein kuukausi: helmikuu 7,62 c/kWh');
    }

    public function test_spot_variant_states_the_empty_case_when_too_few_months_are_stored(): void
    {
        $contract = $this->spotContract('history-spot-short');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 0.42,
            '2026-07-25' => 0.42,
        ]);
        $this->monthlySpotAverages(['2026-05' => 4.90, '2026-06' => 4.10]);

        $development = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment');

        $this->assertFalse($development['available']);
        $this->assertNull($development['chart']);
        $this->assertStringContainsString('2 täydeltä kuukaudelta', (string) $development['message']);
    }

    // ------------------------------------------------------------------
    // Honest empty state
    // ------------------------------------------------------------------

    public function test_short_history_states_the_window_instead_of_drawing_a_flat_line(): void
    {
        $contract = $this->openEndedContract('history-too-short');
        $this->generalPriceSeries($contract->id, [
            '2026-07-20' => 7.10,
            '2026-07-25' => 6.95,
        ]);

        $test = Livewire::test('contract-detail', ['contractId' => $contract->id]);
        $development = $test->viewData('priceDevelopment');

        $this->assertFalse($development['available']);
        $this->assertNull($development['chart']);

        $test
            ->assertSee('Hintaa on seurattu vasta 5 päivän ajan')
            ->assertDontSeeHtml('stroke-dasharray="6 5"');
    }

    public function test_a_single_observation_says_so(): void
    {
        $contract = $this->openEndedContract('history-single');
        $this->generalPriceSeries($contract->id, ['2026-07-25' => 6.95]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Voltikka on havainnut tämän sopimuksen hinnan vain kerran (25.7.2026)');
    }

    // ------------------------------------------------------------------
    // Seller behaviour record
    // ------------------------------------------------------------------

    public function test_behaviour_tags_report_changes_in_cents_per_kwh(): void
    {
        $contract = $this->openEndedContract('history-facts');
        $this->generalPriceSeries($contract->id, [
            '2026-01-10' => 6.00,
            '2026-03-01' => 6.50,
            '2026-06-01' => 7.10,
            '2026-07-25' => 7.10,
        ]);

        $test = Livewire::test('contract-detail', ['contractId' => $contract->id]);
        $facts = $test->viewData('priceDevelopment')['facts'];

        $this->assertContains('Energianhintaa korotettu 2 kertaa 6 kuukaudessa', $facts);
        $this->assertContains('Viimeisin muutos +0,60 c/kWh (1.6.2026)', $facts);

        // Never a percentage.
        foreach ($facts as $fact) {
            $this->assertStringNotContainsString('%', $fact);
        }

        $test->assertSee('Energianhintaa korotettu 2 kertaa 6 kuukaudessa');
    }

    public function test_a_flat_price_is_reported_as_unchanged_without_inventing_a_change(): void
    {
        $contract = $this->openEndedContract('history-no-facts');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 6.95,
            '2026-07-25' => 6.95,
        ]);
        $this->monthlyFee($contract->id, '2026-03-07', 4.50);
        $this->monthlyFee($contract->id, '2026-07-25', 4.50);

        $facts = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment')['facts'];

        $this->assertSame([
            'Energianhinta ennallaan koko seurannan ajan',
            'Perusmaksu ennallaan koko seurannan ajan',
        ], $facts);
    }

    /**
     * A window too short to describe produces no behaviour record at all, not a
     * confident-sounding claim built on two observations.
     */
    public function test_behaviour_tags_are_omitted_when_the_window_is_too_short(): void
    {
        $contract = $this->openEndedContract('history-short-facts');
        $this->generalPriceSeries($contract->id, [
            '2026-07-20' => 7.10,
            '2026-07-25' => 6.95,
        ]);
        $this->monthlyFee($contract->id, '2026-07-20', 4.50);
        $this->monthlyFee($contract->id, '2026-07-25', 4.50);

        $facts = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment')['facts'];

        $this->assertSame([], $facts);
    }

    public function test_a_monthly_fee_change_is_reported_in_euros_per_month(): void
    {
        $contract = $this->openEndedContract('history-fee-change');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 6.95,
            '2026-07-25' => 6.95,
        ]);
        $this->monthlyFee($contract->id, '2026-03-07', 3.90);
        $this->monthlyFee($contract->id, '2026-05-02', 4.99);
        $this->monthlyFee($contract->id, '2026-07-25', 4.99);

        $facts = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment')['facts'];

        $this->assertContains('Perusmaksua muutettu kerran (nyt 4,99 €/kk)', $facts);
    }

    // ------------------------------------------------------------------
    // Version timeline
    // ------------------------------------------------------------------

    public function test_version_timeline_collapses_past_three_versions(): void
    {
        $contract = $this->openEndedContract('history-chain-5');
        $this->generalPriceSeries($contract->id, ['2026-07-01' => 6.50, '2026-07-25' => 6.50]);

        $successorId = $contract->id;
        foreach ([4 => 6.60, 3 => 6.70, 2 => 6.80, 1 => 6.90] as $index => $price) {
            $older = $this->openEndedContract("history-chain-{$index}", active: false, name: "Versio {$index}");
            $older->replaced_by_contract_id = $successorId;
            $older->save();
            $this->generalPriceSeries($older->id, [
                Carbon::parse('2026-07-01')->subMonths(5 - $index)->toDateString() => $price,
            ]);
            $successorId = $older->id;
        }

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Näytä 2 vanhempaa versiota')
            // All five versions stay in the DOM; only the oldest two are collapsed.
            ->assertSee('Versio 1')
            ->assertSee('Versio 4');
    }

    public function test_version_timeline_is_not_collapsed_with_three_versions(): void
    {
        $contract = $this->openEndedContract('history-chain-b3');
        $this->generalPriceSeries($contract->id, ['2026-07-01' => 6.50, '2026-07-25' => 6.50]);

        $successorId = $contract->id;
        foreach ([2 => 6.70, 1 => 6.90] as $index => $price) {
            $older = $this->openEndedContract("history-chain-b{$index}", active: false, name: "Versio B{$index}");
            $older->replaced_by_contract_id = $successorId;
            $older->save();
            $this->generalPriceSeries($older->id, [
                Carbon::parse('2026-07-01')->subMonths(3 - $index)->toDateString() => $price,
            ]);
            $successorId = $older->id;
        }

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertDontSee('vanhempaa versiota');
    }

    public function test_version_deltas_are_shown_in_cents_per_kwh_not_percent(): void
    {
        $contract = $this->openEndedContract('history-delta-unit', name: 'Uusi versio');
        $this->generalPriceSeries($contract->id, ['2026-07-01' => 6.50, '2026-07-25' => 6.50]);

        $older = $this->openEndedContract('history-delta-unit-old', active: false, name: 'Vanha versio');
        $older->replaced_by_contract_id = $contract->id;
        $older->save();
        $this->generalPriceSeries($older->id, ['2026-05-01' => 7.00]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSeeInOrder(['-0,50', 'c/kWh', 'Energiahinta muuttui']);
    }

    // ------------------------------------------------------------------
    // Zero energy prices: the duplicate null-UUID collision artifact
    // ------------------------------------------------------------------

    /**
     * The upstream payload can send two `General` components for one contract,
     * both with the null UUID and the same fuse size, one real and one zero.
     * They collapse to a single relational key, and a day whose upsert let the
     * zero win stores an energy price of 0,00 c/kWh beside months of real
     * prices. The chart used to plot it as a vertical drop to zero, and the
     * behaviour tags reported that drop as a price change, while the version
     * timeline six pixels below kept showing the real price.
     */
    public function test_a_zero_energy_price_beside_real_prices_is_not_charted(): void
    {
        $contract = $this->openEndedContract('history-collided-zero');
        $this->generalPriceSeries($contract->id, [
            '2026-01-10' => 7.88,
            '2026-07-22' => 7.88,
            // The collision artifact: two observed days at exactly zero.
            '2026-07-23' => 0.00,
            '2026-07-24' => 0.00,
        ]);
        $this->monthlyFee($contract->id, '2026-01-10', 4.05);
        $this->monthlyFee($contract->id, '2026-07-24', 4.05);

        $development = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment');

        $chart = $development['chart'];

        // The line ends on the last trusted observation, at the real price.
        $this->assertSame('7,88', $chart['series_end_label']['text']);
        foreach ($chart['series_points'] as $point) {
            $this->assertGreaterThan(0, $point['value']);
        }

        // The sr-only table mirror and the hover bands share these rows, so
        // they cannot disagree with the line.
        foreach ($chart['rows'] as $row) {
            $this->assertNotSame('0,00', $row['series']);
        }

        // No invented price change, and no invented -7,88 c/kWh drop.
        $this->assertContains('Energianhinta ennallaan koko seurannan ajan', $development['facts']);
        foreach ($development['facts'] as $fact) {
            $this->assertStringNotContainsString('Viimeisin muutos', $fact);
        }

        // The monthly fee is unaffected by the energy-side guard.
        $this->assertContains('Perusmaksu ennallaan koko seurannan ajan', $development['facts']);
    }

    /**
     * Zero is a real per-kWh price for a flat-fee package contract (Helen
     * Helpposähkö, Väre Kuukausisähkö, Vattenfall Ilmasto Vakio). Those price
     * at zero on every observed date, so the guard must leave them alone.
     */
    public function test_a_contract_priced_at_zero_throughout_keeps_its_zero_series(): void
    {
        $contract = $this->openEndedContract('history-package-zero');
        $this->generalPriceSeries($contract->id, [
            '2026-03-07' => 0.00,
            '2026-05-01' => 0.00,
            '2026-07-25' => 0.00,
        ]);
        $this->monthlyFee($contract->id, '2026-03-07', 39.90);
        $this->monthlyFee($contract->id, '2026-07-25', 39.90);

        $development = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->viewData('priceDevelopment');

        $this->assertTrue($development['available']);
        $this->assertSame('0,00', $development['chart']['series_end_label']['text']);
        $this->assertContains('Energianhinta ennallaan koko seurannan ajan', $development['facts']);
    }

    /**
     * A spot contract's tracked component is its margin, and a 0 c/kWh margin
     * is a real commercial position, so the guard must not reach it.
     */
    public function test_a_spot_margin_of_zero_is_left_alone(): void
    {
        $contract = $this->spotContract('history-zero-margin');
        $this->monthlySpotAverages([
            '2026-04' => 4.10,
            '2026-05' => 3.40,
            '2026-06' => 2.90,
        ]);

        // Called directly, because the relational calculator resolves the
        // margin from the latest *positive* component and would never report a
        // zero margin back into the payload.
        $development = app(PriceDevelopmentPresenter::class)->present(
            $contract,
            ['General' => [
                ['date' => '2026-07-25', 'price' => 0.00],
                ['date' => '2026-06-01', 'price' => 0.00],
                ['date' => '2026-01-10', 'price' => 0.60],
            ]],
            ['spot_price_margin' => 0.0],
        );

        $this->assertSame('spot', $development['variant']);
        $this->assertContains('Marginaalia laskettu kerran 6 kuukauden seurannan aikana', $development['facts']);
        $this->assertContains('Viimeisin muutos -0,60 c/kWh (1.6.2026)', $development['facts']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function openEndedContract(string $id, bool $active = true, string $name = 'Historia Perus'): ElectricityContract
    {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Historia Energia Oy',
            'name' => $name,
            'name_slug' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);

        if ($active) {
            ActiveContract::create(['id' => $contract->id]);
        }

        return $contract;
    }

    private function spotContract(string $id): ElectricityContract
    {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Historia Energia Oy',
            'name' => 'Historia Pörssi',
            'name_slug' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        return $contract;
    }

    /**
     * @param  array<string, float>  $pricesByDate
     */
    private function generalPriceSeries(string $contractId, array $pricesByDate): void
    {
        foreach ($pricesByDate as $date => $price) {
            PriceComponent::create([
                'id' => 'pc-' . $contractId . '-general-' . $date,
                'electricity_contract_id' => $contractId,
                'price_component_type' => 'General',
                'price_date' => $date,
                'price' => $price,
                'payment_unit' => 'c/kWh',
            ]);
        }
    }

    private function monthlyFee(string $contractId, string $date, float $price): void
    {
        PriceComponent::create([
            'id' => 'pc-' . $contractId . '-monthly-' . $date,
            'electricity_contract_id' => $contractId,
            'price_component_type' => 'Monthly',
            'price_date' => $date,
            'price' => $price,
            'payment_unit' => 'EUR/month',
        ]);
    }

    private function segmentMedians(string $segmentKey, string $from, string $to, float $median): void
    {
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($cursor->lte($end)) {
            ContractPriceDailyStatistic::create([
                'stat_date' => $cursor->toDateString(),
                'segment_key' => $segmentKey,
                'metric_key' => 'energy_price',
                'consumption_kwh' => null,
                'median_value' => $median,
                'avg_value' => $median,
                'contract_count' => 12,
            ]);
            $cursor->addWeek();
        }
    }

    /**
     * @param  array<string, float>  $averagesByMonth  'YYYY-MM' => c/kWh incl. VAT
     */
    private function monthlySpotAverages(array $averagesByMonth): void
    {
        foreach ($averagesByMonth as $month => $average) {
            $start = Carbon::parse($month . '-01');
            SpotPriceAverage::create([
                'region' => 'FI',
                'period_type' => SpotPriceAverage::PERIOD_MONTHLY,
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'avg_price_with_tax' => $average,
                'avg_price_without_tax' => round($average / 1.255, 3),
                'hours_count' => 720,
            ]);
        }
    }
}
