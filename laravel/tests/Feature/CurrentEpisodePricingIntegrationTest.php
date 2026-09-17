<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CurrentEpisodePricingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'Episode Integration', 'name_slug' => 'episode-integration']);
        config()->set('canonical_pricing.enabled', true);
        $this->app->forgetScopedInstances();
    }

    #[DataProvider('entryPoints')]
    public function test_every_current_entry_point_bounds_real_episode_evidence_by_its_comparison_date(string $entry): void
    {
        $contract = $this->contract();
        $rate = $contract->canonical_pricing['phases'][0]['components'][0]['amount'];
        foreach (['2026-06-01' => $rate, '2026-07-01' => $rate + 2] as $date => $energy) {
            DB::table('contract_price_snapshots')->insert([
                'contract_id' => $contract->id,
                'snapshot_date' => $date,
                'company_name' => $contract->company_name,
                'contract_name' => $contract->name,
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'pricing_model' => 'FixedPrice',
                'pricing_basis' => 'canonical_calculation',
                'segment_key' => 'open_ended',
                'energy_price_cents_per_kwh' => $energy,
            ]);
        }
        $service = app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(null, null));
        $date = CarbonImmutable::parse('2026-06-15', 'Europe/Helsinki');
        $usage = new EnergyUsage(total: 5000, basicLiving: 5000);
        $estimate = match ($entry) {
            'single' => $service->evaluate($contract, $usage, startDate: $date)['outcome']->supplierAdjustedEstimate,
            'metrics' => $service->metricsForContracts(collect([$contract]), $usage, $date)[$contract->id]->pricing()->toArray()['supplier_adjusted_estimate'],
            'statistics' => $service->outcomesForContractsAtConsumptions(collect([$contract]), [2000, 5000, 18000], new SpotAssumptions(null, null), $date)[$contract->id][5000]->supplierAdjustedEstimate,
            'period' => $service->periodEvaluationsForContracts(collect([$contract]), new CanonicalPeriodPricingRequest($date, $date->addDays(2), 20, 5000, []))[$contract->id]['annual']->supplierAdjustedEstimate,
        };
        $this->assertSame('2026-06-01', $estimate['price_episode_started_at']);
        $this->assertSame('canonical_snapshot_run', $estimate['price_episode_evidence_basis']);
        $this->assertContains('price_episode_right_observation_gap', $estimate['flags']);
    }

    public static function entryPoints(): array
    {
        return [['single'], ['metrics'], ['statistics'], ['period']];
    }

    public function test_memo_uses_full_energy_signature_and_date_but_not_fee_and_reset_clears_it(): void
    {
        $resolver = new RecordingCurrentEpisodeResolver;
        $service = new CanonicalContractPricingService(
            app(CanonicalContractPriceCalculator::class),
            app(PricingMode::class),
            priceEpisodeResolver: $resolver,
        );
        $contract = $this->contract();
        $contract->metering = 'Time';
        $this->rates($contract, 10, 6, 4);
        $date = CarbonImmutable::parse('2026-06-15', 'Europe/Helsinki');
        $usage = new EnergyUsage(total: 5000, basicLiving: 5000);
        $spot = new SpotAssumptions(null, null);
        $service->evaluate($contract, $usage, $spot, $date);
        $service->evaluate($contract, new EnergyUsage(total: 2000, basicLiving: 2000), $spot, $date);
        $this->rates($contract, 10, 6, 9);
        $service->evaluate($contract, $usage, $spot, $date);
        $this->assertCount(1, $resolver->batches);

        // Both tariffs have the same old 15/9-weighted representative: 8.5.
        $this->rates($contract, 9.4, 7, 9);
        $service->evaluate($contract, $usage, $spot, $date);
        $this->assertCount(2, $resolver->batches);
        $service->evaluate($contract, $usage, $spot, $date->addDay());
        $this->assertSame(['2026-06-15', '2026-06-15', '2026-06-16'], array_column($resolver->batches, 'date'));
        $service->resetMemoization();
        $service->evaluate($contract, $usage, $spot, $date->addDay());
        $this->assertCount(4, $resolver->batches);
    }

    private function contract(): ElectricityContract
    {
        return ElectricityContract::factory()->forCompany('Episode Integration')->create([
            'id' => 'episode-integration',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            ...CanonicalPricingFixture::fixedAttributes(),
        ]);
    }

    private function rates(ElectricityContract $contract, float $day, float $night, float $fee): void
    {
        $pricing = $contract->canonical_pricing;
        $pricing['phases'][0]['components'] = [
            CanonicalPricingFixture::component(ComponentType::EnergyDay, $day, ComponentUnit::CentsPerKwh),
            CanonicalPricingFixture::component(ComponentType::EnergyNight, $night, ComponentUnit::CentsPerKwh),
            CanonicalPricingFixture::component(ComponentType::MonthlyFee, $fee, ComponentUnit::EurPerMonth),
        ];
        $contract->canonical_pricing = $pricing;
    }
}

class RecordingCurrentEpisodeResolver extends CurrentPriceEpisodeResolver
{
    public array $batches = [];

    public function resolve(array $candidates, ?CarbonInterface $asOf = null): array
    {
        if ($candidates === []) {
            return [];
        }
        $this->batches[] = ['date' => $asOf?->toDateString(), 'candidates' => $candidates];

        return array_map(fn () => PriceEpisodeAnchor::missing(), $candidates);
    }
}
