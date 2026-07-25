<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\RetailPremiumObservation;
use App\Services\RetailPremium\RetailPremiumObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetailPremiumCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_collects_exact_spot_premium_with_fee_and_vat_basis(): void
    {
        $contract = $this->publishedSpotContract(
            'spot-contract',
            margin: 0.49,
            monthlyFee: 4.90,
            vatStatus: 'included',
        );

        $this->artisan('retail-premiums:collect --as-of=2026-07-25')
            ->assertExitCode(0);

        $observation = RetailPremiumObservation::sole();
        $this->assertSame($contract->id, $observation->contract_id);
        $this->assertSame('spot_disclosed', $observation->reference_kind);
        $this->assertSame('exact', $observation->quality);
        $this->assertSame('included', $observation->vat_basis);
        $this->assertSame(5000, $observation->reference_consumption_kwh);
        $this->assertEqualsWithDelta(0.49, $observation->retail_premium_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(4.90, $observation->monthly_fee_eur, 0.0001);
        $this->assertEqualsWithDelta(1.666, $observation->retail_premium_with_fee_cents_per_kwh, 0.0001);
        $this->assertNull($observation->reference_trade_date);
        $this->assertNull($observation->reference_price_cents_per_kwh);
        $this->assertSame('2026-07-01', $observation->period_start->toDateString());
        $this->assertSame('2026-07-31', $observation->period_end->toDateString());
        $this->assertSame(RetailPremiumObservationService::METHOD_VERSION, $observation->method_version);
        $this->assertSame([], $observation->quality_flags);
    }

    public function test_existing_observation_is_immutable_unless_overwrite_is_requested(): void
    {
        $this->publishedSpotContract('immutable-spot', margin: 0.50, monthlyFee: 3.00);
        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $observation = RetailPremiumObservation::sole();
        $observation->update(['retail_premium_cents_per_kwh' => 9.99]);

        $this->artisan('retail-premiums:collect')->assertExitCode(0);
        $this->assertEqualsWithDelta(9.99, $observation->fresh()->retail_premium_cents_per_kwh, 0.0001);

        $this->artisan('retail-premiums:collect --overwrite')->assertExitCode(0);
        $this->assertEqualsWithDelta(0.50, $observation->fresh()->retail_premium_cents_per_kwh, 0.0001);
    }

    public function test_unchanged_semantic_price_reuses_the_open_period_across_source_versions(): void
    {
        $contract = $this->publishedSpotContract('versioned-spot', margin: 0.55, monthlyFee: 3.50);
        $this->artisan('retail-premiums:collect')->assertExitCode(0);
        $first = RetailPremiumObservation::sole();

        $this->publishVersion($contract, margin: 0.55, monthlyFee: 3.50, firstObserved: '2026-07-26');
        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $this->assertSame(1, RetailPremiumObservation::count());
        $this->assertSame($first->observation_key, RetailPremiumObservation::sole()->observation_key);
    }

    public function test_a_changed_spot_price_starts_a_new_observation_period(): void
    {
        $contract = $this->publishedSpotContract('changing-spot', margin: 0.45, monthlyFee: 3.00);
        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $this->publishVersion($contract, margin: 0.65, monthlyFee: 3.00, firstObserved: '2026-07-26');
        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $this->assertSame(2, RetailPremiumObservation::count());
        $this->assertEqualsCanonicalizing(
            [0.45, 0.65],
            RetailPremiumObservation::query()->pluck('retail_premium_cents_per_kwh')->map(fn ($value) => (float) $value)->all(),
        );
    }

    public function test_command_records_outliers_and_flags_missing_fee_instead_of_dropping_them(): void
    {
        $this->publishedSpotContract('zero-spot', margin: 0.00, monthlyFee: null, vatStatus: 'unknown');

        $this->artisan('retail-premiums:collect')->assertExitCode(0);

        $observation = RetailPremiumObservation::sole();
        $this->assertEqualsWithDelta(0.0, $observation->retail_premium_cents_per_kwh, 0.0001);
        $this->assertNull($observation->retail_premium_with_fee_cents_per_kwh);
        $this->assertContains('monthly_fee_missing', $observation->quality_flags);
        $this->assertContains('zero_or_negative_retail_premium', $observation->quality_flags);
        $this->assertContains('vat_unknown', $observation->quality_flags);
    }

    public function test_dry_run_does_not_write_and_inactive_contracts_are_not_collected(): void
    {
        $active = $this->publishedSpotContract('active-spot', margin: 0.40, monthlyFee: 2.50);
        $inactive = $this->publishedSpotContract('inactive-spot', margin: 0.60, monthlyFee: 2.50);
        ActiveContract::query()->whereKey($inactive->id)->delete();

        $this->artisan("retail-premiums:collect --dry-run --contract={$active->id}")
            ->assertExitCode(0);

        $this->assertSame(0, RetailPremiumObservation::count());
    }

    private function publishedSpotContract(
        string $id,
        float $margin,
        ?float $monthlyFee,
        string $vatStatus = 'included',
    ): ElectricityContract {
        Company::firstOrCreate(['name' => 'Premium Energy']);
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Premium Energy',
            'name' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);
        ActiveContract::create(['id' => $contract->id]);
        $this->publishVersion($contract, $margin, $monthlyFee, $vatStatus, '2026-07-25');

        return $contract->fresh();
    }

    private function publishVersion(
        ElectricityContract $contract,
        float $margin,
        ?float $monthlyFee,
        string $vatStatus = 'included',
        string $firstObserved = '2026-07-25',
    ): void {
        $pricing = $this->pricing($margin, $monthlyFee, $vatStatus);
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => hash('sha256', $contract->id.$margin.$monthlyFee.$firstObserved.microtime(true)),
            'source_payload' => ['id' => $contract->id, 'price' => $margin],
            'first_observed_at' => $firstObserved.' 06:00:00',
            'last_observed_at' => $firstObserved.' 06:00:00',
        ]);
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => hash('sha256', $snapshot->source_fingerprint.'analysis'),
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'schema_version' => 'schema-v3',
            'prompt_version' => 'prompt-v17',
            'validator_version' => 'validator-v14',
            'provider' => 'test',
            'model' => 'test-model',
            'output' => [
                'pricing' => $pricing,
                'confidence' => ['pricing' => 'high'],
            ],
            'published_at' => $firstObserved.' 06:05:00',
        ]);

        $contract->update([
            'published_interpretation_id' => $interpretation->id,
            'canonical_pricing' => $pricing,
            'canonical_source_consistency' => ['structured_pricing_status' => 'complete'],
            'canonical_calculation' => ['status' => 'estimate_required'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(float $margin, ?float $monthlyFee, string $vatStatus): array
    {
        $components = [[
            'component_type' => 'spot_margin',
            'amount' => $margin,
            'normal_amount' => null,
            'unit' => 'cents_per_kwh',
            'vat_status' => $vatStatus,
            'price_role' => 'current',
            'source_kind' => 'structured',
            'evidence' => [['source' => 'components[0].price', 'quote' => "margin={$margin}"]],
        ]];

        if ($monthlyFee !== null) {
            $components[] = [
                'component_type' => 'monthly_fee',
                'amount' => $monthlyFee,
                'normal_amount' => null,
                'unit' => 'eur_per_month',
                'vat_status' => $vatStatus,
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[1].price', 'quote' => "fee={$monthlyFee}"]],
            ];
        }

        return [
            'phases' => [[
                'label' => 'Current price',
                'phase_kind' => 'current_structured',
                'starts' => ['kind' => 'date', 'value' => '2026-07-01'],
                'ends' => ['kind' => 'date', 'value' => '2026-07-31'],
                'components' => $components,
                'evidence' => [],
            ]],
            'recurring_schedule' => [
                'present' => false,
                'cadence' => 'none',
                'current_period_start' => null,
                'current_period_end' => null,
            ],
            'consumption_effect' => ['present' => false],
        ];
    }
}
