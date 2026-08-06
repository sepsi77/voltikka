<?php

namespace Tests\Feature;

use App\Livewire\ArticleSpotElectricity;
use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StatisticsBasisConsumersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->assertSame(700.0, $aggregate['latest_value']);
        $this->assertSame(600.0, $aggregate['previous_value']);

        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $observed = app(ContractMarketInsightService::class)->insight('spot', 5000)['trend'];

        $this->assertSame(100.0, $observed['latest_value']);
        $this->assertSame('observed_seller_data', $observed['latest_pricing_basis']);
        $this->assertSame(6, ContractPriceDailyStatistic::count());
    }

    public function test_aggregate_market_insight_uses_a_per_segment_compatibility_map(): void
    {
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);

        foreach ([
            ['2026-05-01', 'spot', 500.0, 10, 'spot-key'],
            ['2026-05-01', 'fixed_term_12', 700.0, 10, 'fixed-key'],
            ['2026-05-01', 'open_ended', 900.0, 100, 'old-open-key'],
            ['2026-06-01', 'spot', 600.0, 10, 'spot-key'],
            ['2026-06-01', 'fixed_term_12', 800.0, 10, 'fixed-key'],
            ['2026-06-01', 'open_ended', 100.0, 100, 'new-open-key'],
        ] as [$date, $segment, $value, $count, $key]) {
            $this->stat(
                $date,
                $segment,
                $value,
                'observed_seller_data',
                $count,
                AnnualCostMethodVersion::AsOf,
                $key,
            );
        }

        $trend = app(ContractMarketInsightService::class)->insight(null, 5000)['trend'];

        $this->assertSame(700.0, $trend['latest_value']);
        $this->assertSame(600.0, $trend['previous_value']);
        $this->assertSame(20, $trend['contract_count']);
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
