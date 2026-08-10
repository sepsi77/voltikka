<?php

namespace Tests\Feature;

use App\Livewire\SeoContractsList;
use App\Models\Company;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Models\FixedContractPriceForecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class FixedDurationContractsListingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('canonical_pricing.enabled', false);
        config()->set('price_forecasting.fixed_term.model_version', 'duration_listing_test');
        app()->forgetScopedInstances();

        $this->company = Company::create([
            'name' => 'Kesto Energia Oy',
            'name_slug' => 'kesto-energia-oy',
            'company_url' => 'https://example.test',
        ]);
    }

    public function test_exact_duration_routes_are_public_and_have_exact_defaults(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $route = Route::getRoutes()->getByName($case['route_name']);

            $this->assertNotNull($route);
            $this->assertSame('FixedTerm', $route->defaults['contractDuration'] ?? null);
            $this->assertSame($case['range'], $route->defaults['fixedTimeRange'] ?? null);

            $this->get($case['path'])
                ->assertOk()
                ->assertSeeText("{$months} kk määräaikainen sähkösopimus: vertaa hinnat")
                ->assertSeeText("Halvin {$months} kk sähkösopimus");
        }
    }

    public function test_each_page_filters_by_exact_structured_duration(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $this->fixedContract("fixed-{$months}", "Oma {$months} kk sopimus", $case['range']);
        }

        ElectricityContract::factory()
            ->forCompany($this->company)
            ->active()
            ->household()
            ->create([
                'id' => 'wrong-contract-type',
                'name' => 'Nimessä 6 kk mutta avoin',
                'contract_type' => 'OpenEnded',
                'fixed_time_range' => 'Fixed6',
            ]);

        foreach ($this->durationCases() as $months => $case) {
            $contracts = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $case['range'],
            ])->viewData('contracts');

            $this->assertSame(["fixed-{$months}"], $contracts->pluck('id')->all());
        }
    }

    public function test_each_page_has_unique_seo_content_canonical_heading_and_discovery_links(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $this->fixedContract("seo-{$months}", "SEO {$months} kk", $case['range']);

            $component = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $case['range'],
            ]);
            $seo = $component->viewData('seoData');

            $this->assertStringStartsWith("{$months} kk määräaikainen sähkösopimus: vertaa hinnat", $seo['title']);
            $this->assertStringEndsWith('| Voltikka', $seo['title']);
            $this->assertStringContainsString("{$months} kk", $seo['description']);
            $this->assertStringContainsString('hintoja ja ehtoja', $seo['description']);
            $this->assertStringContainsString('kehitys ja ennuste', $seo['description']);
            $this->assertStringContainsString('halvin vaihtoehto', $seo['description']);
            $this->assertStringEndsWith($case['path'], $seo['canonical']);
            $this->assertSame("{$months} kk määräaikainen sähkösopimus: vertaa hinnat", $component->viewData('pageHeading'));
            $this->assertStringContainsString("sovitun {$months} kuukauden ajan", $component->viewData('seoIntroText'));
            $this->assertStringContainsString('12 kuukauden vertailukustannuksia', $component->viewData('seoIntroText'));
            $this->assertStringContainsString('tarjottujen energiahintojen kehitystä ja ennustetta', $component->viewData('seoIntroText'));

            $component
                ->assertSeeText("Halvin {$months} kk sähkösopimus")
                ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-6-kk"')
                ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-12-kk"')
                ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-24-kk"');
        }
    }

    public function test_exact_results_heading_remains_visible_in_bill_mode(): void
    {
        $this->fixedContract('bill-fixed-6', 'Bill 6 kk', 'Fixed6');

        Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed6',
        ])
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 300)
            ->set('billTotalEur', 40.00)
            ->assertSeeText('Halvin 6 kk sähkösopimus');
    }

    public function test_each_page_selects_its_matching_unit_trend_and_eligible_forecast(): void
    {
        foreach ([6 => 6.60, 12 => 12.60, 24 => 24.60] as $months => $latestMedian) {
            $this->unitStatistic('2026-05-01', $months, $latestMedian - 0.60);
            $this->unitStatistic('2026-06-01', $months, $latestMedian);
            $this->unitStatistic('2026-06-02', $months, 99.90, 'canonical_calculation');
            $this->forecast('2026-06-01', $months);
            $this->forecast('2026-06-02', $months, 'canonical_calculation');
        }

        foreach ($this->durationCases() as $months => $case) {
            $insight = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $case['range'],
            ])->viewData('marketInsight');

            $this->assertTrue($insight['has_items']);
            $this->assertSame((float) ($months + 0.60), $insight['trend']['latest_value']);
            $this->assertSame("{$months} kk hintakehitys", $insight['trend']['eyebrow']);
            $this->assertSame($case['segment'], $insight['trend']['segment_key']);
            $this->assertSame($months, $insight['trend']['duration_months']);
            $this->assertSame('2026-05-01', $insight['trend']['previous_as_of']);
            $this->assertSame($months, $insight['forecast']['duration_months']);
            $this->assertSame("{$months} kk ennuste", $insight['forecast']['eyebrow']);
            $this->assertSame('2026-06-01', $insight['forecast']['forecast_date']);
        }
    }

    public function test_canonical_exact_trend_uses_matching_observed_history_and_canonical_forecast_provenance(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Cache::flush();

        foreach ([6, 12, 24] as $months) {
            $this->unitStatistic('2026-05-01', $months, (float) $months, 'observed_seller_data');
            $this->unitStatistic('2026-06-01', $months, $months + 1.0, 'canonical_calculation');
            $this->forecast('2026-06-01', $months, 'canonical_calculation');
        }
        $this->forecast('2026-06-02', 6, 'observed_seller_data');

        $insight = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed6',
        ])->viewData('marketInsight');

        $this->assertSame('fixed_term_6', $insight['trend']['segment_key']);
        $this->assertSame(7.0, $insight['trend']['latest_value']);
        $this->assertSame(6.0, $insight['trend']['previous_value']);
        $this->assertSame('canonical_calculation', $insight['trend']['latest_pricing_basis']);
        $this->assertSame('observed_seller_data', $insight['trend']['previous_pricing_basis']);
        $this->assertSame(6, $insight['forecast']['duration_months']);
        $this->assertSame('2026-06-01', $insight['forecast']['forecast_date']);
    }

    /**
     * @return array<int, array{path:string,route_name:string,range:string,segment:string}>
     */
    private function durationCases(): array
    {
        return [
            6 => [
                'path' => '/sahkosopimus/maaraaikainen-6-kk',
                'route_name' => 'seo.duration.maaraaikainen-6-kk',
                'range' => 'Fixed6',
                'segment' => 'fixed_term_6',
            ],
            12 => [
                'path' => '/sahkosopimus/maaraaikainen-12-kk',
                'route_name' => 'seo.duration.maaraaikainen-12-kk',
                'range' => 'Fixed12',
                'segment' => 'fixed_term_12',
            ],
            24 => [
                'path' => '/sahkosopimus/maaraaikainen-24-kk',
                'route_name' => 'seo.duration.maaraaikainen-24-kk',
                'range' => 'Fixed24',
                'segment' => 'fixed_term_24',
            ],
        ];
    }

    private function fixedContract(string $id, string $name, string $fixedTimeRange): ElectricityContract
    {
        return ElectricityContract::factory()
            ->forCompany($this->company)
            ->fixedTerm()
            ->active()
            ->household()
            ->create([
                'id' => $id,
                'name' => $name,
                'fixed_time_range' => $fixedTimeRange,
            ]);
    }

    private function unitStatistic(
        string $date,
        int $durationMonths,
        float $median,
        string $pricingBasis = 'observed_seller_data',
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => "fixed_term_{$durationMonths}",
            'metric_key' => 'energy_price',
            'pricing_basis' => $pricingBasis,
            'consumption_kwh' => null,
            'median_value' => $median,
            'contract_count' => $durationMonths,
        ]);
    }

    private function forecast(
        string $date,
        int $durationMonths,
        string $pricingBasis = 'observed_seller_data',
    ): void {
        FixedContractPriceForecast::create([
            'forecast_date' => $date,
            'target_date' => CarbonImmutable::parse($date)->addDays(30)->toDateString(),
            'horizon_days' => 30,
            'duration_months' => $durationMonths,
            'target_quantile' => 'median',
            'current_price_cents_per_kwh' => $durationMonths + 0.60,
            'forecast_price_cents_per_kwh' => $durationMonths + 0.70,
            'expected_change_cents_per_kwh' => 0.10,
            'hedge_cost_cents_per_kwh' => $durationMonths - 1.0,
            'retail_premium_cents_per_kwh' => 1.0,
            'normal_retail_premium_cents_per_kwh' => 1.1,
            'fair_price_cents_per_kwh' => $durationMonths + 0.80,
            'gap_cents_per_kwh' => 0.20,
            'futures_trade_date' => CarbonImmutable::parse($date)->subDay()->toDateString(),
            'coverage_quality' => 'all_monthly',
            'confidence' => 'low',
            'direction' => 'slightly_rising',
            'consumer_signal' => 'neutral',
            'contract_count' => $durationMonths,
            'model_version' => 'duration_listing_test',
            'source_metadata' => [
                'current_retail_pricing_basis' => $pricingBasis,
            ],
        ]);
    }
}
