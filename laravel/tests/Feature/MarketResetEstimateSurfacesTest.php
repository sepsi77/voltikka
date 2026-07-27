<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\Caching\ContractPageCacheVersion;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\MarketReset\ResetEstimateCopy;
use App\Services\ContractListCacheService;
use App\Services\ContractRankingService;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The market-reset estimate has to reach visitors correctly and has to survive a flag flip.
 *
 * Cache participation is the part that cannot be skipped: CANONICAL_PRICING_ENABLED is already
 * true in production, so it cannot stage this change, and a cached hold-flat payload would
 * outlive the flip if RESET_FORWARD_SHIFT_ENABLED did not vary the keys.
 */
class MarketResetEstimateSurfacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_cache_version_changes_when_the_reset_shift_flag_flips(): void
    {
        $version = app(ContractPageCacheVersion::class);

        config(['canonical_pricing.reset_forward_shift.enabled' => false]);
        $this->assertSame(8, $version->version()['payload_schema_version']);
        $this->assertFalse($version->version()['reset_forward_shift_enabled']);
        $off = $version->hash();

        config(['canonical_pricing.reset_forward_shift.enabled' => true]);
        $this->assertTrue($version->version()['reset_forward_shift_enabled']);

        $this->assertNotSame($off, $version->hash());
    }

    public function test_list_metrics_cache_key_varies_by_the_reset_shift_flag(): void
    {
        Cache::flush();

        config(['canonical_pricing.reset_forward_shift.enabled' => false]);
        app()->forgetInstance(ContractListCacheService::class);
        app(ContractListCacheService::class)->getCachedMetrics(5000);
        $offKeys = $this->cacheKeysMatching('contract_list_metrics');

        config(['canonical_pricing.reset_forward_shift.enabled' => true]);
        app()->forgetInstance(ContractListCacheService::class);
        app(ContractListCacheService::class)->getCachedMetrics(5000);
        $allKeys = $this->cacheKeysMatching('contract_list_metrics');

        $this->assertNotEmpty($offKeys);
        $this->assertGreaterThan(count($offKeys), count($allKeys), 'flipping the flag must create a new cache entry, not reuse the old one');
        $this->assertNotEmpty(array_filter($allKeys, fn (string $key) => str_contains($key, 'c0r1')));
        $this->assertNotEmpty(array_filter($allKeys, fn (string $key) => str_contains($key, 'c0r0')));
    }

    public function test_ranking_cache_key_varies_by_the_reset_shift_flag(): void
    {
        Cache::flush();

        config(['canonical_pricing.reset_forward_shift.enabled' => false]);
        app()->forgetInstance(ContractRankingService::class);
        app(ContractRankingService::class)->getTotalActiveContracts();

        config(['canonical_pricing.reset_forward_shift.enabled' => true]);
        app()->forgetInstance(ContractRankingService::class);
        app(ContractRankingService::class)->getTotalActiveContracts();

        $keys = $this->cacheKeysMatching('contract_rankings');

        $this->assertNotEmpty(array_filter($keys, fn (string $key) => str_contains($key, ':s1:')));
        $this->assertNotEmpty(array_filter($keys, fn (string $key) => str_ends_with($key, ':r1')));
        $this->assertNotEmpty(array_filter($keys, fn (string $key) => str_ends_with($key, ':r0')));
    }

    public function test_ranking_cache_key_varies_by_the_contract_list_data_version(): void
    {
        Cache::flush();

        app(ContractRankingService::class)->getTotalActiveContracts();
        app(ContractListCacheService::class)->bumpVersion();
        app()->forgetInstance(ContractRankingService::class);
        app(ContractRankingService::class)->getTotalActiveContracts();

        $keys = $this->cacheKeysMatching('contract_rankings');

        $this->assertNotEmpty(array_filter($keys, fn (string $key) => str_contains($key, ':lv1:')));
        $this->assertNotEmpty(array_filter($keys, fn (string $key) => str_contains($key, ':lv2:')));
    }

    /**
     * The unit tests inject a fake curve, so this is the only check that the real container
     * wiring reaches the calculator: without the binding the estimator resolves to null and the
     * feature silently does nothing in production.
     */
    public function test_the_container_wires_the_estimator_into_the_canonical_calculator(): void
    {
        config([
            'canonical_pricing.enabled' => true,
            'canonical_pricing.reset_forward_shift.enabled' => true,
            'price_forecasting.fixed_term.vat_multiplier' => 1.255,
        ]);

        Company::create(['name' => 'Kausi Energia Oy', 'name_slug' => 'kausi-energia-oy', 'company_url' => 'https://kausi.fi']);

        foreach ([['month', '202607', 19.53], ['month', '202608', 41.64], ['month', '202609', 87.05], ['year', '202601', 60.0], ['year', '202701', 54.12]] as [$type, $maturity, $price]) {
            ElectricityFuturesEodPrice::create([
                'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base', 'area' => 'FI',
                'short_code' => $type === 'month' ? 'FNBM' : 'FNBY',
                'maturity' => $maturity, 'maturity_type' => $type,
                'trade_date' => '2026-07-24', 'settlement_price' => $price,
            ]);
        }

        $contract = ElectricityContract::create([
            'id' => 'reset-wiring-test',
            'name' => 'Kvartaalisähkö',
            'company_name' => 'Kausi Energia Oy',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => [
                'phases' => [[
                    'label' => 'recurring_period', 'phase_kind' => 'recurring_period',
                    'starts' => ['kind' => 'contract_start', 'value' => null],
                    'ends' => ['kind' => 'none', 'value' => null],
                    'components' => [[
                        'component_type' => 'energy_general', 'amount' => 8.0, 'normal_amount' => null,
                        'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'current',
                        'source_kind' => 'both', 'evidence' => [],
                    ]],
                    'evidence' => [],
                ]],
                'recurring_schedule' => [
                    'present' => true, 'cadence' => 'quarterly', 'current_period_start' => '2026-07-01',
                    'current_period_end' => '2026-09-30', 'future_price_known' => false, 'description' => null, 'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false, 'applies_to' => 'unknown', 'cadence' => 'none',
                    'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null, 'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null, 'hard_max_cents_per_kwh' => null, 'uncapped' => null,
                    'description' => null, 'evidence' => [],
                ],
            ],
            'canonical_calculation' => ['status' => 'estimate_required', 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete',
                'issue_codes' => ['recurring_reset_requires_estimate'],
            ],
        ]);

        $outcome = app(CanonicalContractPricingService::class)->evaluate(
            $contract,
            new EnergyUsage(total: 5000, basicLiving: 5000),
            null,
            CarbonImmutable::parse('2026-07-25', 'Europe/Helsinki'),
        )['outcome'];

        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $outcome->estimateMethod);
        $this->assertSame('quarter_month_average', $outcome->resetEstimate['reference_kind']);
        $this->assertSame('2026-Q3', $outcome->resetEstimate['anchor_period']);
        $this->assertSame('2026-10', $outcome->resetEstimate['tail_starts']);
        $this->assertGreaterThan(400.0, $outcome->totalCost, 'hold-flat would have been 400 EUR at 8,00 c/kWh');
        $this->assertNotNull(ResetEstimateCopy::receiptNote($outcome->toCalculatedCostArray()['reset_estimate']));
    }

    public function test_reset_copy_states_both_figures_in_plain_finnish(): void
    {
        $reset = [
            'basis' => 'forward_curve_shift',
            'cadence' => 'quarterly',
            'current_period_energy_price' => 5.54,
            'annual_equivalent_energy_price' => 8.05,
            'curve_trade_date' => '2026-07-24',
            'tail_starts' => '2026-10',
        ];

        $this->assertSame('12 kk arvio 8,05 c/kWh', ResetEstimateCopy::cardEquivalent($reset));

        $tooltip = ResetEstimateCopy::cardTooltip($reset);
        $this->assertStringContainsString('neljännesvuosittain', $tooltip);
        $this->assertStringContainsString('5,54 c/kWh', $tooltip);
        $this->assertStringContainsString('8,05 c/kWh', $tooltip);
        $this->assertStringContainsString('ei ole hintalupaus', $tooltip);

        // The detail page's receipt note deliberately states only what the hero
        // qualifier and the dated receipt rows do not: that future period prices are
        // unknown, when the estimated tail starts, and which forward vintage it reads.
        $note = ResetEstimateCopy::receiptNote($reset);
        $this->assertStringContainsString('ei tiedetä etukäteen', $note);
        $this->assertStringContainsString('10/2026', $note);
        $this->assertStringContainsString('24.7.2026', $note);
        $this->assertStringContainsString('tukkumarkkinan ennakkohintoihin eli sähköfutuureihin', $note);
    }

    public function test_reset_copy_marks_the_seasonal_index_fallback_as_such(): void
    {
        $note = ResetEstimateCopy::receiptNote([
            'basis' => 'spot_seasonal_index',
            'cadence' => 'monthly',
            'current_period_energy_price' => 7.0,
            'annual_equivalent_energy_price' => 11.5,
        ]);

        $this->assertStringContainsString('pörssisähkön usean vuoden kausivaihteluun', $note);
        $this->assertStringContainsString('tukkumarkkinan ennakkohintoja ei ollut saatavilla', $note);
    }

    public function test_reset_copy_is_absent_without_an_estimate(): void
    {
        $this->assertNull(ResetEstimateCopy::cardEquivalent(null));
        $this->assertNull(ResetEstimateCopy::cardTooltip(null));
        $this->assertNull(ResetEstimateCopy::receiptNote(null));
        $this->assertNull(ResetEstimateCopy::cardEquivalent(['annual_equivalent_energy_price' => null]));
    }

    /**
     * Tests run on the array cache store, so keys are read from its in-memory storage.
     *
     * @return list<string>
     */
    private function cacheKeysMatching(string $needle): array
    {
        $store = Cache::store()->getStore();
        $storage = (new \ReflectionClass($store))->getProperty('storage');
        $storage->setAccessible(true);

        return collect(array_keys((array) $storage->getValue($store)))
            ->map(fn ($key) => (string) $key)
            ->filter(fn (string $key) => str_contains($key, $needle))
            ->values()
            ->all();
    }
}
