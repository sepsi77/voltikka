<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractStatistics\DTO\SellerSetEnergyPriceIndexDateSummary;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSellerSetEnergyPriceIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-01-21';

    public function test_command_is_dry_run_by_default_and_apply_uses_only_canonical_evidence_dates(): void
    {
        Company::create(['name' => 'Backfill Seller Oy', 'name_slug' => 'backfill-seller-oy']);
        $contract = ElectricityContract::factory()->forCompany('Backfill Seller Oy')->create(['id' => 'seller-backfill']);
        ContractPriceSnapshot::create([
            'snapshot_date' => self::DATE,
            'contract_id' => $contract->id,
            'company_name' => 'Backfill Seller Oy',
            'contract_name' => $contract->name,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'segment_key' => 'open_ended',
            'pricing_basis' => 'observed_seller_data',
            'energy_price_cents_per_kwh' => 7.0,
            'has_discount' => false,
            'includes_spot_price' => false,
        ]);
        ContractPriceAnnualCost::create([
            'snapshot_date' => self::DATE,
            'contract_id' => $contract->id,
            'segment_key' => 'open_ended',
            'pricing_basis' => 'observed_seller_data',
            'consumption_kwh' => 5000,
            'annual_cost' => 500.0,
            'method_version' => AnnualCostMethodVersion::AsOf,
            'calculation_basis' => AnnualCostCalculationBasis::CanonicalOutcome,
            'estimate_method' => 'hold_current',
            'estimate_basis' => 'test',
            'compatibility_key' => 'test',
        ]);
        $summary = new SellerSetEnergyPriceIndexDateSummary(
            date: self::DATE,
            evidenceCount: 4,
            annualProofCount: 4,
            eligibleContractCount: 3,
            directRateCount: 3,
            rowCount: 4,
            familyOfferCounts: ['fixed_term' => 1, 'market_reset' => 1, 'open_ended' => 1],
            exclusionCounts: ['missing_or_unsupported_direct_general_rate' => 1],
            provenanceCounts: ['retrospective_historical_interpretation' => 3],
            rows: [],
        );

        $mock = $this->mock(SellerSetEnergyPriceIndexService::class);
        $mock->shouldReceive('previewHistoricalForDate')->once()->with(self::DATE)->andReturn($summary);
        $mock->shouldNotReceive('writeHistoricalForDate');
        $this->artisan('contracts:backfill-seller-set-energy-price-index', ['--date' => self::DATE])
            ->expectsOutputToContain('Dry run:')
            ->expectsOutputToContain('annual_proof=4')
            ->expectsOutputToContain('retrospective_historical_interpretation=3')
            ->assertSuccessful();

        $this->app->forgetInstance(SellerSetEnergyPriceIndexService::class);
        $mock = $this->mock(SellerSetEnergyPriceIndexService::class);
        $mock->shouldReceive('writeHistoricalForDate')->once()->with(self::DATE)->andReturn($summary);
        $this->artisan('contracts:backfill-seller-set-energy-price-index', ['--date' => self::DATE, '--apply' => true])
            ->expectsOutputToContain('Applying')
            ->assertSuccessful();
    }

    public function test_command_requires_an_exact_date_or_complete_bounded_range(): void
    {
        $this->artisan('contracts:backfill-seller-set-energy-price-index')
            ->expectsOutputToContain('Use --date or both --from and --to.')
            ->assertFailed();
        $this->artisan('contracts:backfill-seller-set-energy-price-index', ['--from' => self::DATE])
            ->expectsOutputToContain('Use --date or both --from and --to.')
            ->assertFailed();
        $this->artisan('contracts:backfill-seller-set-energy-price-index', ['--date' => '2026-01-20'])
            ->expectsOutputToContain('Dates must be from 2026-01-21 through 2026-08-11.')
            ->assertFailed();
        $this->artisan('contracts:backfill-seller-set-energy-price-index', ['--from' => '2026-08-11', '--to' => '2026-01-21'])
            ->expectsOutputToContain('--from must not be after --to.')
            ->assertFailed();
    }
}
