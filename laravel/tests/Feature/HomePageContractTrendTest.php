<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomePageContractTrendTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_trend_uses_expected_basis_and_separates_feature_mode_caches(): void
    {
        config()->set('canonical_pricing.enabled', true);
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
        $legacyResponse = $this->get('/');
        $legacyResponse->assertSee('1 050 €/v', false);
        $legacyResponse->assertSee('Pisteet perustuvat kyseisinä päivinä havaittuihin myyjähintoihin.');
    }

    private function stat(
        string $date,
        string $segment,
        string $metric,
        ?int $consumption,
        float $value,
        string $basis,
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => $metric,
            'pricing_basis' => $basis,
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
