<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\CompanyStatistics\CompanyMarketComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The three sections added to the company page for the "[yhtiö] tarjoukset",
 * "[yhtiö] hinta" and "[yhtiö] pörssisähkö" query clusters.
 */
class CompanyDetailSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Energy Oy',
            'name_slug' => 'test-energy-oy',
            'company_url' => 'https://testenergy.fi',
        ]);
    }

    // ---------------------------------------------------------------- offers

    public function test_promotions_section_lists_contracts_with_a_live_discount(): void
    {
        $this->createContract('promo', 'Kampanja Sähkö', 5.0, 3.0, discount: 2.5);
        $this->createContract('plain', 'Tavallinen Sähkö', 5.0, 3.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $promotions = $component->viewData('promotionContracts');

        $this->assertCount(1, $promotions);
        $this->assertSame('Kampanja Sähkö', $promotions->first()->name);
        $component->assertSee('Test Energy Oy tarjoukset');
    }

    /**
     * The section must render even with nothing to show, so a seller page never
     * silently drops the heading a visitor searched for.
     */
    public function test_promotions_section_renders_a_fallback_when_there_are_no_promotions(): void
    {
        $this->createContract('plain', 'Tavallinen Sähkö', 5.0, 3.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $this->assertCount(0, $component->viewData('promotionContracts'));

        $component
            ->assertSee('Test Energy Oy tarjoukset')
            ->assertSee('Vertailussa ei ole nyt kampanjahintaista sopimusta. Voltikka päivittää sopimustiedot päivittäin.')
            ->assertSee('Katso kaikki voimassa olevat sähkötarjoukset')
            ->assertDontSee('ilmestyy tähän automaattisesti');
    }

    /**
     * A monthly-fee waiver is not a per-kWh discount. The label comes from
     * `formatActiveDiscountValue()` so this page cannot repeat that mistake.
     */
    public function test_promotion_label_uses_the_discounted_components_own_unit(): void
    {
        $this->createContract('promo', 'Kampanja Sähkö', 5.0, 3.0, discount: 3.0, discountOnMonthly: true);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('€/kk')
            ->assertDontSee('3,00 c/kWh alennus');
    }

    public function test_promotion_benefit_column_shows_the_12_month_effect(): void
    {
        $this->createContract('promo', 'Kampanja Sähkö', 5.0, 6.0, discount: 3.0, discountOnMonthly: true, discountMonths: 12);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $savings = $component->viewData('promotionContracts')->first()->calculated_cost['discount_savings_total'];

        $this->assertGreaterThan(0, $savings, 'Fixture must produce a real discount for this test to mean anything.');
        $component->assertSee(number_format($savings, 0, ',', ' ').' €');
    }

    /**
     * A zero here does not mean the promotion is worthless. The shared discount
     * calculation returns 0 for 26 of 69 discounted contracts on 2026-07-24,
     * including Vattenfall's real "Perusmaksut -50 % ensimmäiset 12 kuukautta",
     * so printing "0 €" would publish a false claim about a live offer.
     */
    public function test_promotion_benefit_shows_a_dash_instead_of_a_false_zero(): void
    {
        // Nothing to discount: the monthly fee is already zero, so the promotion
        // cannot move the 12-month total.
        $this->createContract('promo', 'Kampanja Sähkö', 5.0, 0.0, discount: 3.0, discountOnMonthly: true, discountMonths: 3);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $contract = $component->viewData('promotionContracts')->first();

        $this->assertNotNull($contract, 'The contract must still be listed as a promotion.');
        $this->assertEqualsWithDelta(0.0, $contract->calculated_cost['discount_savings_total'] ?? 0.0, 0.005);
        $component->assertSee('ei voi laskea luotettavasti');
    }

    // ------------------------------------------------------------------ spot

    public function test_spot_section_lists_spot_contracts_with_their_margin(): void
    {
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 3.90, pricingModel: 'Spot');
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $spot = $component->viewData('spotContracts');

        $this->assertCount(1, $spot);
        $this->assertSame('Pörssi Sähkö', $spot->first()->name);

        $component
            ->assertSee('Test Energy Oy: pörssisähkö, marginaali ja perusmaksu')
            ->assertSee('Test Energy Oy myy pörssisähköä. Vertailussa on 1 pörssisähkösopimus.')
            ->assertSee('Myyjän itse määrittämät kulut ovat marginaali ja kuukausittainen perusmaksu.')
            ->assertSee('Nord Poolin markkinahinta on kaikille pörssisähkötuotteille yhteinen')
            ->assertSee('0,45 c/kWh');
    }

    public function test_spot_section_uses_the_correct_plural_contract_count(): void
    {
        $this->createContract('spot-one', 'Pörssi Yksi', 0.45, 3.90, pricingModel: 'Spot');
        $this->createContract('spot-two', 'Pörssi Kaksi', 0.55, 4.20, pricingModel: 'Spot');

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Vertailussa on 2 pörssisähkösopimusta.');
    }

    public function test_spot_charges_are_compared_with_same_date_and_basis_market_medians(): void
    {
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 4.00, pricingModel: 'Spot');
        $this->seedMarket('spot', 500.0, 600.0, 700.0, 40, 'observed_seller_data', '2026-08-01');
        $this->seedCompanySnapshot('spot', 610.0, 0.45, 'spot', 'observed_seller_data', '2026-08-01');
        $this->seedSpotBenchmark('spot_margin', 0.50, 40, 'observed_seller_data', '2026-08-01');
        $this->seedSpotBenchmark('monthly_fee', 3.50, 40, 'observed_seller_data', '2026-08-01');

        // Neither a newer date nor the opposite basis can replace the selected
        // company-comparison date and basis.
        $this->seedSpotBenchmark('spot_margin', 0.10, 40, 'observed_seller_data', '2026-08-02');
        $this->seedSpotBenchmark('monthly_fee', 9.00, 40, 'canonical_calculation', '2026-08-01');

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $benchmarks = $component->viewData('spotBenchmarks');

        $this->assertSame('2026-08-01', $benchmarks['stat_date']);
        $this->assertSame('observed_seller_data', $benchmarks['pricing_basis']);
        $this->assertSame(0.5, $benchmarks['spot_margin']['median']);
        $this->assertSame(3.5, $benchmarks['monthly_fee']['median']);

        $component
            ->assertSee('0,05 c/kWh alle markkinan mediaanin')
            ->assertSee('0,50 €/kk yli markkinan mediaanin')
            ->assertSee('Mediaanit perustuvat 1.8.2026')
            ->assertSee('myyjiltä havaittuihin hintoihin');
    }

    public function test_canonical_spot_benchmarks_use_the_selected_current_date_and_basis(): void
    {
        config()->set('canonical_pricing.enabled', true);
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 3.90, pricingModel: 'Spot');
        $this->seedMarket('spot', 500.0, 600.0, 700.0, 40, 'canonical_calculation', '2026-08-01');
        $this->seedCompanySnapshot('spot', 610.0, null, 'spot', 'canonical_calculation', '2026-08-01');
        $this->seedSpotBenchmark('spot_margin', 0.55, 40, 'canonical_calculation', '2026-08-01');
        $this->seedSpotBenchmark('monthly_fee', 4.50, 40, 'canonical_calculation', '2026-08-01');
        $this->seedSpotBenchmark('spot_margin', 0.10, 40, 'observed_seller_data', '2026-08-01');
        $this->seedSpotBenchmark('monthly_fee', 1.00, 40, 'canonical_calculation', '2026-08-02');

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);
        $benchmarks = $comparison['spot_benchmarks'];

        $this->assertSame('current_canonical', $comparison['comparison_state']);
        $this->assertSame('2026-08-01', $benchmarks['stat_date']);
        $this->assertSame('canonical_calculation', $benchmarks['pricing_basis']);
        $this->assertSame(0.55, $benchmarks['spot_margin']['median']);
        $this->assertSame(4.5, $benchmarks['monthly_fee']['median']);
    }

    public function test_spot_charge_equal_to_the_market_median_is_stated_directly(): void
    {
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 3.90, pricingModel: 'Spot');
        $this->seedMarket(segment: 'spot', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 40);
        $this->seedCompanySnapshot(segment: 'spot', annualCost: 610.0, energyPrice: 0.45, contractId: 'spot');
        $this->seedSpotBenchmark('spot_margin', 0.45);
        $this->seedSpotBenchmark('monthly_fee', 3.90);

        $html = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])->html();

        $this->assertSame(2, substr_count($html, 'Sama kuin markkinan mediaani'));
    }

    public function test_spot_benchmark_claim_is_omitted_when_market_metric_is_not_usable(): void
    {
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 3.90, pricingModel: 'Spot');
        $this->seedMarket(segment: 'spot', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 40);
        $this->seedCompanySnapshot(segment: 'spot', annualCost: 610.0, energyPrice: 0.45, contractId: 'spot');
        $this->seedSpotBenchmark('spot_margin', 0.50, CompanyMarketComparisonService::MIN_MARKET_CONTRACTS - 1);
        $this->seedSpotBenchmark('monthly_fee', null);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $this->assertNull($component->viewData('spotBenchmarks'));
        $component
            ->assertSee('0,45 c/kWh')
            ->assertSee('3,90 €/kk')
            ->assertDontSee('c/kWh alle markkinan mediaanin')
            ->assertDontSee('€/kk yli markkinan mediaanin')
            ->assertDontSee('Sama kuin markkinan mediaani');
    }

    public function test_spot_section_renders_an_honest_fallback_when_the_seller_has_no_spot_contract(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Test Energy Oy: pörssisähkö, marginaali ja perusmaksu')
            ->assertSee('Test Energy Oy ei tarjoa tällä hetkellä kotitalouksille pörssisähkösopimusta Voltikan vertailussa.')
            ->assertSee('Vertaa kaikkia pörssisähkösopimuksia')
            ->assertDontSee('Test Energy Oy myy pörssisähköä.');
    }

    public function test_contract_list_heading_includes_the_company_name(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Test Energy Oy sähkösopimukset')
            ->assertDontSee('Test Energy Oy: sähkösopimukset');
    }

    public function test_company_page_has_no_faq_section_or_schema(): void
    {
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 3.90, pricingModel: 'Spot');

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $component
            ->assertDontSee('Usein kysyttyä')
            ->assertDontSee('Toimitusalue')
            ->assertDontSee('Missä Test Energy Oy myy sähköä?');
        $this->assertNull(collect($component->viewData('schemas'))->firstWhere('@type', 'FAQPage'));
    }

    // ----------------------------------------------------- market comparison

    public function test_comparison_row_places_the_seller_against_the_market_band(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'open_ended', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 40);
        $this->seedCompanySnapshot(segment: 'open_ended', annualCost: 600.0);

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);

        $this->assertNotNull($comparison);
        $this->assertCount(1, $comparison['rows']);

        $row = $comparison['rows'][0];

        $this->assertSame('open_ended', $row['segment_key']);
        $this->assertSame(600.0, $row['company_value']);
        $this->assertSame('in_band', $row['position']);
        $this->assertEqualsWithDelta(0.0, $row['delta_vs_median'], 0.001);

        // The marker sits on the median tick, and both are inside the band.
        $this->assertEqualsWithDelta($row['median_percent'], $row['marker_percent'], 0.01);
        $this->assertGreaterThan($row['band_left_percent'], $row['marker_percent']);
        $this->assertLessThan($row['band_left_percent'] + $row['band_width_percent'], $row['marker_percent']);
    }

    public function test_a_seller_cheaper_than_p20_is_marked_below_the_band(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'open_ended', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 40);
        $this->seedCompanySnapshot(segment: 'open_ended', annualCost: 420.0);

        $row = app(CompanyMarketComparisonService::class)
            ->forCompany($this->company->name, 5000)['rows'][0];

        $this->assertSame('below_p20', $row['position']);
        $this->assertLessThan($row['band_left_percent'], $row['marker_percent']);
        // The marker stays on the track even when it is outside the band.
        $this->assertGreaterThan(0.0, $row['marker_percent']);
    }

    /**
     * A band over a handful of contracts is noise. `/sahkosopimus/tilastot`
     * hides such rows and this page must agree.
     */
    public function test_a_thin_market_segment_is_not_used_as_a_reference(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(
            segment: 'open_ended',
            p20: 500.0,
            median: 600.0,
            p80: 700.0,
            contractCount: CompanyMarketComparisonService::MIN_MARKET_CONTRACTS - 1,
        );
        $this->seedCompanySnapshot(segment: 'open_ended', annualCost: 600.0);

        $this->assertNull(app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000));
    }

    /**
     * A snapshot with no energy price carries only the standing charge in its
     * annual-cost columns, so it would claim an impossible saving. This is the
     * Hybrid publication-gate artifact.
     */
    public function test_a_snapshot_without_an_energy_price_is_excluded(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'hybrid', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 40);
        $this->seedCompanySnapshot(segment: 'hybrid', annualCost: 59.0, energyPrice: 0.0);

        $this->assertNull(app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000));
    }

    public function test_a_canonical_annual_snapshot_does_not_require_a_unit_rate(): void
    {
        config()->set('canonical_pricing.enabled', true);
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(
            segment: 'open_ended',
            p20: 500.0,
            median: 600.0,
            p80: 700.0,
            contractCount: 40,
            pricingBasis: 'canonical_calculation',
        );
        $this->seedCompanySnapshot(
            segment: 'open_ended',
            annualCost: 610.0,
            energyPrice: null,
            pricingBasis: 'canonical_calculation',
        );

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);

        $this->assertNotNull($comparison);
        $this->assertSame(610.0, $comparison['rows'][0]['company_value']);
    }

    public function test_current_range_uses_the_latest_expected_basis_and_cache_fingerprint_tracks_rewrites(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket('open_ended', 500.0, 600.0, 700.0, 40, 'canonical_calculation', '2026-07-27');
        $this->seedCompanySnapshot('open_ended', 610.0, null, 'fixed', 'canonical_calculation', '2026-07-27');
        $this->seedMarket('open_ended', 100.0, 110.0, 120.0, 40, 'observed_seller_data', '2026-07-28');
        $this->seedCompanySnapshot('open_ended', 111.0, 8.0, 'fixed', 'observed_seller_data', '2026-07-28');
        // A mixed-basis row on the canonical date must not define the current basis.
        $this->seedMarket('spot', 200.0, 210.0, 220.0, 40, 'observed_seller_data', '2026-07-27');

        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $service = app(CompanyMarketComparisonService::class);
        $canonical = $service->forCompany($this->company->name, 5000);
        $fingerprintMethod = new \ReflectionMethod($service, 'fingerprint');
        $canonicalFingerprint = $fingerprintMethod->invoke($service);

        $this->assertSame('2026-07-27', $canonical['stat_date']);
        $this->assertSame('canonical_calculation', $canonical['pricing_basis']);
        $this->assertSame('current_canonical', $canonical['comparison_state']);
        $this->assertFalse($canonical['is_historical_fallback']);
        $this->assertSame(610.0, $canonical['rows'][0]['company_value']);
        $this->assertCount(1, $canonical['rows']);

        ContractPriceSnapshot::query()
            ->where('pricing_basis', 'canonical_calculation')
            ->update(['annual_cost_5000_kwh' => 620.0, 'updated_at' => now()->addMinute()]);
        $rewrittenFingerprint = $fingerprintMethod->invoke($service);
        $this->assertNotSame($canonicalFingerprint, $rewrittenFingerprint);

        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $service = app(CompanyMarketComparisonService::class);
        $fingerprintMethod = new \ReflectionMethod($service, 'fingerprint');
        $observedFingerprint = $fingerprintMethod->invoke($service);
        $observed = $service->forCompany($this->company->name, 5000);

        $this->assertNotSame($rewrittenFingerprint, $observedFingerprint);
        $this->assertSame('2026-07-28', $observed['stat_date']);
        $this->assertSame('observed_seller_data', $observed['pricing_basis']);
        $this->assertSame(111.0, $observed['rows'][0]['company_value']);
        $this->assertSame(3, ContractPriceDailyStatistic::count(), 'Historical and opposite-basis rows must remain stored.');
    }

    /**
     * A broken import gets past the shared statistics cleaner, because that
     * applies the 50 c/kWh ceiling to the `energy_price` metric while this
     * service reads `annual_cost`, whose ceiling is 50 000 EUR. Vaasan Sähkö's
     * "Kiinteä 12 kk (yösähkö)" was ingested at 585,46 c/kWh and drew a
     * 39 724 EUR/year spike on the trend chart.
     */
    public function test_an_implausible_energy_price_is_excluded(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'fixed_term_12', p20: 550.0, median: 580.0, p80: 610.0, contractCount: 49);
        $this->seedCompanySnapshot(segment: 'fixed_term_12', annualCost: 39723.85, energyPrice: 585.46);

        $this->assertNull(app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000));
    }

    /**
     * The statistics tables price three consumptions. A selection outside them
     * is snapped, and the page has to be able to say so.
     */
    public function test_an_unpriced_consumption_is_snapped_to_the_nearest_reference(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'open_ended', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 40);
        $this->seedCompanySnapshot(segment: 'open_ended', annualCost: 600.0);

        $service = app(CompanyMarketComparisonService::class);

        $snapped = $service->forCompany($this->company->name, 10000);
        $this->assertSame(5000, $snapped['reference_consumption']);
        $this->assertSame(10000, $snapped['selected_consumption']);
        $this->assertTrue($snapped['is_snapped']);

        $exact = $service->forCompany($this->company->name, 5000);
        $this->assertSame(5000, $exact['reference_consumption']);
        $this->assertFalse($exact['is_snapped']);
    }

    /**
     * Määräaikainen 12 kk is the type a visitor comparing sellers shops for, so
     * it wins even against a much larger market segment.
     */
    public function test_the_trend_chart_prefers_the_12_month_fixed_term(): void
    {
        $this->createContract('toistaiseksi', 'Jatkuva Sähkö', 8.0, 3.0);
        $this->createContract('vuosi', 'Vuoden Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'open_ended', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 62);
        $this->seedMarket(segment: 'fixed_term_12', p20: 550.0, median: 580.0, p80: 610.0, contractCount: 20);
        $this->seedCompanySnapshot(segment: 'open_ended', annualCost: 600.0, contractId: 'toistaiseksi');
        $this->seedCompanySnapshot(segment: 'fixed_term_12', annualCost: 580.0, contractId: 'vuosi');

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);

        $this->assertSame('fixed_term_12', $comparison['chart_segment_key']);
    }

    public function test_canonical_chart_combines_older_observed_history_with_canonical_points(): void
    {
        config()->set('canonical_pricing.enabled', true);
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);

        foreach (['2026-07-01', '2026-07-08', '2026-07-15'] as $index => $date) {
            $this->seedMarket('fixed_term_12', 500.0, 600.0 + $index, 700.0, 40, 'observed_seller_data', $date);
            $this->seedCompanySnapshot('fixed_term_12', 610.0 + $index, 8.0, 'fixed', 'observed_seller_data', $date);
        }

        foreach (['2026-07-22', '2026-07-29'] as $index => $date) {
            $this->seedMarket('fixed_term_12', 510.0, 610.0 + $index, 710.0, 40, 'canonical_calculation', $date);
            $this->seedCompanySnapshot('fixed_term_12', 620.0 + $index, null, 'fixed', 'canonical_calculation', $date);
        }

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);
        $chart = $comparison['chart'];

        $this->assertSame('current_canonical', $comparison['comparison_state']);
        $this->assertSame('2026-07-29', $comparison['stat_date']);
        $this->assertSame('canonical_calculation', $chart['current_pricing_basis']);
        $this->assertSame('2026-07-22', $chart['canonical_from']);
        $this->assertCount(5, array_filter($chart['series'][0]['values'], fn ($value) => $value !== null));
        $this->assertSame([610.0, 611.0, 612.0, 620.0, 621.0], $chart['series'][0]['values']);
    }

    /**
     * A seller with fixed terms but no 12-month product keeps a fixed-term
     * reference rather than dropping to the widest segment on the market.
     */
    public function test_the_trend_chart_falls_back_to_another_fixed_term(): void
    {
        $this->createContract('toistaiseksi', 'Jatkuva Sähkö', 8.0, 3.0);
        $this->createContract('kaksi-vuotta', 'Kahden Vuoden Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'open_ended', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 62);
        $this->seedMarket(segment: 'fixed_term_24', p20: 510.0, median: 540.0, p80: 570.0, contractCount: 49);
        $this->seedCompanySnapshot(segment: 'open_ended', annualCost: 600.0, contractId: 'toistaiseksi');
        $this->seedCompanySnapshot(segment: 'fixed_term_24', annualCost: 540.0, contractId: 'kaksi-vuotta');

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);

        $this->assertSame('fixed_term_24', $comparison['chart_segment_key']);
    }

    /**
     * A seller with no fixed-term product at all still gets a chart, on the
     * largest market segment it does sell. 12 of 35 sellers on 2026-07-24.
     */
    public function test_a_seller_without_any_fixed_term_uses_its_largest_market_segment(): void
    {
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 3.90, pricingModel: 'Spot');
        $this->createContract('kvartaali', 'Kvartaali Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'spot', p20: 400.0, median: 430.0, p80: 460.0, contractCount: 59);
        $this->seedMarket(segment: 'quarterly', p20: 440.0, median: 470.0, p80: 500.0, contractCount: 13);
        $this->seedCompanySnapshot(segment: 'spot', annualCost: 430.0, contractId: 'spot');
        $this->seedCompanySnapshot(segment: 'quarterly', annualCost: 470.0, contractId: 'kvartaali');

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);

        $this->assertSame('spot', $comparison['chart_segment_key']);
    }

    public function test_the_page_renders_the_comparison_section_when_reference_data_exists(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);
        $this->seedMarket(segment: 'open_ended', p20: 500.0, median: 600.0, p80: 700.0, contractCount: 40);
        $this->seedCompanySnapshot(segment: 'open_ended', annualCost: 600.0);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Test Energy Oy: sähkön hinta')
            ->assertSee('Sähkön hinta riippuu sopimustyypistä ja vuosikulutuksesta.')
            ->assertSee('saman sopimustyypin markkinamediaanin ja keskimmäisen 60 %:n hintahaarukan')
            ->assertSee('Toistaiseksi voimassa oleva');
    }

    public function test_canonical_mode_renders_a_dated_observed_fallback_with_the_trailing_chart(): void
    {
        config()->set('canonical_pricing.enabled', true);
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);

        foreach (['2026-07-01', '2026-07-08', '2026-07-15', '2026-07-22'] as $index => $date) {
            $this->seedMarket(
                segment: 'fixed_term_12',
                p20: 500.0 + $index,
                median: 600.0 + $index,
                p80: 700.0 + $index,
                contractCount: 40,
                pricingBasis: 'observed_seller_data',
                date: $date,
            );
            $this->seedCompanySnapshot(
                segment: 'fixed_term_12',
                annualCost: 610.0 + $index,
                pricingBasis: 'observed_seller_data',
                date: $date,
            );
        }

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $comparison = $component->viewData('marketComparison');

        $this->assertNotNull($comparison);
        $this->assertSame('historical_observed_fallback', $comparison['comparison_state']);
        $this->assertTrue($comparison['is_historical_fallback']);
        $this->assertSame('observed_seller_data', $comparison['pricing_basis']);
        $this->assertSame('2026-07-22', $comparison['stat_date']);
        $this->assertSame('fixed_term_12', $comparison['chart_segment_key']);
        $this->assertNotNull($comparison['chart']);
        $this->assertGreaterThanOrEqual(3, count(array_filter($comparison['chart']['series'][0]['values'])));

        $component
            ->assertSee('viimeisin yhtenäinen historiallinen hintavertailu 22.7.2026')
            ->assertSee('Se ei ole tämän päivän hintavertailu')
            ->assertSee('Kaikki pisteet ovat päivättyjä myyjiltä havaittuja hintoja');

        $service = app(CompanyMarketComparisonService::class);
        $fingerprintMethod = new \ReflectionMethod($service, 'fingerprint');
        $beforeRewrite = $fingerprintMethod->invoke($service);
        ContractPriceSnapshot::query()
            ->where('pricing_basis', 'observed_seller_data')
            ->whereDate('snapshot_date', '2026-07-22')
            ->update(['annual_cost_5000_kwh' => 620.0, 'updated_at' => now()->addMinute()]);

        $this->assertNotSame($beforeRewrite, $fingerprintMethod->invoke($service));
    }

    public function test_historical_fallback_never_exposes_spot_benchmarks_for_current_contract_facts(): void
    {
        config()->set('canonical_pricing.enabled', true);
        $this->createContract('spot', 'Pörssi Sähkö', 0.45, 3.90, pricingModel: 'Spot');
        $this->seedMarket('spot', 500.0, 600.0, 700.0, 40, 'observed_seller_data', '2026-07-22');
        $this->seedCompanySnapshot('spot', 610.0, 0.45, 'spot', 'observed_seller_data', '2026-07-22');
        $this->seedSpotBenchmark('spot_margin', 0.50, 40, 'observed_seller_data', '2026-07-22');
        $this->seedSpotBenchmark('monthly_fee', 4.00, 40, 'observed_seller_data', '2026-07-22');
        // Current-basis rows do not make the dated observed fallback compatible
        // with current canonical contract facts either.
        $this->seedSpotBenchmark('spot_margin', 0.60, 40, 'canonical_calculation', '2026-07-22');

        $comparison = app(CompanyMarketComparisonService::class)->forCompany($this->company->name, 5000);

        $this->assertSame('historical_observed_fallback', $comparison['comparison_state']);
        $this->assertSame('observed_seller_data', $comparison['pricing_basis']);
        $this->assertNull($comparison['spot_benchmarks']);
    }

    public function test_the_comparison_section_renders_an_honest_fallback_without_reference_data(): void
    {
        $this->createContract('fixed', 'Kiinteä Sähkö', 8.0, 3.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $this->assertNull($component->viewData('marketComparison'));
        $component
            ->assertSee('Test Energy Oy: sähkön hinta')
            ->assertSee('Yhtiön ja markkinan vertailukelpoista hintatietoa ei ole nyt saatavilla. Katso nykyiset sopimushinnat alta.')
            ->assertDontSee('sähkön hinta riippuu sopimustyypistä')
            ->assertDontSee('markkinan mediaani');
    }

    // --------------------------------------------------------------- helpers

    private function seedMarket(
        string $segment,
        float $p20,
        float $median,
        float $p80,
        int $contractCount,
        string $pricingBasis = 'observed_seller_data',
        ?string $date = null,
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date ?? now()->toDateString(),
            'segment_key' => $segment,
            'metric_key' => 'annual_cost',
            'pricing_basis' => $pricingBasis,
            'consumption_kwh' => 5000,
            'min_value' => $p20 - 50,
            'p20_value' => $p20,
            'avg_value' => $median,
            'median_value' => $median,
            'p80_value' => $p80,
            'max_value' => $p80 + 50,
            'contract_count' => $contractCount,
        ]);
    }

    private function seedSpotBenchmark(
        string $metric,
        ?float $median,
        int $contractCount = 40,
        string $pricingBasis = 'observed_seller_data',
        ?string $date = null,
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date ?? now()->toDateString(),
            'segment_key' => 'spot',
            'metric_key' => $metric,
            'pricing_basis' => $pricingBasis,
            'consumption_kwh' => null,
            'min_value' => $median === null ? null : $median - 0.20,
            'p20_value' => $median === null ? null : $median - 0.10,
            'avg_value' => $median,
            'median_value' => $median,
            'p80_value' => $median === null ? null : $median + 0.10,
            'max_value' => $median === null ? null : $median + 0.20,
            'contract_count' => $contractCount,
        ]);
    }

    private function seedCompanySnapshot(
        string $segment,
        float $annualCost,
        ?float $energyPrice = 8.0,
        string $contractId = 'fixed',
        string $pricingBasis = 'observed_seller_data',
        ?string $date = null,
    ): void {
        ContractPriceSnapshot::create([
            'snapshot_date' => $date ?? now()->toDateString(),
            'contract_id' => $contractId,
            'company_name' => $this->company->name,
            'contract_name' => 'Kiinteä Sähkö',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'segment_key' => $segment,
            'pricing_basis' => $pricingBasis,
            'energy_price_cents_per_kwh' => $energyPrice,
            'monthly_fee_eur' => 3.0,
            'annual_cost_2000_kwh' => $annualCost / 2,
            'annual_cost_5000_kwh' => $annualCost,
            'annual_cost_18000_kwh' => $annualCost * 3,
            'has_discount' => false,
            'includes_spot_price' => false,
        ]);
    }

    private function createContract(
        string $id,
        string $name,
        float $generalPrice,
        float $monthlyFee,
        ?string $pricingModel = null,
        ?float $discount = null,
        bool $discountOnMonthly = false,
        ?int $discountMonths = null,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $this->company->name,
            'name' => $name,
            'contract_type' => 'OpenEnded',
            'pricing_model' => $pricingModel ?? 'FixedPrice',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-'.$id.'-general',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $generalPrice,
            'payment_unit' => 'CentPerKiwattHour',
            'has_discount' => $discount !== null && ! $discountOnMonthly,
            'discount_value' => $discountOnMonthly ? null : $discount,
            'discount_is_percentage' => $discount !== null && ! $discountOnMonthly ? false : null,
        ]);

        PriceComponent::create([
            'id' => 'pc-'.$id.'-monthly',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => $monthlyFee,
            'payment_unit' => 'EurPerMonth',
            'has_discount' => $discount !== null && $discountOnMonthly,
            'discount_value' => $discountOnMonthly ? $discount : null,
            'discount_is_percentage' => $discountOnMonthly ? false : null,
            'discount_discount_n_first_months' => $discountOnMonthly ? $discountMonths : null,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        return $contract;
    }
}
