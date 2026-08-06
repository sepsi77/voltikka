<?php

namespace Tests\Feature;

use App\Livewire\HomePage;
use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomePageContractTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_home_trend_uses_expected_basis_and_separates_feature_mode_caches(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Cache::flush();
        $segments = ['spot', 'fixed_term_12', 'open_ended', 'hybrid'];

        foreach ([
            ['date' => '2026-06-01', 'basis' => 'observed_seller_data', 'cost' => 600.0],
            ['date' => '2026-07-27', 'basis' => 'canonical_calculation', 'cost' => 900.0],
        ] as $day) {
            foreach ($segments as $offset => $segment) {
                $this->stat($day['date'], $segment, 'annual_cost', 5000, $day['cost'] + $offset * 10, $day['basis']);
                $this->stat($day['date'], $segment, 'energy_price', null, 99.0, $day['basis']);
            }
        }

        // A newer row from the opposite feature mode must not become the current
        // point while canonical pricing is enabled.
        foreach ($segments as $offset => $segment) {
            $this->stat('2026-07-28', $segment, 'annual_cost', 5000, 1200.0 + $offset * 10, 'observed_seller_data');
        }

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Sähkösopimusten vuosikustannus sopimustyypeittäin');
        $response->assertSee('Pörssisähkön vuosikustannus on vaihdellut 600–900 €/v', false);
        $response->assertDontSee('1 050 €/v', false);
        $response->assertSee('uusin laskelma käyttää kanonista nykyhintaa');
        $response->assertSee('vanhat pisteet säilyttävät oman keräyspäivänsä perusteen');
        $response->assertSee('"unit":"eur"', false);
        $response->assertDontSee('99,0 c/kWh');

        // A flag rollback has its own cache key and selects the latest observed
        // current point instead of serving the canonical payload.
        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $legacyResponse = $this->get('/');
        $legacyResponse->assertSee('1 050 €/v', false);
        $legacyResponse->assertSee('Pisteet perustuvat kyseisinä päivinä havaittuihin myyjähintoihin.');
    }

    public function test_home_trend_gaps_the_first_week_after_an_annual_method_transition(): void
    {
        Carbon::setTestNow('2026-05-20 12:00:00');
        Cache::flush();
        config()->set('canonical_pricing.enabled', true);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);
        app()->forgetScopedInstances();

        foreach ([
            ['2026-05-04', 100.0, 'rolling'],
            ['2026-05-11', 200.0, 'forward'],
            ['2026-05-18', 220.0, 'forward'],
        ] as [$date, $value, $key]) {
            foreach (['spot', 'fixed_term_12', 'open_ended', 'hybrid'] as $offset => $segment) {
                $this->stat($date, $segment, 'annual_cost', 5000, $value + $offset, 'canonical_calculation', $key, AnnualCostMethodVersion::AsOf);
            }
        }

        $method = new \ReflectionMethod(app(HomePage::class), 'getContractPriceTrend');
        $trend = $method->invoke(app(HomePage::class));

        $this->assertSame([100.0, null, 220.0], $trend['series'][0]['values']);
        $this->assertNull($trend['caption'], 'A caption must not pool the rolling and forward regimes.');
    }

    private function stat(
        string $date,
        string $segment,
        string $metric,
        ?int $consumption,
        float $value,
        string $basis,
        ?string $compatibilityKey = null,
        AnnualCostMethodVersion $methodVersion = AnnualCostMethodVersion::Legacy,
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => $metric,
            'pricing_basis' => $basis,
            'method_version' => $metric === 'annual_cost'
                ? $methodVersion->value
                : ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            'compatibility_key' => $compatibilityKey,
            'consumption_kwh' => $consumption,
            'min_value' => $value,
            'p20_value' => $value,
            'avg_value' => $value,
            'median_value' => $value,
            'p80_value' => $value,
            'max_value' => $value,
            'contract_count' => 20,
        ]);
    }
}
