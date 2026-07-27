<?php

namespace Tests\Feature;

use App\Livewire\ArticleContractPriceComparisonChart;
use App\Livewire\ArticleSpotWinRateChart;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArticleSpotElectricityStatisticsQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-27 12:00:00');
        Cache::flush();
        config()->set('canonical_pricing.enabled', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_article_statistics_widgets_read_only_the_trailing_year_and_required_columns(): void
    {
        $this->seedProductionScaleStatistics();

        DB::enableQueryLog();

        $comparison = app(ArticleContractPriceComparisonChart::class)->preparedData;
        $winRate = app(ArticleSpotWinRateChart::class)->chartData;

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $dataReads = $queries->filter(
            fn (string $query) => str_contains($query, 'from "contract_price_daily_statistics"')
                && (str_contains($query, '"median_value"') || str_contains($query, '"p20_value"')),
        )->values();

        $this->assertCount(2, $dataReads);
        foreach ($dataReads as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query));
            $this->assertStringContainsString('"stat_date" between', $query);
            $this->assertStringContainsString('"pricing_basis"', $query);
            $this->assertStringNotContainsString('"min_value"', $query);
            $this->assertStringNotContainsString('"max_value"', $query);
        }

        $this->assertSame('2025-07-27', $comparison['data_window']['from']);
        $this->assertSame('2026-07-27', $comparison['data_window']['to']);
        $this->assertSame(900.0, $comparison['lead_chart']['series'][0]['values'][array_key_last($comparison['lead_chart']['series'][0]['values'])]);
        $this->assertNotContains(9999.0, $comparison['lead_chart']['series'][0]['values']);

        $this->assertSame('27.7.2026', $winRate['to']);
        $this->assertSame(900.0, $winRate['spot'][array_key_last($winRate['spot'])]);
        $this->assertNotContains(9999.0, $winRate['spot']);

        config()->set('canonical_pricing.enabled', false);
        Cache::flush();
        $observedComparison = app(ArticleContractPriceComparisonChart::class)->preparedData;

        $this->assertSame('2026-07-28', $observedComparison['data_window']['to']);
        $this->assertSame(5449.5, $observedComparison['lead_chart']['series'][0]['values'][array_key_last($observedComparison['lead_chart']['series'][0]['values'])]);
    }

    public function test_article_route_renders_with_production_scale_statistics(): void
    {
        $this->seedProductionScaleStatistics();
        Cache::flush();

        $response = $this->get('/sahkosopimus/kannattaako-porssisahko');

        $response->assertOk();
        $response->assertSee('Kannattaako pörssisähkö');
        $response->assertSee('Aineisto: 27.7.2025–27.7.2026');
    }

    private function seedProductionScaleStatistics(): void
    {
        $segments = ['spot', 'fixed_term_12', 'fixed_term_24', 'open_ended', 'hybrid'];
        $rows = [];

        foreach (CarbonPeriod::create('2023-01-01', '2026-07-26') as $date) {
            foreach ($segments as $index => $segment) {
                $value = 500 + ($index * 50);
                $rows[] = $this->statisticRow($date->toDateString(), $segment, $value, 'observed_seller_data');

                if (count($rows) === 500) {
                    DB::table('contract_price_daily_statistics')->insert($rows);
                    $rows = [];
                }
            }
        }

        foreach ($segments as $index => $segment) {
            $rows[] = $this->statisticRow('2026-07-27', $segment, 900 + ($index * 50), 'canonical_calculation');
            $rows[] = $this->statisticRow('2026-07-28', $segment, 9999, 'observed_seller_data');
        }

        if ($rows !== []) {
            DB::table('contract_price_daily_statistics')->insert($rows);
        }

        $this->assertGreaterThan(6000, DB::table('contract_price_daily_statistics')->count());
    }

    private function statisticRow(string $date, string $segment, float $value, string $pricingBasis): array
    {
        return [
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => 'annual_cost',
            'pricing_basis' => $pricingBasis,
            'consumption_kwh' => 5000,
            'min_value' => $value,
            'p20_value' => $value,
            'avg_value' => $value,
            'median_value' => $value,
            'p80_value' => $value,
            'max_value' => $value,
            'contract_count' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
