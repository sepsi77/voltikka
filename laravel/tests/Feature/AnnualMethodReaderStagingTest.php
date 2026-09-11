<?php

namespace Tests\Feature;

use App\Livewire\ArticleSpotElectricity;
use App\Livewire\ConsumptionCalculator;
use App\Livewire\ContractPriceStatistics;
use App\Livewire\HomePage;
use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnualMethodReaderStagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.enabled', true);
        $this->activate(AnnualCostMethodVersion::AsOf);
    }

    public function test_units_advance_while_active_annual_panels_keep_their_own_dated_endpoint(): void
    {
        $this->seedStaging();
        $page = app(ContractPriceStatistics::class)->warmPreparedViewDataCache();
        $this->assertSame('2026-06-02', $page['dataWindow']['to']);
        $this->assertSame('2026-06-01', $page['annualDataDate']);
        $this->assertTrue($page['annualDataIsRetained']);
        $this->assertSame(600.0, $page['consumptionRows'][0]['median']);
        $this->assertFalse($page['consumptionRows'][0]['is_current']);
        $this->assertSame('2026-06-01', $page['consumptionRows'][0]['latest_observation_date']);
        $this->get('/sahkosopimus/tilastot')->assertOk()
            ->assertSee('Vuosihinnat ovat päivältä 1.6.2026.')
            ->assertSee('Ne ovat historiallisia arvioita, eivät tämän päivän hintavertailu.');

        $this->activate(AnnualCostMethodVersion::AsOfV2);
        $corrected = app(ContractPriceStatistics::class)->warmPreparedViewDataCache();
        $this->assertSame('2026-06-02', $corrected['annualDataDate']);
        $this->assertFalse($corrected['annualDataIsRetained']);
        $this->assertSame(900.0, $corrected['consumptionRows'][0]['median']);

        $this->activate(AnnualCostMethodVersion::AsOf);
        $this->assertSame(600.0, app(ContractPriceStatistics::class)->warmPreparedViewDataCache()['consumptionRows'][0]['median']);
    }

    public function test_inactive_corrected_rows_do_not_fill_missing_active_annual_data(): void
    {
        $this->stat('2026-06-02', 'spot', 900.0, AnnualCostMethodVersion::AsOfV2);
        $this->unit('2026-06-02');
        $page = app(ContractPriceStatistics::class)->warmPreparedViewDataCache();
        $this->assertNull($page['annualDataDate']);
        $this->assertSame([], $page['consumptionRows']);
        $this->assertSame([], $this->consumption()['rows']);
        $this->assertSame([], app(ArticleSpotElectricity::class)->marketSnapshot);
    }

    public function test_consumption_article_home_and_insight_select_only_the_active_method_and_switch_back(): void
    {
        $this->seedStaging();
        // The same-date inactive value also tests the calculator's second query.
        $this->stat('2026-06-01', 'spot', 950.0, AnnualCostMethodVersion::AsOfV2);
        foreach ([AnnualCostMethodVersion::AsOf, AnnualCostMethodVersion::AsOfV2, AnnualCostMethodVersion::AsOf] as $method) {
            $this->activate($method);
            $expected = $method === AnnualCostMethodVersion::AsOf ? 600.0 : 900.0;
            $this->assertSame($expected, $this->consumption()['rows'][0]['costs']['median']['annual']);
            $this->assertSame($expected, app(ArticleSpotElectricity::class)->marketSnapshot['spot']);
            $trendMethod = new \ReflectionMethod(HomePage::class, 'getContractPriceTrend');
            $home = $trendMethod->invoke(app(HomePage::class));
            $this->assertSame($method === AnnualCostMethodVersion::AsOf ? 600.0 : 925.0, collect($home['series'][0]['values'])->filter(fn ($value) => $value !== null)->last());
            $insight = app(ContractMarketInsightService::class)->insight('spot', 5000)['trend'];
            $this->assertSame($expected, $insight['latest_value']);
        }
    }

    public function test_csv_keeps_both_audit_methods_and_marks_only_the_selected_one_active(): void
    {
        $this->seedStaging();
        foreach ([AnnualCostMethodVersion::AsOf, AnnualCostMethodVersion::AsOfV2, AnnualCostMethodVersion::AsOf] as $method) {
            $this->activate($method);
            $response = $this->get('/sahkosopimus/tilastot.csv')->assertOk();
            $rows = collect(explode("\n", $response->streamedContent()))
                ->map(fn (string $line): array => str_getcsv($line, escape: ''))
                ->filter(fn (array $row): bool => ($row[2] ?? null) === 'annual_cost');
            $this->assertTrue($rows->contains(fn (array $row): bool => $row[4] === AnnualCostMethodVersion::AsOf->value));
            $this->assertTrue($rows->contains(fn (array $row): bool => $row[4] === AnnualCostMethodVersion::AsOfV2->value));
            foreach ($rows as $row) {
                $this->assertSame($row[4] === $method->value ? '1' : '0', $row[9]);
            }
        }
    }

    public function test_wrong_basis_is_not_used_as_the_retained_active_endpoint(): void
    {
        $this->stat('2026-06-01', 'spot', 600.0, AnnualCostMethodVersion::AsOf);
        $this->unit('2026-06-02');
        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $this->unit('2026-06-03', 'observed_seller_data');
        $this->assertNull(app(ContractPriceStatistics::class)->annualDataDate);
        $this->assertSame([], $this->consumption()['rows']);
    }

    private function activate(AnnualCostMethodVersion $method): void
    {
        config()->set('contract_statistics.annual_cost.active_method_version', $method->value);
        app()->forgetScopedInstances();
    }

    private function consumption(): array
    {
        $method = new \ReflectionMethod(ConsumptionCalculator::class, 'priceEstimatesFor');

        return $method->invoke(app(ConsumptionCalculator::class), 5000);
    }

    private function seedStaging(): void
    {
        foreach (['spot', 'fixed_term_12', 'open_ended', 'hybrid'] as $segment) {
            foreach ([AnnualCostMethodVersion::AsOf, AnnualCostMethodVersion::AsOfV2] as $method) {
                $this->stat('2026-05-01', $segment, 500.0, $method);
                $this->stat('2026-05-08', $segment, 550.0, $method);
                $this->stat($method === AnnualCostMethodVersion::AsOf ? '2026-06-01' : '2026-06-02', $segment, $method === AnnualCostMethodVersion::AsOf ? 600.0 : 900.0, $method);
            }
        }
        ContractPriceDailyStatistic::whereDate('stat_date', '2026-05-01')->update(['pricing_basis' => 'observed_seller_data']);
        $this->unit('2026-06-02');
    }

    private function unit(string $date, string $basis = 'canonical_calculation'): void
    {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date, 'segment_key' => 'fixed_term_12', 'metric_key' => 'energy_price',
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            'pricing_basis' => $basis, 'contract_count' => 20, 'median_value' => 8.0,
            'p20_value' => 7.0, 'p80_value' => 9.0,
        ]);
    }

    private function stat(string $date, string $segment, float $value, AnnualCostMethodVersion $method): void
    {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date, 'segment_key' => $segment, 'metric_key' => 'annual_cost',
            'method_version' => $method->value, 'pricing_basis' => 'canonical_calculation',
            'consumption_kwh' => 5000, 'contract_count' => 20, 'median_value' => $value,
            'p20_value' => $value, 'p80_value' => $value, 'avg_value' => $value,
            'compatibility_key' => $method->value, 'basis_counts' => ['estimate_method' => ['forward_curve' => 20]],
        ]);
    }
}
