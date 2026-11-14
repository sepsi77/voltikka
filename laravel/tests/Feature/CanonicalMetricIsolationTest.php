<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture as F;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * One calculator result that the strict reader rejects must exclude only that contract.
 * On 2026-11-14 one new Hybrid result stopped every price cache build and the morning chain.
 */
class CanonicalMetricIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'Isolation Oy', 'name_slug' => 'isolation-oy']);
        config()->set('canonical_pricing.enabled', true);
        $this->app->forgetScopedInstances();
    }

    public function test_a_rejected_result_excludes_only_its_contract(): void
    {
        [$rejected, $fixed] = $this->contracts();
        Log::spy();

        $metrics = app(CanonicalContractPricingService::class)
            ->withSpotAssumptions(new SpotAssumptions(6, 4))
            ->metricsForContracts(collect([$rejected, $fixed]), new EnergyUsage(total: 5000, basicLiving: 5000),
                CarbonImmutable::parse('2026-11-14', 'Europe/Helsinki'));

        $this->assertFalse($metrics['rejected-hybrid']->isListed());
        $this->assertNull($metrics['rejected-hybrid']->sortKey());
        $this->assertSame('excluded_incomplete', $metrics['rejected-hybrid']->pricing()->toArray()['comparability']);
        $this->assertTrue($metrics['plain-fixed']->isListed());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['contract_id'] === 'rejected-hybrid'
            && $context['comparability'] === 'base_only_hybrid'
            && $context['exception_class'] === \InvalidArgumentException::class);
    }

    public function test_the_price_cache_build_succeeds_without_the_rejected_contract(): void
    {
        $this->contracts();
        $this->app->forgetScopedInstances();

        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));

        $set = app(ContractListCacheService::class)->getCachedMetrics(5000);
        $this->assertNotNull($set);
        $this->assertFalse($set->metric('rejected-hybrid')->isListed());
        $this->assertTrue($set->metric('plain-fixed')->isListed());
    }

    /** @return array{ElectricityContract, ElectricityContract} */
    private function contracts(): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/kerava-perusfiksu-24kk-hybrid-spot-transport.json')), true);
        $rejected = ElectricityContract::factory()->forCompany('Isolation Oy')->active()->create([
            'id' => 'rejected-hybrid', ...$fixture['contract'],
            'canonical_pricing' => $fixture['canonical_pricing'],
            'canonical_source_consistency' => $fixture['canonical_source_consistency'],
            'canonical_calculation' => $fixture['canonical_calculation'],
        ]);
        $fixed = ElectricityContract::factory()->forCompany('Isolation Oy')->active()->create([
            'id' => 'plain-fixed', 'pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', ...F::fixedAttributes(),
        ]);

        return [$rejected, $fixed];
    }
}
