<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\FixedContractPriceForecast;
use App\Models\RetailPremiumObservation;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use App\Services\RetailPremium\RetailPremiumObservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MorningJobFreshnessGateTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-01';

    public function test_incomplete_contract_checkpoint_blocks_retail_collection_without_writes(): void
    {
        $this->checkpoint(DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT, DataFreshnessCheckpoint::STATUS_INCOMPLETE);
        $this->readyEexInputs();

        $builder = $this->createMock(RetailPremiumObservationService::class);
        $builder->expects($this->never())->method('buildObservations');
        $this->app->instance(RetailPremiumObservationService::class, $builder);

        $this->artisan('retail-premiums:collect', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: The current contract import is incomplete.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('retail_premium_observations', 0);
    }

    public function test_changed_active_contract_with_delayed_interpretation_blocks_retail_collection(): void
    {
        config()->set('contract_interpretation.enabled', true);
        [$contract, $currentSnapshot] = $this->contractWithChangedUnpublishedSnapshot();
        $this->readyContractCheckpoint([$currentSnapshot->id], [$contract->id]);
        $this->readyEexInputs();

        $builder = $this->createMock(RetailPremiumObservationService::class);
        $builder->expects($this->never())->method('buildObservations');
        $this->app->instance(RetailPremiumObservationService::class, $builder);

        $this->artisan('retail-premiums:collect', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput("Morning job deferred: Current interpretations are not published for active contracts: {$contract->id}.")
            ->assertExitCode(1);

        $this->assertDatabaseCount('retail_premium_observations', 0);
    }

    public function test_interpretation_published_during_statistics_calculation_blocks_forecast(): void
    {
        config()->set('contract_interpretation.enabled', true);
        [$contract, $snapshot] = $this->contractWithPublishedSnapshot('published-during-statistics', '2026-08-01 06:15:00');
        $this->readyContractCheckpoint(
            [$snapshot->id],
            [$contract->id],
            '2026-08-01T06:10:00+03:00',
            '2026-08-01T06:20:00+03:00',
        );
        $this->readyEexInputs();
        $this->forecastStatistics([6]);
        $publishedAt = CarbonImmutable::instance($contract->publishedInterpretation->published_at);
        $statisticsStartedAt = CarbonImmutable::parse('2026-08-01T06:10:00+03:00');
        $statisticsCompletedAt = CarbonImmutable::parse('2026-08-01T06:20:00+03:00');
        $this->assertTrue($publishedAt->gt($statisticsStartedAt));
        $this->assertTrue($publishedAt->lt($statisticsCompletedAt));

        $builder = $this->createMock(FixedTermPriceForecastService::class);
        $builder->expects($this->never())->method('buildForecasts');
        $this->app->instance(FixedTermPriceForecastService::class, $builder);

        $this->artisan('forecasting:run-fixed-contracts', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: Contract statistics started before the current interpretation was published.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('fixed_contract_price_forecasts', 0);
    }

    public function test_missing_active_snapshot_coverage_blocks_interpretation_gate(): void
    {
        config()->set('contract_interpretation.enabled', true);
        [$observedContract, $observedSnapshot] = $this->contractWithPublishedSnapshot('observed-contract');
        [$missingContract] = $this->contractWithPublishedSnapshot('missing-contract');
        $this->readyContractCheckpoint(
            [$observedSnapshot->id],
            [$missingContract->id, $observedContract->id],
        );
        $this->readyEexInputs();

        $builder = $this->createMock(RetailPremiumObservationService::class);
        $builder->expects($this->never())->method('buildObservations');
        $this->app->instance(RetailPremiumObservationService::class, $builder);

        $this->artisan('retail-premiums:collect', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput("Morning job deferred: Active contracts do not have exactly one observed source episode: {$missingContract->id}.")
            ->assertExitCode(1);
    }

    public function test_duplicate_active_snapshot_coverage_blocks_interpretation_gate(): void
    {
        config()->set('contract_interpretation.enabled', true);
        [$contract, $snapshot] = $this->contractWithPublishedSnapshot('duplicate-snapshot-contract');
        $duplicateSnapshot = $this->snapshot($contract, 'duplicate');
        $this->readyContractCheckpoint([$snapshot->id, $duplicateSnapshot->id], [$contract->id]);
        $this->readyEexInputs();

        $result = app(MorningJobFreshnessService::class)
            ->checkRetailPremium(CarbonImmutable::parse(self::DATE, 'Europe/Helsinki'));

        $this->assertFalse($result->ready());
        $this->assertSame(
            "Active contracts do not have exactly one observed source episode: {$contract->id}.",
            $result->failures['contract_interpretations'],
        );
    }

    public function test_stale_futures_blocks_forecast_builder_and_writes_no_forecast(): void
    {
        config()->set('morning_freshness.max_futures_age_days', 7);
        $this->readyContractCheckpoint([1], []);
        $this->checkpoint(DataFreshnessCheckpoint::KEY_EEX_FUTURES, DataFreshnessCheckpoint::STATUS_READY, [
            'current_run_latest_prior_fi_trade_date' => '2026-07-31',
        ]);
        $this->forecastStatistics();
        $this->future('2026-07-20');

        $builder = $this->createMock(FixedTermPriceForecastService::class);
        $builder->expects($this->never())->method('buildForecasts');
        $this->app->instance(FixedTermPriceForecastService::class, $builder);

        $this->artisan('forecasting:run-fixed-contracts', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: The latest FI EEX Base futures data is 12 days old.')
            ->assertExitCode(1);

        $this->assertSame(0, FixedContractPriceForecast::count());
    }

    public function test_missing_required_checkpoint_has_visible_deferred_output_and_failure(): void
    {
        $this->artisan('retail-premiums:collect', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: The current contract import checkpoint is missing.')
            ->expectsOutput('Morning job deferred: The current EEX futures checkpoint is missing.')
            ->assertExitCode(1);

        $this->assertSame(0, RetailPremiumObservation::count());
    }

    public function test_contract_checkpoint_requires_statistics_start_and_completion(): void
    {
        $this->checkpoint(DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT, DataFreshnessCheckpoint::STATUS_READY, [
            'observed_snapshot_ids' => [1],
            'active_contract_ids' => [],
            'statistics_completed_at' => '2026-08-01T06:20:30+03:00',
        ]);
        $this->readyEexInputs();

        $this->artisan('retail-premiums:collect', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: The current contract import facts are incomplete.')
            ->assertExitCode(1);
    }

    public function test_ready_eex_checkpoint_requires_current_run_fi_proof(): void
    {
        $this->readyContractCheckpoint([1], []);
        $this->checkpoint(DataFreshnessCheckpoint::KEY_EEX_FUTURES, DataFreshnessCheckpoint::STATUS_READY);
        $this->future('2026-07-31');

        $this->artisan('retail-premiums:collect', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: The current EEX fetch has no prior-date FI Base point from this run.')
            ->assertExitCode(1);
    }

    public function test_valid_retail_gate_is_ready(): void
    {
        $this->readyContractCheckpoint([1], []);
        $this->readyEexInputs();

        $result = app(MorningJobFreshnessService::class)
            ->checkRetailPremium(CarbonImmutable::parse(self::DATE, 'Europe/Helsinki'));

        $this->assertTrue($result->ready(), $result->summary());
        $this->assertSame([], $result->failures);
    }

    public function test_forecast_gate_requires_at_least_one_fixed_term_statistic(): void
    {
        $this->readyContractCheckpoint([1], []);
        $this->readyEexInputs();

        $builder = $this->createMock(FixedTermPriceForecastService::class);
        $builder->expects($this->never())->method('buildForecasts');
        $this->app->instance(FixedTermPriceForecastService::class, $builder);

        $this->artisan('forecasting:run-fixed-contracts', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: No current fixed-term 6/12/24 energy-price statistic is available in the expected pricing basis.')
            ->assertExitCode(1);
    }

    public function test_gated_forecast_with_one_duration_statistic_reaches_builder_and_defers_zero_output(): void
    {
        $this->readyContractCheckpoint([1], []);
        $this->readyEexInputs();
        $this->forecastStatistics([12]);

        $builder = $this->createMock(FixedTermPriceForecastService::class);
        $builder->expects($this->once())->method('buildForecasts')->willReturn(collect());
        $this->app->instance(FixedTermPriceForecastService::class, $builder);

        $this->artisan('forecasting:run-fixed-contracts', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: No current fixed-term forecasts were produced.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('fixed_contract_price_forecasts', 0);
    }

    public function test_gated_retail_collection_with_no_output_is_deferred(): void
    {
        $this->readyContractCheckpoint([1], []);
        $this->readyEexInputs();

        $this->artisan('retail-premiums:collect', [
            '--as-of' => self::DATE,
            '--require-freshness' => true,
        ])
            ->expectsOutput('Morning job deferred: No current retail premium observations were built.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('retail_premium_observations', 0);
    }

    /** @return array{ElectricityContract, ContractSourceSnapshot} */
    private function contractWithChangedUnpublishedSnapshot(): array
    {
        Company::create(['name' => 'Freshness Energy', 'name_slug' => 'freshness-energy']);
        $contract = ElectricityContract::create([
            'id' => 'changed-active-contract',
            'api_id' => 'changed-active-api',
            'name' => 'Changed active contract',
            'company_name' => 'Freshness Energy',
            'contract_type' => 'FixedTerm',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'availability_is_national' => true,
        ]);
        $oldSnapshot = $this->snapshot($contract, 'old');
        $currentSnapshot = $this->snapshot($contract, 'current');
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $oldSnapshot->id,
            'analysis_fingerprint' => str_repeat('a', 64),
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'schema_version' => 'test-schema',
            'prompt_version' => 'test-prompt',
            'provider' => 'test',
            'model' => 'test',
            'published_at' => '2026-08-01 06:10:00',
        ]);
        $contract->update(['published_interpretation_id' => $interpretation->id]);

        return [$contract->fresh(), $currentSnapshot];
    }

    /** @return array{ElectricityContract, ContractSourceSnapshot} */
    private function contractWithPublishedSnapshot(
        string $contractId,
        string $publishedAt = '2026-08-01 06:05:00',
    ): array {
        $companyName = "{$contractId} Energy";
        Company::create([
            'name' => $companyName,
            'name_slug' => $contractId.'-energy',
        ]);
        $contract = ElectricityContract::create([
            'id' => $contractId,
            'api_id' => $contractId.'-api',
            'name' => $contractId,
            'company_name' => $companyName,
            'contract_type' => 'FixedTerm',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'availability_is_national' => true,
        ]);
        $snapshot = $this->snapshot($contract, 'current');
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => hash('sha256', $contractId),
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'schema_version' => 'test-schema',
            'prompt_version' => 'test-prompt',
            'provider' => 'test',
            'model' => 'test',
            'published_at' => $publishedAt,
        ]);
        $contract->update(['published_interpretation_id' => $interpretation->id]);

        return [$contract->fresh(), $snapshot];
    }

    private function snapshot(ElectricityContract $contract, string $version): ContractSourceSnapshot
    {
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => hash('sha256', $version),
            'source_payload' => ['version' => $version],
            'first_observed_at' => '2026-08-01 06:00:00',
            'last_observed_at' => '2026-08-01 06:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => '2026-08-01 06:00:00',
            'last_observed_at' => '2026-08-01 06:00:00',
        ]);
        $contract->update(['current_source_observation_id' => $observation->id]);

        return $snapshot;
    }

    /** @param list<int> $snapshotIds @param list<string> $activeIds */
    private function readyContractCheckpoint(
        array $snapshotIds,
        array $activeIds,
        string $statisticsStartedAt = '2026-08-01T06:20:00+03:00',
        string $statisticsCompletedAt = '2026-08-01T06:20:30+03:00',
    ): void {
        $observationIds = ContractSourceObservation::query()
            ->whereIn('source_snapshot_id', $snapshotIds)
            ->pluck('id')
            ->all();

        $this->checkpoint(DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT, DataFreshnessCheckpoint::STATUS_READY, [
            'observed_source_observation_ids' => $observationIds !== [] ? $observationIds : $snapshotIds,
            'active_contract_ids' => $activeIds,
            'statistics_started_at' => $statisticsStartedAt,
            'statistics_completed_at' => $statisticsCompletedAt,
        ]);
    }

    private function readyEexInputs(): void
    {
        $this->checkpoint(DataFreshnessCheckpoint::KEY_EEX_FUTURES, DataFreshnessCheckpoint::STATUS_READY, [
            'current_run_latest_prior_fi_trade_date' => '2026-07-31',
        ]);
        $this->future('2026-07-31');
    }

    /** @param array<string, mixed>|null $metadata */
    private function checkpoint(string $key, string $status, ?array $metadata = null): void
    {
        DataFreshnessCheckpoint::create([
            'key' => $key,
            'effective_date' => self::DATE,
            'status' => $status,
            'metadata' => $metadata,
            'recorded_at' => '2026-08-01 06:30:00',
        ]);
    }

    private function future(string $tradeDate): void
    {
        ElectricityFuturesEodPrice::create([
            'area' => 'FI',
            'short_code' => 'FNBM',
            'maturity' => '202608',
            'maturity_type' => 'month',
            'trade_date' => $tradeDate,
            'settlement_price' => 50,
        ]);
    }

    /** @param list<int> $durations */
    private function forecastStatistics(array $durations = [6, 12, 24]): void
    {
        foreach ($durations as $duration) {
            ContractPriceDailyStatistic::create([
                'stat_date' => self::DATE,
                'segment_key' => "fixed_term_{$duration}",
                'metric_key' => 'energy_price',
                'pricing_basis' => 'observed_seller_data',
                'consumption_kwh' => null,
                'median_value' => 9.5,
                'contract_count' => 1,
            ]);
        }
    }
}
