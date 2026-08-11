<?php

namespace Tests\Feature;

use App\Livewire\ArticleSpotElectricity;
use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StatisticsBasisConsumersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::Legacy->value);
        app()->forgetScopedInstances();
        Cache::flush();
    }

    public function test_article_snapshot_uses_expected_basis_and_same_day_updates_invalidate_its_cache(): void
    {
        foreach (['spot' => 600.0, 'fixed_term_12' => 800.0] as $segment => $value) {
            $this->stat('2026-07-27', $segment, $value, 'canonical_calculation');
        }
        $this->stat('2026-07-27', 'open_ended', 50.0, 'observed_seller_data');
        foreach (['spot' => 100.0, 'fixed_term_12' => 200.0, 'open_ended' => 300.0] as $segment => $value) {
            $this->stat('2026-07-28', $segment, $value, 'observed_seller_data');
        }

        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $canonical = app(ArticleSpotElectricity::class)->marketSnapshot;

        $this->assertSame('canonical_calculation', $canonical['pricing_basis']);
        $this->assertSame('27.7.2026', $canonical['date']);
        $this->assertSame(600.0, $canonical['spot']);
        $this->assertNull($canonical['openEnded']);

        ContractPriceDailyStatistic::query()
            ->where('pricing_basis', 'canonical_calculation')
            ->where('segment_key', 'spot')
            ->update(['median_value' => 650.0, 'updated_at' => now()->addMinute()]);

        $rewritten = app(ArticleSpotElectricity::class)->marketSnapshot;
        $this->assertSame(650.0, $rewritten['spot']);

        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $observed = app(ArticleSpotElectricity::class)->marketSnapshot;

        $this->assertSame('observed_seller_data', $observed['pricing_basis']);
        $this->assertSame('28.7.2026', $observed['date']);
        $this->assertSame(100.0, $observed['spot']);
        $this->assertSame(6, ContractPriceDailyStatistic::count(), 'Reading the current snapshot must preserve historical rows.');
    }

    public function test_shadow_as_of_rows_are_isolated_until_the_active_method_changes(): void
    {
        $this->stat('2026-07-28', 'spot', 600.0, 'observed_seller_data');
        $this->stat('2026-07-28', 'fixed_term_12', 800.0, 'observed_seller_data');
        $this->stat(
            '2026-07-28',
            'spot',
            60.0,
            'observed_seller_data',
            methodVersion: AnnualCostMethodVersion::AsOf,
            compatibilityKey: 'as-of-spot',
        );

        $legacy = app(ArticleSpotElectricity::class)->marketSnapshot;
        $this->assertSame(600.0, $legacy['spot']);
        $this->assertSame(800.0, $legacy['fixed']);

        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);
        app()->forgetScopedInstances();
        $asOf = app(ArticleSpotElectricity::class)->marketSnapshot;

        $this->assertSame(60.0, $asOf['spot']);
        $this->assertNull($asOf['fixed'], 'An active AsOf query must not fill a missing segment from legacy rows.');
    }

    public function test_listing_annual_trend_does_not_cross_compatibility_keys(): void
    {
        $this->stat(
            '2026-05-01',
            'spot',
            500.0,
            'observed_seller_data',
            methodVersion: AnnualCostMethodVersion::AsOf,
            compatibilityKey: 'key-a',
        );
        $this->stat(
            '2026-06-01',
            'spot',
            600.0,
            'observed_seller_data',
            methodVersion: AnnualCostMethodVersion::AsOf,
            compatibilityKey: 'key-b',
        );
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);

        $this->assertNull(app(ContractMarketInsightService::class)->insight('spot', 5000)['trend']);
    }

    public function test_listing_insights_use_expected_current_basis_and_explicit_observed_history(): void
    {
        foreach ([
            ['2026-05-01', 'spot', 500.0, 'observed_seller_data', 10],
            ['2026-05-01', 'fixed_term_12', 700.0, 'observed_seller_data', 10],
            ['2026-06-01', 'spot', 600.0, 'canonical_calculation', 10],
            ['2026-06-01', 'fixed_term_12', 800.0, 'canonical_calculation', 10],
            ['2026-06-01', 'open_ended', 1.0, 'observed_seller_data', 100],
            ['2026-06-02', 'spot', 100.0, 'observed_seller_data', 10],
        ] as [$date, $segment, $value, $basis, $count]) {
            $this->stat($date, $segment, $value, $basis, $count);
        }

        $this->indexStat('2026-05-02', 7.0, 40, 20);
        $this->indexStat('2026-06-01', 8.0, 42, 21);

        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $service = app(ContractMarketInsightService::class);
        $segment = $service->insight('spot', 5000)['trend'];
        $aggregate = $service->insight(null, 5000)['trend'];

        $this->assertSame(600.0, $segment['latest_value']);
        $this->assertSame(500.0, $segment['previous_value']);
        $this->assertSame('canonical_calculation', $segment['latest_pricing_basis']);
        $this->assertSame('observed_seller_data', $segment['previous_pricing_basis']);
        $this->assertStringContainsString('Kanoninen nykyarvio', $segment['supporting']);
        $this->assertSame(8.0, $aggregate['latest_value']);
        $this->assertSame(7.0, $aggregate['previous_value']);
        $this->assertSame(42, $aggregate['contract_count']);
        $this->assertSame(21, $aggregate['supplier_count']);

        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $observedService = app(ContractMarketInsightService::class);
        $observed = $observedService->insight('spot', 5000)['trend'];

        $this->assertSame(100.0, $observed['latest_value']);
        $this->assertSame('observed_seller_data', $observed['latest_pricing_basis']);
        $this->assertNull($observedService->insight(null, 5000)['trend']);
        $this->assertSame(8, ContractPriceDailyStatistic::count());
    }

    public function test_aggregate_market_insight_uses_the_exact_30_day_index_row_and_main_copy_counts(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $this->indexStat('2026-08-10', 4.0, 38, 18);
        $this->indexStat('2026-08-11', 8.0, 40, 20);
        $this->indexStat('2026-08-12', 20.0, 41, 20);
        $this->indexStat('2026-09-10', 10.0, 44, 22);
        $this->stat('2026-09-11', 'spot', 999.0, 'canonical_calculation');

        $trend = app(ContractMarketInsightService::class)->insight(null, 18000)['trend'];

        $this->assertSame(10.0, $trend['latest_value']);
        $this->assertSame(8.0, $trend['previous_value']);
        $this->assertSame('2026-08-11', $trend['previous_as_of']);
        $this->assertSame(44, $trend['contract_count']);
        $this->assertSame(22, $trend['supplier_count']);
        $this->assertSame('Sähkösopimusten energianhintaindeksi', $trend['eyebrow']);
        $this->assertStringContainsString('sähkösopimusten energiahintoja Suomessa', $trend['supporting']);
        $this->assertStringContainsString('Pörssisähkö ei ole mukana', $trend['supporting']);

        $html = Blade::render('<x-contract-market-insight-pills :insight="$insight" />', [
            'insight' => ['trend' => $trend, 'forecast' => null, 'has_items' => true],
        ]);
        $this->assertStringContainsString('Sähkösopimusten energianhintaindeksi', $html);
        $this->assertStringContainsString('44</span> tarjoukseen', $html);
        $this->assertStringContainsString('22</span> sähköyhtiöltä', $html);
        $this->assertStringNotContainsString('vuosikustannus', mb_strtolower($html));
    }

    public function test_aggregate_market_insight_has_no_annual_cost_or_nearby_date_fallback(): void
    {
        $this->indexStat('2026-08-12', 8.0, 40, 20);
        $this->indexStat('2026-09-10', 10.0, 44, 22);
        $this->stat('2026-08-11', 'spot', 500.0, 'canonical_calculation');
        $this->stat('2026-09-10', 'spot', 600.0, 'canonical_calculation');

        $this->assertNull(app(ContractMarketInsightService::class)->insight(null, 2000)['trend']);
    }

    public function test_market_reset_insight_waits_for_same_segment_canonical_history(): void
    {
        $this->stat('2026-05-01', 'quarterly', 400.0, 'observed_seller_data');
        $this->stat('2026-05-01', 'market_reset', 600.0, 'canonical_calculation');
        $this->stat('2026-06-01', 'market_reset', 660.0, 'canonical_calculation');

        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $trend = app(ContractMarketInsightService::class)->insight('market_reset', 5000)['trend'];

        $this->assertSame(660.0, $trend['latest_value']);
        $this->assertSame(600.0, $trend['previous_value']);
        $this->assertSame('canonical_calculation', $trend['latest_pricing_basis']);
        $this->assertSame('canonical_calculation', $trend['previous_pricing_basis']);
        $this->assertStringContainsString('aiempaan kanoniseen arvioon', $trend['supporting']);
        $this->assertNotSame(400.0, $trend['previous_value']);
    }

    private function indexStat(string $date, float $value, int $contractCount, int $supplierCount): void
    {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => SellerSetEnergyPriceIndexService::SEGMENT_OVERALL,
            'metric_key' => SellerSetEnergyPriceIndexService::METRIC_KEY,
            'pricing_basis' => 'canonical_calculation',
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            'calculation_basis' => SellerSetEnergyPriceIndexService::CALCULATION_BASIS,
            'estimate_basis' => SellerSetEnergyPriceIndexService::ESTIMATE_BASIS,
            'compatibility_key' => SellerSetEnergyPriceIndexService::COMPATIBILITY_KEY,
            'basis_counts' => [
                'contract_count' => $contractCount,
                'supplier_count' => $supplierCount,
                'family_weights' => SellerSetEnergyPriceIndexService::FAMILY_WEIGHTS,
            ],
            'consumption_kwh' => null,
            'avg_value' => $value,
            'contract_count' => $contractCount,
        ]);
    }

    private function stat(
        string $date,
        string $segment,
        float $value,
        string $pricingBasis,
        int $contractCount = 20,
        AnnualCostMethodVersion $methodVersion = AnnualCostMethodVersion::Legacy,
        ?string $compatibilityKey = null,
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => 'annual_cost',
            'pricing_basis' => $pricingBasis,
            'method_version' => $methodVersion->value,
            'compatibility_key' => $compatibilityKey,
            'consumption_kwh' => 5000,
            'min_value' => $value,
            'p20_value' => $value,
            'avg_value' => $value,
            'median_value' => $value,
            'p80_value' => $value,
            'max_value' => $value,
            'contract_count' => $contractCount,
        ]);
    }
}
