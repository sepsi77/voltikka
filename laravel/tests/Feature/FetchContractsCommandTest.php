<?php

namespace Tests\Feature;

use App\Console\Commands\FetchContracts;
use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Models\PriceComponent;
use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\ContractImport\ContractImportCompletion;
use App\Services\ContractListCacheService;
use App\Services\ContractReplacement\ContractReplacementLinker;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\CapturesSentryIssues;
use Tests\TestCase;

class FetchContractsCommandTest extends TestCase
{
    use CapturesSentryIssues;
    use RefreshDatabase;

    /** The outer store lock every staged store takes (StagedImport); FetchContracts::STORE_LOCK is the inner one. */
    private const TRAIT_STORE_LOCK = 'staged_import_store_v1:contracts:fetch';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a valid postcode for testing
        Postcode::create([
            'postcode' => '00100',
            'postcode_name' => 'Helsinki',
            'municipality_code' => '091',
        ]);

        Postcode::create([
            'postcode' => '02230',
            'postcode_name' => 'Espoo',
            'municipality_code' => '049',
        ]);
    }

    public function test_full_import_is_running_during_acquisition_and_failed_after_unexpected_exception(): void
    {
        Http::fake(function () {
            $checkpoint = DataFreshnessCheckpoint::sole();
            $this->assertSame(DataFreshnessCheckpoint::STATUS_RUNNING, $checkpoint->status);
            $this->assertTrue(Str::isUuid($checkpoint->metadata['run_uuid']));
            throw new \RuntimeException('private upstream detail');
        });
        $this->artisan('contracts:fetch --skip-logos')->assertExitCode(1);
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, DataFreshnessCheckpoint::sole()->status);
        $this->assertSame('unexpected', DataFreshnessCheckpoint::sole()->metadata['stage']);
    }

    public function test_refused_checkpoint_record_is_in_the_import_issue(): void
    {
        $this->captureSentryIssues();
        Http::fake(function () {
            // Another run took ownership of today's checkpoint.
            $checkpoint = DataFreshnessCheckpoint::sole();
            $checkpoint->update(['metadata' => ['run_uuid' => (string) Str::uuid()] + $checkpoint->metadata]);
            throw new \RuntimeException('private upstream detail');
        });
        $this->artisan('contracts:fetch --skip-logos')->assertExitCode(1);
        $this->assertImportIssue('contracts', 'error', ['unexpected' => 1, 'checkpoint' => 1]);
    }

    /**
     * Test command fetches contracts from API and saves to database.
     */
    public function test_command_fetches_and_saves_contracts(): void
    {
        // Mock the HTTP response from Azure Consumer API
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        // Run the command
        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Verify company was created
        $this->assertDatabaseHas('companies', [
            'name' => 'Energia Oy',
        ]);

        // Verify the API ID is preserved and the source alias is normalized.
        $this->assertDatabaseHas('electricity_contracts', [
            'api_id' => 'contract-12345',
            'name' => 'Sähkösopimus Perus',
            'company_name' => 'Energia Oy',
            'contract_type' => 'FixedTerm',
            'metering' => 'General',
        ]);

        // Get the contract to find its new internal ID
        $contract = ElectricityContract::where('api_id', 'contract-12345')->first();
        $this->assertNotNull($contract);

        // Verify electricity source was created with the new internal ID
        $this->assertDatabaseHas('electricity_sources', [
            'contract_id' => $contract->id,
            'renewable_total' => 100.0,
            'renewable_wind' => 50.0,
            'renewable_hydro' => 50.0,
        ]);

        // Verify price components were created with the new internal ID
        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price' => 5.5,
        ]);

        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'Monthly',
            'price' => 2.95,
        ]);

        // Verify active contract was created with the new internal ID
        $this->assertDatabaseHas('active_contracts', [
            'id' => $contract->id,
        ]);
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
    }

    /**
     * Test command handles multiple postcodes.
     */
    public function test_command_handles_multiple_postcodes(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/00100' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/02230' => Http::response(
                $this->getSampleApiResponse('contract-67890', 'Another Contract'),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100,02230'])
            ->assertExitCode(0);

        // Should have both contracts (identified by api_id)
        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'contract-12345']);
        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'contract-67890']);
    }

    /**
     * Test command uses default postcodes when none provided.
     */
    public function test_command_uses_default_postcodes(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch')
            ->assertExitCode(0);

        // Should have fetched from all default postcodes (30) plus retry attempt
        Http::assertSentCount(31);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT, $checkpoint->key);
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $checkpoint->status);
        $this->assertNotEmpty($checkpoint->metadata['observed_source_observation_ids']);
        $this->assertNotEmpty($checkpoint->metadata['active_contract_ids']);
        $this->assertNotNull($checkpoint->metadata['statistics_started_at']);
        $this->assertNotNull($checkpoint->metadata['statistics_completed_at']);
    }

    public function test_full_import_with_pending_interpretation_records_proof_and_explicit_deferred_output(): void
    {
        $this->captureSentryIssues();
        Queue::fake();
        config(['contract_interpretation.enabled' => true, 'services.openrouter.api_key' => 'test-key']);
        Http::fake(['*' => Http::response($this->getSampleApiResponse(), 200)]);
        $this->artisan('contracts:fetch', ['--skip-logos' => true])
            ->expectsOutput('Contracts imported. Required completion is deferred until current interpretations settle.')
            ->assertSuccessful();
        $row = DataFreshnessCheckpoint::sole();
        $this->assertSame(ContractImportCompletion::PENDING, $row->status);
        $this->assertSame(1, $row->metadata['completion_version']);
        $this->assertNotNull($row->metadata['episodes'][0]['interpretation_id']);
        $this->assertNotNull($row->metadata['statistics_completed_at']);
        $this->assertSame('waiting', app(ContractImportCompletion::class)->tick());
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 1);
        Http::assertSentCount(30);
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_full_import_defers_when_the_active_set_changes_during_initial_statistics(): void
    {
        // 2026-10-01: month-start replacements were activated while the initial statistics ran.
        $this->captureSentryIssues();
        Queue::fake();
        config(['contract_interpretation.enabled' => true, 'services.openrouter.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(array_merge(
            $this->getSampleApiResponse(),
            $this->getSampleApiResponse('contract-new', 'Sähkösopimus Uusi'),
        ), 200)]);
        // A first run leaves both contracts inactive; one then becomes active through earlier publication.
        // Artisan keeps the first command instance, so the proxy passes the first run through.
        $this->activateDuringStatistics(passThrough: 1);
        $this->artisan('contracts:fetch', ['--skip-logos' => true])->assertSuccessful();
        ActiveContract::create(['id' => ElectricityContract::where('api_id', 'contract-12345')->value('id')]);
        $this->artisan('contracts:fetch', ['--skip-logos' => true])
            ->expectsOutput('Contracts imported. Required completion is deferred until current interpretations settle.')
            ->doesntExpectOutput('Required post-import stage daily_statistics failed.')
            ->assertSuccessful();
        $row = DataFreshnessCheckpoint::sole();
        $this->assertSame(ContractImportCompletion::PENDING, $row->status);
        $this->assertArrayNotHasKey('statistics_started_at', $row->metadata);
        $this->assertArrayNotHasKey('statistics_completed_at', $row->metadata);
        $this->assertSame(1, count($row->metadata['initial_active_contract_ids']));
        $this->assertDatabaseCount('active_contracts', 2);
        $this->assertDatabaseCount('contract_price_snapshots', 0);
        $this->assertSame([], $this->sentryIssues);
        $this->assertSame('waiting', app(ContractImportCompletion::class)->tick());
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_active_set_change_during_scoped_statistics_stays_a_failure(): void
    {
        $this->captureSentryIssues();
        Http::fake(fn () => Http::response($this->getSampleApiResponse()));
        $this->activateDuringStatistics();
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->expectsOutput('Required post-import stage daily_statistics failed.')
            ->assertExitCode(1);
        $this->assertImportIssue('contracts', 'error', ['daily_statistics' => 1]);
        $this->assertSame(['daily_statistics' => ['evidence_changed' => 1]], $this->sentryIssues[0]->getContexts()['data_fetch']['reasons']);
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
    }

    public function test_full_import_statistics_error_stays_terminal(): void
    {
        $this->captureSentryIssues();
        Http::fake(fn () => Http::response($this->getSampleApiResponse()));
        $statistics = $this->createMock(ContractPriceStatisticsService::class);
        $statistics->method('calculateForDate')->willThrowException(new \RuntimeException('private'));
        $this->app->instance(ContractPriceStatisticsService::class, $statistics);
        $this->artisan('contracts:fetch', ['--skip-logos' => true])->assertExitCode(1);
        $row = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $row->status);
        $this->assertSame('post_import', $row->metadata['stage']);
        $this->assertImportIssue('contracts', 'error', ['daily_statistics' => 1]);
        $this->assertSame('no_pending_completion', app(ContractImportCompletion::class)->tick());
    }

    /** Activates every imported contract after the caller captured the active set. */
    private function activateDuringStatistics(int $passThrough = 0): void
    {
        $real = app(ContractPriceStatisticsService::class);
        $proxy = \Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('calculateForDate')->times($passThrough + 1)->andReturnUsing(function ($date, $ids, $overwrite, $canonical, $fence) use ($real, &$passThrough): array {
            if ($passThrough-- > 0) {
                return $real->calculateForDate($date, $ids, $overwrite, $canonical, $fence);
            }
            $active = ActiveContract::pluck('id')->all();
            foreach (ElectricityContract::whereNotIn('id', $active)->pluck('id') as $id) {
                ActiveContract::create(['id' => $id]);
            }
            if (ActiveContract::count() === count($active)) {
                ActiveContract::query()->delete();
            }

            return $real->calculateForDate($date, $ids, $overwrite, $canonical, $fence);
        });
        $this->app->instance(ContractPriceStatisticsService::class, $proxy);
    }

    public function test_full_scope_stops_before_acquisition_when_initial_checkpoint_fails(): void
    {
        $freshness = $this->createMock(ContractImportCompletion::class);
        $freshness->expects($this->once())
            ->method('start')
            ->willThrowException(new \RuntimeException('Checkpoint unavailable'));
        $this->app->instance(ContractImportCompletion::class, $freshness);
        Http::fake();

        $this->artisan('contracts:fetch')
            ->expectsOutput('Failed to record the contract freshness checkpoint.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    /**
     * Test command deduplicates contracts from different postcodes.
     */
    public function test_command_deduplicates_contracts(): void
    {
        // Same contract returned from multiple postcodes
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100,02230'])
            ->assertExitCode(0);

        // Should only have one contract (deduplicated)
        $this->assertEquals(1, ElectricityContract::count());
    }

    /**
     * Test command updates existing contracts.
     */
    public function test_command_updates_existing_contracts(): void
    {
        // Create an existing contract
        Company::create([
            'name' => 'Energia Oy',
            'name_slug' => 'energia-oy',
        ]);
        ElectricityContract::create([
            'id' => 'replacement-contract-id',
            'api_id' => 'replacement-api-id',
            'name' => 'Replacement contract',
            'company_name' => 'Energia Oy',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        ElectricityContract::create([
            'id' => 'existing-contract-id',
            'api_id' => 'contract-12345',
            'name' => 'Old Name',
            'company_name' => 'Energia Oy',
            'contract_type' => 'Fixed',
            'spot_price_selection' => 'OldSelection',
            'fixed_time_range' => 'OldRange',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'target_group' => 'Company',
            'short_description' => 'Old short description',
            'long_description' => 'Preserved legacy long description',
            'pricing_name' => 'Old pricing',
            'pricing_has_discounts' => false,
            'consumption_control' => true,
            'order_link' => 'https://old.example/order',
            'billing_frequency' => ['Old' => true],
            'time_period_definitions' => ['Old' => true],
            'extra_information_fi' => 'Vanhentunut kuvaus',
            'availability_is_national' => true,
            'microproduction_buys' => false,
            'replaced_by_contract_id' => 'replacement-contract-id',
        ]);

        // API returns current source fields
        $response = $this->getSampleApiResponse('contract-12345', 'Sähkösopimus Perus', true);
        $response[0]['Details']['ShortDescription'] = 'Current short description';
        $response[0]['Details']['TimePeriodDefinitions'] = [
            'DayAndNight' => [
                'Default' => null,
                'FI' => 'Päivä 07–22',
                'EN' => 'Day 07–22',
                'SV' => 'Dag 07–22',
            ],
        ];
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response($response, 200),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Current source fields should be refreshed without changing the local ID
        $contract = ElectricityContract::where('api_id', 'contract-12345')->first();
        $this->assertSame('existing-contract-id', $contract->id);
        $this->assertSame('replacement-contract-id', $contract->replaced_by_contract_id);
        $this->assertSame('Sähkösopimus Perus', $contract->name);
        $this->assertSame('sahkosopimus-perus', $contract->name_slug);
        $this->assertNull($contract->spot_price_selection);
        $this->assertSame('24 kk', $contract->fixed_time_range);
        $this->assertSame('FixedPrice', $contract->pricing_model);
        $this->assertSame('Household', $contract->target_group);
        $this->assertSame('Current short description', $contract->short_description);
        $this->assertSame('Preserved legacy long description', $contract->long_description);
        $this->assertSame('General Price', $contract->pricing_name);
        $this->assertTrue($contract->pricing_has_discounts);
        $this->assertFalse($contract->consumption_control);
        $this->assertSame('https://energia.fi/order', $contract->order_link);
        $this->assertSame(['Monthly' => true], $contract->billing_frequency);
        $this->assertSame('Lisätietoja', $contract->extra_information_fi);
        $this->assertFalse($contract->availability_is_national);
        $this->assertTrue($contract->microproduction_buys);
        $this->assertSame('Päivä 07–22', $contract->time_period_definitions['DayAndNight']['FI']);
    }

    public function test_source_fetch_does_not_overwrite_published_canonical_classification(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $contract = ElectricityContract::where('api_id', 'contract-12345')->firstOrFail();
        $snapshot = ContractSourceSnapshot::sole();
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => str_repeat('c', 64),
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'published_fields' => ['contract_type', 'fixed_time_range', 'metering', 'pricing_model'],
            'schema_version' => 'schema-v2',
            'prompt_version' => 'prompt-v5',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'output' => [],
            'published_at' => now(),
        ]);
        $contract->update([
            'published_interpretation_id' => $interpretation->id,
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => 'Fixed6',
            'metering' => 'Time',
            'pricing_model' => 'Spot',
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $contract->refresh();
        $this->assertSame('OpenEnded', $contract->contract_type);
        $this->assertSame('Fixed6', $contract->fixed_time_range);
        $this->assertSame('Time', $contract->metering);
        $this->assertSame('Spot', $contract->pricing_model);
        $this->assertSame($interpretation->id, $contract->published_interpretation_id);
    }

    public function test_new_source_price_waits_for_validation_when_a_canonical_version_exists(): void
    {
        $firstResponse = $this->getSampleApiResponse();
        $secondResponse = $this->getSampleApiResponse();
        $secondResponse[0]['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] = 6.5;
        Http::fakeSequence()
            ->push($firstResponse, 200)
            ->push($secondResponse, 200);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $contract = ElectricityContract::where('api_id', 'contract-12345')->firstOrFail();
        $snapshot = ContractSourceSnapshot::sole();
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => str_repeat('d', 64),
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'schema_version' => 'schema-v2',
            'prompt_version' => 'prompt-v5',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'output' => [],
            'published_at' => now(),
        ]);
        $contract->update(['published_interpretation_id' => $interpretation->id]);

        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', 'test-key');

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $component = PriceComponent::where('price_component_type', 'General')->sole();
        $this->assertSame(5.5, $component->price);
        $this->assertDatabaseHas('active_contracts', ['id' => $contract->id]);
        $this->assertSame(2, ContractInterpretation::count());
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 1);
    }

    public function test_command_stores_one_source_snapshot_for_an_unchanged_payload(): void
    {
        $response = $this->getSampleApiResponse();

        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response($response, 200),
        ]);

        $this->travelTo('2026-07-23 10:00:00');
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $this->travelTo('2026-07-24 10:00:00');
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $contract = ElectricityContract::where('api_id', 'contract-12345')->firstOrFail();
        $snapshot = ContractSourceSnapshot::sole();

        $this->assertSame($contract->id, $snapshot->contract_id);
        $this->assertEquals($response[0], $snapshot->source_payload);
        $this->assertSame(64, strlen($snapshot->source_fingerprint));
        $this->assertSame('2026-07-23 10:00:00', $snapshot->first_observed_at->toDateTimeString());
        $this->assertSame('2026-07-24 10:00:00', $snapshot->last_observed_at->toDateTimeString());
        $this->assertCount(1, $contract->sourceSnapshots);
    }

    public function test_command_queues_interpretation_after_the_snapshot_commits_when_enabled(): void
    {
        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $interpretation = ContractInterpretation::sole();
        $this->assertSame(ContractInterpretation::STATUS_PENDING, $interpretation->status);
        $this->assertDatabaseHas('contract_source_snapshots', ['id' => $interpretation->source_snapshot_id]);
        $this->assertDatabaseMissing('active_contracts', ['id' => $interpretation->contract_id]);
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 1);
    }

    public function test_unchanged_snapshot_retries_after_a_transient_pre_dispatch_failure(): void
    {
        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', null);
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200,
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $this->assertSame(1, ContractSourceSnapshot::count());
        $this->assertSame(0, ContractInterpretation::count());

        config()->set('services.openrouter.api_key', 'test-key');
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $this->assertSame(1, ContractSourceSnapshot::count());
        $this->assertSame(1, ContractInterpretation::count());
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 1);
    }

    public function test_command_creates_a_new_snapshot_when_the_source_payload_changes(): void
    {
        Http::fakeSequence()
            ->push($this->getSampleApiResponse(), 200)
            ->push($this->getSampleApiResponse('contract-12345', 'Changed contract name'), 200);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $snapshots = ContractSourceSnapshot::orderBy('id')->get();

        $this->assertCount(2, $snapshots);
        $this->assertNotSame($snapshots[0]->source_fingerprint, $snapshots[1]->source_fingerprint);
        $this->assertSame('Sähkösopimus Perus', $snapshots[0]->source_payload['Name']);
        $this->assertSame('Changed contract name', $snapshots[1]->source_payload['Name']);
    }

    public function test_command_refreshes_a_price_component_changed_on_the_same_day(): void
    {
        $firstResponse = $this->getSampleApiResponse();
        $secondResponse = $this->getSampleApiResponse();
        $secondResponse[0]['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] = 6.5;
        array_pop($secondResponse[0]['Details']['Pricing']['PriceComponents']);

        Http::fakeSequence()
            ->push($firstResponse, 200)
            ->push($secondResponse, 200);

        $this->travelTo('2026-07-23 10:00:00');
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $component = PriceComponent::where('price_component_type', 'General')->sole();

        $this->assertSame(6.5, $component->price);
        $this->assertSame(1, PriceComponent::count());
        $this->assertSame(2, ContractSourceSnapshot::count());
    }

    public function test_command_replaces_stale_postcode_and_dso_relationships(): void
    {
        $firstResponse = $this->getSampleApiResponse();
        $firstResponse[0]['Details']['AvailabilityArea']['Dsos'] = ['Old DSO'];
        $secondResponse = $this->getSampleApiResponse();
        $secondResponse[0]['Details']['AvailabilityArea']['PostalCodes'] = ['00100'];
        $secondResponse[0]['Details']['AvailabilityArea']['Dsos'] = ['New DSO'];

        Http::fakeSequence()
            ->push($firstResponse, 200)
            ->push($secondResponse, 200);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $contract = ElectricityContract::where('api_id', 'contract-12345')->firstOrFail();

        $this->assertDatabaseHas('contract_postcode', [
            'contract_id' => $contract->id,
            'postcode' => '00100',
        ]);
        $this->assertDatabaseMissing('contract_postcode', [
            'contract_id' => $contract->id,
            'postcode' => '02230',
        ]);
        $this->assertSame(['New DSO'], $contract->dsos()->pluck('name')->all());
    }

    public function test_command_rolls_back_source_snapshots_when_the_import_fails(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);
        $this->app->instance(
            ContractReplacementLinker::class,
            new class extends ContractReplacementLinker
            {
                public function __construct() {}

                public function linkHighConfidenceMatches(): array
                {
                    throw new \RuntimeException('Test import failure');
                }
            }
        );

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(1);

        $this->assertDatabaseCount('contract_source_snapshots', 0);
        $this->assertDatabaseCount('electricity_contracts', 0);
    }

    /**
     * Test command clears and repopulates active contracts table.
     */
    public function test_command_clears_active_contracts(): void
    {
        // Create an old active contract
        Company::create([
            'name' => 'Old Company',
            'name_slug' => 'old-company',
        ]);
        $oldContract = ElectricityContract::create([
            'id' => 'old-contract-id',
            'api_id' => 'old-api-id',
            'name' => 'Old Contract',
            'company_name' => 'Old Company',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        ActiveContract::create(['id' => $oldContract->id]);

        $this->assertEquals(1, ActiveContract::count());

        // API returns new contracts
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Old active contract should be removed, new one added
        $this->assertDatabaseMissing('active_contracts', ['id' => $oldContract->id]);
        $newContract = ElectricityContract::where('api_id', 'contract-12345')->first();
        $this->assertDatabaseHas('active_contracts', ['id' => $newContract->id]);
    }

    /**
     * Test command saves contract-postcode relationships.
     */
    public function test_command_saves_postcode_relationships(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Verify postcode relationship uses the new internal ID
        $contract = ElectricityContract::where('api_id', 'contract-12345')->first();
        $this->assertDatabaseHas('contract_postcode', [
            'contract_id' => $contract->id,
            'postcode' => '00100',
        ]);
    }

    /**
     * Test command saves spot futures data.
     */
    public function test_command_saves_spot_futures(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Verify spot futures were created
        $this->assertDatabaseHas('spot_futures', [
            'price' => 4.25,
        ]);
    }

    public function test_partial_postcode_failure_is_reported_as_incomplete_and_available_contracts_import(): void
    {
        $this->captureSentryIssues();
        Company::create([
            'name' => 'Regional Energy Oy',
            'name_slug' => 'regional-energy-oy',
        ]);
        $regionalContract = ElectricityContract::create([
            'id' => 'regional-contract',
            'api_id' => 'regional-api',
            'name' => 'Regional Contract',
            'company_name' => 'Regional Energy Oy',
            'contract_type' => 'FixedTerm',
            'metering' => 'General',
            'availability_is_national' => false,
        ]);
        ActiveContract::create(['id' => $regionalContract->id]);

        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/00100' => Http::response(
                $this->getSampleApiResponse(),
                200,
            ),
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/02230' => Http::response(
                ['error' => 'Server Error'],
                500,
            ),
        ]);

        $this->artisan('contracts:fetch', [
            '--postcodes' => '00100,02230',
            '--skip-logos' => true,
        ])
            ->expectsOutput('Contract acquisition was incomplete. Failed postcodes: 02230.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'contract-12345']);
        $this->assertDatabaseHas('active_contracts', ['id' => $regionalContract->id]);
        $this->assertImportIssue('contracts', 'warning', ['acquisition' => 1]);
    }

    public function test_required_statistics_failure_returns_exit_one_after_import(): void
    {
        $this->captureSentryIssues();
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200,
            ),
        ]);
        $statistics = $this->createMock(ContractPriceStatisticsService::class);
        $statistics->method('calculateForDate')
            ->willThrowException(new \RuntimeException('secret-token https://seller.test/?password=private SELECT raw_data'));
        $this->app->instance(ContractPriceStatisticsService::class, $statistics);

        $this->artisan('contracts:fetch', [
            '--postcodes' => '00100',
            '--skip-logos' => true,
        ])
            ->expectsOutput('Required post-import stage daily_statistics failed.')
            ->assertExitCode(1);
        $this->assertImportIssue('contracts', 'error', ['daily_statistics' => 1]);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame([\RuntimeException::class], $context['exception_classes']);
        $this->assertSame(['daily_statistics' => ['unexpected' => 1]], $context['reasons']);
        $this->assertStringNotContainsString('secret-token', json_encode($context));
        $this->assertStringNotContainsString('seller.test', json_encode($context));

        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'contract-12345']);
    }

    public function test_required_cache_failure_keeps_safe_class_and_reason_in_one_issue(): void
    {
        $this->captureSentryIssues();
        Log::spy();
        Http::fake(fn () => Http::response($this->getSampleApiResponse()));
        $cache = $this->createMock(ContractListCacheService::class);
        $cache->expects($this->once())->method('refresh')
            ->willThrowException(ContractPriceCacheConflict::evidenceChanged());
        $this->app->instance(ContractListCacheService::class, $cache);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])->assertExitCode(1);
        $this->assertImportIssue('contracts', 'error', ['price_cache_refresh' => 1]);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame([ContractPriceCacheConflict::class], $context['exception_classes']);
        $this->assertSame(['price_cache_refresh' => ['evidence_changed' => 1]], $context['reasons']);
        Log::shouldHaveReceived('log')->once()->with('error', 'Data fetch failed: contracts', $context);
    }

    public function test_recovered_refresh_conflict_does_not_fail_import_or_send_an_issue(): void
    {
        $this->captureSentryIssues();
        Http::fake(fn () => Http::response($this->getSampleApiResponse()));
        $real = app(ContractPriceCacheLifecycle::class);
        $calls = 0;
        $proxy = \Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('promote')->twice()->andReturnUsing(function ($starting, $candidate, $expected) use ($real, &$calls) {
            if (++$calls === 1) {
                $real->invalidate();
            }
            $real->promote($starting, $candidate, $expected);
        });
        $this->app->instance(ContractPriceCacheLifecycle::class, $proxy);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])->assertExitCode(0);
        $this->assertSame(2, $calls);
        $this->assertSame([], $this->sentryIssues);
    }

    /**
     * Test command handles API errors gracefully.
     */
    public function test_command_handles_api_errors(): void
    {
        $this->captureSentryIssues();
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                ['error' => 'Server Error'],
                500
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(1);
        $this->assertImportIssue('contracts', 'error', ['acquisition' => 1]);
    }

    /**
     * Test command retries failed API requests.
     */
    public function test_empty_scoped_response_reports_a_warning_without_changing_the_exit(): void
    {
        $this->captureSentryIssues();
        Http::fake(fn () => Http::response([]));
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);
        $this->assertImportIssue('contracts', 'warning', ['no_contracts' => 1]);
    }

    public function test_connection_failure_is_retried_and_recovery_is_silent(): void
    {
        $this->captureSentryIssues();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new ConnectionException('private URL and token');
            }

            return Http::response($this->getSampleApiResponse());
        });
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);
        $this->assertSame(3, $attempts);
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_exhausted_connection_failures_are_counted_once_per_postcode(): void
    {
        $this->captureSentryIssues();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('private URL and token');
        });
        $this->artisan('contracts:fetch', ['--postcodes' => '00100,02230', '--skip-logos' => true])
            ->assertExitCode(1);
        $this->assertSame(6, $attempts);
        $this->assertImportIssue('contracts', 'error', ['acquisition' => 2]);
        $this->assertSame(2, $this->sentryIssues[0]->getContexts()['data_fetch']['counts']['failed_postcodes']);
    }

    public function test_command_retries_on_failure(): void
    {
        $this->captureSentryIssues();
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                return Http::response(['error' => 'Temporary Error'], 503);
            }

            return Http::response($this->getSampleApiResponse(), 200);
        });

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        $this->assertSame([], $this->sentryIssues);
        // Should have retried and eventually succeeded
        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'contract-12345']);
    }

    /**
     * Test command handles discount data correctly.
     */
    public function test_command_handles_discount_data(): void
    {
        $response = $this->getSampleApiResponseWithDiscount();

        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $response,
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Verify discount data was saved (lookup by api_id then get internal id)
        $contract = ElectricityContract::where('api_id', 'discount-contract')->first();
        $priceComponent = PriceComponent::where('electricity_contract_id', $contract->id)
            ->where('price_component_type', 'General')
            ->first();

        $this->assertTrue($priceComponent->has_discount);
        $this->assertEquals(1.0, $priceComponent->discount_value);
        $this->assertFalse($priceComponent->discount_is_percentage);
        $this->assertEquals(3, $priceComponent->discount_discount_n_first_months);
    }

    /**
     * Test command handles time metering contracts.
     */
    public function test_command_handles_time_metering_contracts(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponseWithTimePricing(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Verify day and night price components (using internal ID)
        $contract = ElectricityContract::where('api_id', 'time-contract')->first();
        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'DayTime',
            'price' => 6.5,
        ]);

        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'NightTime',
            'price' => 4.5,
        ]);
    }

    /**
     * Test command handles seasonal metering contracts.
     */
    public function test_command_handles_seasonal_metering_contracts(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponseWithSeasonalPricing(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Verify seasonal price components (using internal ID)
        $contract = ElectricityContract::where('api_id', 'seasonal-contract')->first();
        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'SeasonalWinterDay',
            'price' => 8.0,
        ]);

        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'SeasonalOther',
            'price' => 5.0,
        ]);
    }

    /**
     * Test command outputs progress information.
     */
    public function test_command_outputs_progress(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->expectsOutput('Fetching contracts from Azure Consumer API...')
            ->expectsOutput('Fetching contracts for postcode: 00100')
            ->expectsOutput('Contracts fetched successfully!')
            ->assertExitCode(0);
    }

    public function test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        $this->fakeProductLists([
            '00100' => $this->getSampleApiResponse(),
            '02230' => array_merge($this->getSampleApiResponse('contract-67890', 'Toinen'), $this->getSampleApiResponse()),
        ]);
        $options = ['--postcodes' => '00100,02230', '--skip-logos' => true];

        // The default run inside a rolled-back savepoint, so the staged run starts from the same database.
        DB::beginTransaction();
        $this->assertSame(0, Artisan::call('contracts:fetch', $options));
        $line = $this->lastJsonLine();
        $this->assertSame(['stored', 'store', true], [$line['status'], $line['step'], $line['complete']]);
        $this->assertSame(['contracts' => 2, 'failed_postcodes' => 0, 'active_contracts' => 2, 'price_components' => 4], $line['counts']);
        $defaultRows = $this->importedRows();
        DB::rollBack();
        Cache::flush();
        $this->assertSame(0, ElectricityContract::count());

        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true] + $options));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame([2, 0, true], [$payload['requests'], $payload['failed_units'], $payload['complete']]);
        $this->assertSame(0, ElectricityContract::count() + ContractSourceSnapshot::count() + PriceComponent::count(), 'Fetch only writes no import rows.');

        // The store runs two hours later and keeps the fetch time and date (S5).
        $this->travelTo(CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('contracts:fetch', [
            '--from-payload' => $payload['path'], '--payload-sha256' => $payload['sha256'],
        ] + $options));
        $this->assertSame('stored', $this->lastJsonLine()['status']);

        $this->assertSame($defaultRows, $this->importedRows());
        $fetchedAt = $this->appWallTime('2026-11-19 06:00:00');
        $this->assertSame([$fetchedAt], DB::table('contract_source_observations')->distinct()->pluck('last_observed_at')->all());
        $this->assertSame([$fetchedAt], DB::table('contract_source_snapshots')->distinct()->pluck('first_observed_at')->all());
        $this->assertSame(['2026-11-19'], PriceComponent::query()->distinct()->pluck('price_date')->map(fn ($date) => substr((string) $date, 0, 10))->all());
    }

    public function test_store_failure_writes_no_row_reports_safe_sql_facts_and_a_replay_takes_over_the_checkpoint(): void
    {
        // 2026-11-19: Helen's 455-character ProductLink did not fit varchar(255) under MySQL strict mode.
        $this->captureSentryIssues();
        Log::spy();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        $longLink = 'https://www.helen.fi/sahko/'.str_repeat('x', 428);
        $response = $this->getSampleApiResponse();
        $response[0]['Details']['ProductLink'] = $longLink;
        Http::fake(['*' => Http::response($response)]);
        $columnFits = false;
        ElectricityContract::creating(function (ElectricityContract $contract) use (&$columnFits): void {
            if (! $columnFits && strlen((string) $contract->product_link) > 255) {
                throw $this->dataTooLong($contract);
            }
        });

        $this->assertSame(1, Artisan::call('contracts:fetch', ['--skip-logos' => true]));
        $output = Artisan::output();

        $payloads = glob(config('import_payloads.path').'/azure-consumer-api/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'The payload stays after a failed store.');
        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
            'sqlstate' => '22001', 'driver_code' => 1406, 'table' => 'electricity_contracts',
        ], $this->lastJsonLine($output));
        $this->assertStringNotContainsString(str_repeat('x', 100), $output);
        foreach (['companies', 'electricity_contracts', 'contract_source_snapshots', 'contract_source_observations', 'price_components', 'active_contracts', 'spot_futures'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "A failed store writes no {$table} row (S21).");
        }
        $failed = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $failed->status);
        $this->assertSame('import', $failed->metadata['stage']);
        $this->assertSame(['path' => $payloads[0], 'sha256' => trim(file_get_contents(str_replace('.ndjson.gz', '.sha256', $payloads[0])))], $failed->metadata['payload']);

        $this->assertImportIssue('contracts', 'error', ['import' => 1]);
        $context = $this->sentryIssues[0]->getContexts()['data_fetch'];
        $this->assertSame(['sqlstate' => '22001', 'driver_code' => 1406, 'table' => 'electricity_contracts'], $context['store_error']);
        $this->assertSame(['import' => 'store'], $context['steps']);
        $this->assertSame(['import' => ['sqlstate_22001' => 1]], $context['reasons']);
        $this->assertStringNotContainsString(str_repeat('x', 100), json_encode($context));
        $this->assertStringNotContainsString('contract-12345', json_encode($context));
        Log::shouldHaveReceived('log')->once()->with('error', 'Data fetch failed: contracts', $context);

        // After the fix, the same payload is stored two hours later without an API call.
        $columnFits = true;
        $this->travelTo(CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $payloads[0], '--skip-logos' => true]));

        $line = $this->lastJsonLine();
        $this->assertSame(['stored', true], [$line['status'], $line['complete']]);
        $this->assertSame($longLink, ElectricityContract::sole()->product_link);
        $this->assertSame($this->appWallTime('2026-11-19 06:00:00'), DB::table('contract_source_snapshots')->value('first_observed_at'));
        $ready = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $ready->status);
        $this->assertNotSame($failed->metadata['run_uuid'], $ready->metadata['run_uuid'], 'The replay owns the checkpoint with its own run.');
        // Bookkeeping stays real time: the completion deadline starts at the replay, not at the fetch.
        $this->assertTrue(CarbonImmutable::parse($ready->metadata['deadline'])->eq(now()->addHours(2)));
        $this->assertCount(1, $this->sentryIssues, 'The replay sends no Issue.');
    }

    public function test_store_refuses_a_payload_from_another_helsinki_date_before_any_write(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 23:30:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $fetchRun = DataFreshnessCheckpoint::sole();

        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payload['path'], '--skip-logos' => true]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'wrong_date',
            'payload_path' => $payload['path'], 'payload_sha256' => $payload['sha256'],
        ], $this->lastJsonLine());
        $this->assertSame(0, ElectricityContract::count() + Company::count() + ContractSourceSnapshot::count());
        // The fetch's own run (still its owner) fails with the payload reference; today's date gets none.
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame('2026-11-18', $checkpoint->effective_date->toDateString());
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame([
            'run_uuid' => $fetchRun->metadata['run_uuid'],
            'stage' => 'wrong_date',
            'payload' => ['path' => $payload['path'], 'sha256' => $payload['sha256']],
        ], $checkpoint->metadata);
    }

    public function test_partial_fetch_keeps_its_failed_postcode_through_the_payload(): void
    {
        $this->captureSentryIssues();
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Company::create(['name' => 'Regional Energy Oy', 'name_slug' => 'regional-energy-oy']);
        $regional = ElectricityContract::create([
            'id' => 'regional-contract', 'api_id' => 'regional-api', 'name' => 'Regional Contract',
            'company_name' => 'Regional Energy Oy', 'contract_type' => 'FixedTerm', 'metering' => 'General',
            'availability_is_national' => false,
        ]);
        ActiveContract::create(['id' => $regional->id]);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/02230')
            ? Http::response(['error' => 'Server Error'], 500)
            : Http::response($this->getSampleApiResponse()));

        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $this->assertSame([30, 1, false], [$payload['requests'], $payload['failed_units'], $payload['complete']]);
        $this->assertSame(DataFreshnessCheckpoint::STATUS_RUNNING, DataFreshnessCheckpoint::sole()->status, 'The store sets the final status.');
        // The fetch process reports the failed postcode, as the one process did before.
        $this->assertImportIssue('contracts', 'warning', ['acquisition' => 1]);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $payload['path'], '--skip-logos' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Contract acquisition was incomplete. Failed postcodes: 02230.', $output);
        $line = $this->lastJsonLine($output);
        $this->assertSame(['stored', false, 1], [$line['status'], $line['complete'], $line['counts']['failed_postcodes']]);
        $this->assertSame(DataFreshnessCheckpoint::STATUS_INCOMPLETE, DataFreshnessCheckpoint::sole()->status);
        $this->assertDatabaseHas('active_contracts', ['id' => $regional->id]);
        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'contract-12345']);
        $this->assertCount(1, $this->sentryIssues, 'The store of a partial payload sends no second Issue.');
    }

    public function test_all_failed_fetch_keeps_the_payload_with_its_error_bodies_and_writes_the_failed_checkpoint(): void
    {
        $this->captureSentryIssues();
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response(['error' => 'Server Error'], 503)]);

        $this->assertSame(1, Artisan::call('contracts:fetch', ['--skip-logos' => true]));

        $line = $this->lastJsonLine();
        $payloads = glob(config('import_payloads.path').'/azure-consumer-api/2026-11-19/*.ndjson.gz');
        $this->assertCount(1, $payloads, 'An all-failed fetch keeps its payload (design 7a).');
        $this->assertSame([
            'status' => 'failed', 'step' => 'fetch', 'error' => 'fetch_failed', 'reason' => 'all_failed',
            'payload_path' => $payloads[0], 'payload_sha256' => hash_file('sha256', $payloads[0]),
        ], $line);
        $lines = array_map(fn (string $json) => json_decode($json, true), array_filter(explode("\n", gzdecode(file_get_contents($payloads[0])))));
        $requests = array_values(array_filter($lines, fn (array $entry) => $entry['type'] === 'request'));
        $this->assertCount(30, $requests);
        $this->assertSame([503], array_values(array_unique(array_column($requests, 'status'))));
        $this->assertSame(['error' => 'Server Error'], json_decode($requests[0]['body'], true), 'The error body is kept for diagnosis.');
        $this->assertCount(30, end($lines)['failed_units']);

        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame(['run_uuid' => $checkpoint->metadata['run_uuid'], 'stage' => 'acquisition'], $checkpoint->metadata);
        $this->assertImportIssue('contracts', 'error', ['acquisition' => 30]);
        $this->assertSame(['acquisition' => 'fetch'], $this->sentryIssues[0]->getContexts()['data_fetch']['steps']);
    }

    public function test_store_from_payload_downloads_no_logo_even_without_skip_logos(): void
    {
        Storage::fake('public');
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--postcodes' => '00100']));
        $payload = $this->lastJsonLine()['payload'];

        // The company is new and has a logo URL but no local logo: default mode would download it.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['storage.example.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $payload['path'], '--postcodes' => '00100']));

        $this->assertSame('stored', $this->lastJsonLine()['status']);
        Http::assertNothingSent();
        $company = Company::sole();
        $this->assertSame('https://storage.example.com/logos/energia.png', $company->logo_url);
        $this->assertNull($company->local_logo_path);
    }

    public function test_an_older_same_day_payload_is_refused_and_leaves_newer_evidence_unchanged(): void
    {
        // 06:00 payload A offers 8 c/kWh, 10:00 payload B 12 c/kWh; a replay of A at 12:00 must not roll B back.
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(8.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--skip-logos' => true]));
        $payloadA = $this->lastJsonLine()['payload']['path'];

        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(12.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--skip-logos' => true]));
        $payloadB = $this->lastJsonLine()['payload']['path'];
        $rowsAfterB = $this->importedRows();
        $checkpointAfterB = DataFreshnessCheckpoint::sole()->getAttributes();
        $this->assertSame(12.0, (float) PriceComponent::query()->where('price_component_type', 'General')->sole()->price);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payloadA, '--skip-logos' => true]));

        // The newer payload file B supersedes A before the store step (StagedImport).
        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $payloadA, 'payload_sha256' => hash_file('sha256', $payloadA),
        ], $this->lastJsonLine());
        $this->assertSame($rowsAfterB, $this->importedRows(), 'Contract fields, price components and observations stay at B.');
        $this->assertEquals($checkpointAfterB, DataFreshnessCheckpoint::sole()->getAttributes(), 'The refused replay takes no checkpoint.');

        // The latest payload stored again (equal time) stays idempotent.
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $payloadB, '--skip-logos' => true]));
        $this->assertSame($rowsAfterB, $this->importedRows());
    }

    /**
     * Every contract store, full or scoped, takes the shared store lock before its freshness
     * check. While another store holds it past the wait, the store writes nothing and fails with
     * the closed error store_busy; the payload stays and a later replay stores it.
     */
    public function test_a_store_that_cannot_get_the_shared_store_lock_writes_nothing_and_is_busy(): void
    {
        $this->captureSentryIssues();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $full = $this->lastJsonLine()['payload'];
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--postcodes' => '00100', '--skip-logos' => true]));
        $scoped = $this->lastJsonLine()['payload'];
        $fetchRunUuid = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];
        $emptyRows = $this->importedRows();

        // Another process holds the lock for longer than the store waits.
        $holder = Cache::lock(FetchContracts::STORE_LOCK, 1800);
        $this->assertTrue($holder->get());
        Sleep::fake(syncWithCarbon: true);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $failedFetchRun = null;
        foreach ([['--from-payload' => $full['path']], ['--from-payload' => $scoped['path'], '--postcodes' => '00100']] as $options) {
            $this->sentryIssues = [];
            $this->assertSame(1, Artisan::call('contracts:fetch', $options + ['--skip-logos' => true]));

            $line = $this->lastJsonLine();
            $this->assertSame(['failed', 'store', 'store_busy'], [$line['status'], $line['step'], $line['error']]);
            $this->assertSame($options['--from-payload'], $line['payload']['path'], 'The payload stays for a replay.');
            $this->assertFileExists($options['--from-payload']);
            $this->assertSame($emptyRows, $this->importedRows());
            $this->assertImportIssue('contracts', 'error', ['store_busy' => 1]);
            $this->assertSame(['store_busy' => 'store'], $this->sentryIssues[0]->getContexts()['data_fetch']['steps']);

            if ($failedFetchRun === null) {
                // LAR-202: the busy store of the full payload fails the fetch's own `running` owner
                // (no new owner) with the payload reference.
                $failedFetchRun = DataFreshnessCheckpoint::sole()->getAttributes();
                $checkpoint = DataFreshnessCheckpoint::sole();
                $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
                $this->assertSame([
                    'run_uuid' => $fetchRunUuid,
                    'stage' => 'store_busy',
                    'payload' => ['path' => $full['path'], 'sha256' => $full['sha256']],
                ], $checkpoint->metadata);
            } else {
                $this->assertEquals($failedFetchRun, DataFreshnessCheckpoint::sole()->getAttributes(), 'A busy scoped store touches no checkpoint.');
            }
        }

        // In the default mode the fetch's own run fails, as for other store failures before takeover.
        $this->sentryIssues = [];
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--skip-logos' => true]));
        $this->assertSame('store_busy', $this->lastJsonLine()['error']);
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame([DataFreshnessCheckpoint::STATUS_FAILED, 'store_busy'], [$checkpoint->status, $checkpoint->metadata['stage']]);
        $this->assertSame($emptyRows, $this->importedRows());
        $this->assertImportIssue('contracts', 'error', ['store_busy' => 1]);

        $holder->release();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $scoped['path'], '--postcodes' => '00100', '--skip-logos' => true]));
        $this->assertSame(5.5, (float) PriceComponent::query()->where('price_component_type', 'General')->sole()->price);
    }

    /**
     * PRC-001 under concurrency: replay A (06:00, 8 c/kWh) starts while another process holds the
     * store lock, and that process commits a newer scoped store B (10:00, 12 c/kWh) before it lets
     * go. A's freshness check must see B, so it must read after A got the lock. The other process
     * is simulated in one PHPUnit process: the first lock wait of A (a faked sleep) stores B with
     * its own command instance and releases the lock. A check before the lock would pass while
     * no observation exists, and A would then overwrite B with 8 c/kWh. The holder holds the
     * trait's outer store lock, so A waits there and B takes both locks without waiting.
     */
    public function test_the_superseded_check_reads_a_newer_store_that_committed_while_the_store_waited_for_the_lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(8.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payloadA = $this->lastJsonLine()['payload']['path'];
        $fetchRunA = DataFreshnessCheckpoint::sole()->getAttributes();

        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(12.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--postcodes' => '00100', '--skip-logos' => true]));
        $payloadB = $this->lastJsonLine()['payload']['path'];

        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $holder = Cache::lock(self::TRAIT_STORE_LOCK, 1800);
        $this->assertTrue($holder->get());
        $storeB = null;
        $rowsAfterB = null;
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($holder, $payloadB, &$storeB, &$rowsAfterB): void {
            if ($storeB !== null) {
                return;
            }
            $holder->release();
            $command = $this->app->make(FetchContracts::class);
            $command->setLaravel($this->app);
            $storeB = $command->run(new ArrayInput(['--from-payload' => $payloadB, '--postcodes' => '00100', '--skip-logos' => true]), new BufferedOutput);
            $rowsAfterB = $this->importedRows();
        });

        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payloadA, '--skip-logos' => true]));

        $this->assertSame(0, $storeB, 'B stored while A waited for the lock.');
        // B is scoped (other options), so its payload file does not supersede A: the observation check does.
        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'superseded',
            'payload_path' => $payloadA, 'payload_sha256' => hash_file('sha256', $payloadA),
        ], $this->lastJsonLine());
        $this->assertSame($rowsAfterB, $this->importedRows(), 'Contract fields, price components and observations stay at B.');
        $this->assertSame(12.0, (float) PriceComponent::query()->where('price_component_type', 'General')->sole()->price);
        // LAR-202: the refused store takes no new owner but fails its fetch's still-owned marker.
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame([
            'run_uuid' => json_decode($fetchRunA['metadata'], true)['run_uuid'],
            'stage' => 'superseded',
            'payload' => ['path' => $payloadA, 'sha256' => hash_file('sha256', $payloadA)],
        ], $checkpoint->metadata);
        $this->assertTrue(Cache::lock(self::TRAIT_STORE_LOCK, 1)->get(), 'A refused store releases the outer lock.');
        $this->assertTrue(Cache::lock(FetchContracts::STORE_LOCK, 1)->get(), 'A refused store releases the inner lock.');
    }

    /**
     * LAR-202, newer owner: fetch A wrote `running` with its owner F, then fetch B took the date
     * over with G. A's store refused before its takeover (busy, then superseded) compares F with
     * the row and changes nothing; it never claims the date. B's payload file is under another
     * payload root, so the shared newer-payload refusal does not stop A before its store step.
     */
    public function test_a_split_store_refused_before_takeover_leaves_a_newer_owner_untouched(): void
    {
        $this->captureSentryIssues();
        $root = config('import_payloads.path');
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(8.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payloadA = $this->lastJsonLine()['payload']['path'];

        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'Europe/Helsinki'));
        config(['import_payloads.path' => "{$root}/other-root"]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(12.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payloadB = $this->lastJsonLine()['payload']['path'];
        config(['import_payloads.path' => $root]);
        $runningB = DataFreshnessCheckpoint::sole()->getAttributes();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_RUNNING, $runningB['status']);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $holder = Cache::lock(FetchContracts::STORE_LOCK, 3900);
        $this->assertTrue($holder->get());
        Sleep::fake(syncWithCarbon: true);
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payloadA, '--skip-logos' => true]));
        $this->assertSame('store_busy', $this->lastJsonLine()['error']);
        $this->assertEquals($runningB, DataFreshnessCheckpoint::sole()->getAttributes(), 'G stays running.');
        $this->assertImportIssue('contracts', 'error', ['store_busy' => 1]);
        $holder->release();

        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $payloadB, '--skip-logos' => true]));
        $readyB = DataFreshnessCheckpoint::sole()->getAttributes();
        $this->assertNotSame(json_decode($runningB['metadata'], true)['run_uuid'], json_decode($readyB['metadata'], true)['run_uuid'], 'B\'s store took over with its own owner.');

        $this->sentryIssues = [];
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payloadA, '--skip-logos' => true]));
        $this->assertSame('superseded', $this->lastJsonLine()['reason']);
        $this->assertEquals($readyB, DataFreshnessCheckpoint::sole()->getAttributes(), 'The newer owner stays as it was.');
        $this->assertImportIssue('contracts', 'error', ['payload_invalid' => 1]);
        $this->assertSame(12.0, (float) PriceComponent::query()->where('price_component_type', 'General')->sole()->price);
    }

    /**
     * A replay never seizes a live run (CodeRabbit, PR #31): newer default run B has written
     * `running` and is still fetching (no payload file yet). Replay A of an earlier split fetch
     * then fails `checkpoint_busy` without a write, and B keeps the date. After B failed, the same
     * replay takes the date over and stores.
     */
    public function test_a_replay_does_not_take_over_a_newer_running_run_but_recovers_after_it_failed(): void
    {
        $this->captureSentryIssues();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payloadA = $this->lastJsonLine()['payload'];

        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'Europe/Helsinki'));
        $completion = $this->app->make(ContractImportCompletion::class);
        $ownerB = $completion->start('2026-11-19');
        $runningB = DataFreshnessCheckpoint::sole()->getAttributes();
        $emptyRows = $this->importedRows();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payloadA['path'], '--skip-logos' => true]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'checkpoint_busy',
            'payload_path' => $payloadA['path'], 'payload_sha256' => $payloadA['sha256'],
        ], array_diff_key($this->lastJsonLine(), ['payload' => true, 'complete' => true, 'counts' => true]));
        $this->assertEquals($runningB, DataFreshnessCheckpoint::sole()->getAttributes(), 'B keeps the date and stays running.');
        $this->assertSame($emptyRows, $this->importedRows());
        $this->assertFileExists($payloadA['path']);
        $this->assertImportIssue('contracts', 'error', ['checkpoint_busy' => 1]);
        $this->assertSame(['checkpoint_busy' => 'store'], $this->sentryIssues[0]->getContexts()['data_fetch']['steps']);
        $this->assertTrue(Cache::lock(FetchContracts::STORE_LOCK, 1)->get(), 'A busy store releases the store lock.');
        Cache::lock(FetchContracts::STORE_LOCK)->forceRelease();

        $this->assertTrue($completion->record('2026-11-19', $ownerB, DataFreshnessCheckpoint::STATUS_FAILED, ['stage' => 'acquisition']));
        $this->sentryIssues = [];
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $payloadA['path'], '--skip-logos' => true]));

        $this->assertSame('stored', $this->lastJsonLine()['status']);
        $ready = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $ready->status);
        $this->assertNotSame($ownerB, $ready->metadata['run_uuid'], 'The replay owns the recovered date.');
        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'contract-12345']);
        $this->assertSame([], $this->sentryIssues);
    }

    /**
     * The date is checked again once the store holds the store lock: a replay that waited past
     * midnight is refused `wrong_date` before its takeover and writes nothing. The lock wait is
     * simulated by the other holder releasing the lock during the store's first faked sleep.
     */
    public function test_a_store_that_waits_for_the_lock_past_midnight_is_refused_wrong_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 23:58:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $fetchRunUuid = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $holder = Cache::lock(FetchContracts::STORE_LOCK, 3900);
        $this->assertTrue($holder->get());
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($holder): void {
            $this->travelTo(CarbonImmutable::parse('2026-11-20 00:01:00', 'Europe/Helsinki'));
            $holder->release();
        });

        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payload['path'], '--skip-logos' => true]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'payload_invalid', 'reason' => 'wrong_date',
            'payload_path' => $payload['path'], 'payload_sha256' => $payload['sha256'],
        ], $this->lastJsonLine());
        $this->assertSame(0, ElectricityContract::count() + Company::count() + ContractSourceSnapshot::count());
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame('2026-11-19', $checkpoint->effective_date->toDateString(), 'No takeover of the new day.');
        $this->assertSame([DataFreshnessCheckpoint::STATUS_FAILED, $fetchRunUuid, 'wrong_date'], [$checkpoint->status, $checkpoint->metadata['run_uuid'], $checkpoint->metadata['stage']]);
        $this->assertTrue(Cache::lock(FetchContracts::STORE_LOCK, 1)->get(), 'A refused store releases the lock.');
    }

    /**
     * LAR-202: a refused replay never turns a settled checkpoint of its own fetch run into failed.
     * The payload names the settled owner, so only the `running` guard of failFetchRun() keeps it.
     */
    public function test_a_refused_replay_of_a_settled_run_keeps_its_checkpoint(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payload = $this->lastJsonLine()['payload']['path'];
        $fetchOwner = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];
        // The fetch run settles while it still owns the date.
        $this->assertTrue($this->app->make(ContractImportCompletion::class)->record('2026-11-19', $fetchOwner, DataFreshnessCheckpoint::STATUS_INCOMPLETE, ['stage' => 'settled']));
        $settled = DataFreshnessCheckpoint::sole()->getAttributes();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $holder = Cache::lock(FetchContracts::STORE_LOCK, 3900);
        $this->assertTrue($holder->get());
        Sleep::fake(syncWithCarbon: true);
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payload, '--skip-logos' => true]));

        $this->assertSame('store_busy', $this->lastJsonLine()['error']);
        $this->assertEquals($settled, DataFreshnessCheckpoint::sole()->getAttributes());
        $holder->release();
    }

    /**
     * CodeRabbit 4628722493 (PR #31): store S of payload P took the date over and was killed
     * before its import committed, so the row stays `running` under S with P's SHA-256 and S's
     * store locks are gone. A store of another payload Q still fails `checkpoint_busy`; a replay
     * of P takes the row over (it holds the outer store lock, which a live S would still hold)
     * and settles it. Q is older and its file is under another payload root, so the payload
     * files supersede neither replay; the takeover alone decides.
     */
    public function test_a_replay_recovers_an_abandoned_store_of_the_same_payload_but_not_of_another(): void
    {
        $this->captureSentryIssues();
        $root = config('import_payloads.path');
        $this->travelTo(CarbonImmutable::parse('2026-11-19 05:00:00', 'Europe/Helsinki'));
        config(['import_payloads.path' => "{$root}/other-root"]);
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(8.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payloadQ = $this->lastJsonLine()['payload'];
        config(['import_payloads.path' => $root]);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->sampleWithEnergyPrice(12.0))]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payloadP = $this->lastJsonLine()['payload'];
        $fetchOwnerP = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];

        // Store S's committed takeover; then the process dies: no rows, no locks held.
        $storeS = $this->app->make(ContractImportCompletion::class)
            ->takeOver('2026-11-19', $fetchOwnerP, ['path' => $payloadP['path'], 'sha256' => $payloadP['sha256']]);
        $this->assertNotNull($storeS);
        $abandoned = DataFreshnessCheckpoint::sole()->getAttributes();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_RUNNING, $abandoned['status']);
        $emptyRows = $this->importedRows();

        $this->travelTo(CarbonImmutable::parse('2026-11-19 07:00:00', 'Europe/Helsinki'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['import_payloads.path' => "{$root}/other-root"]);
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payloadQ['path'], '--skip-logos' => true]));
        config(['import_payloads.path' => $root]);
        $this->assertSame(['failed', 'store', 'checkpoint_busy'], array_values(array_intersect_key($this->lastJsonLine(), array_flip(['status', 'step', 'error']))));
        $this->assertEquals($abandoned, DataFreshnessCheckpoint::sole()->getAttributes(), 'A store of another payload leaves S running.');
        $this->assertSame($emptyRows, $this->importedRows());
        $this->assertImportIssue('contracts', 'error', ['checkpoint_busy' => 1]);

        $this->sentryIssues = [];
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--from-payload' => $payloadP['path'], '--skip-logos' => true]));

        $this->assertSame('stored', $this->lastJsonLine()['status']);
        $ready = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_READY, $ready->status);
        $this->assertNotContains($ready->metadata['run_uuid'], [$storeS, $fetchOwnerP], 'The replay owns the date with its own run.');
        $this->assertSame(12.0, (float) PriceComponent::query()->where('price_component_type', 'General')->sole()->price);
        $this->assertSame([], $this->sentryIssues);
    }

    /**
     * A split store that waits in vain for the outer store lock (StagedImport, before the store
     * closure runs) finishes its fetch's `running` owner like a store refused inside the closure
     * (LAR-202): failed with stage `store_busy` and the payload reference, no new owner, no row.
     */
    public function test_a_split_store_busy_at_the_outer_store_lock_fails_its_fetch_owner(): void
    {
        $this->captureSentryIssues();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'Europe/Helsinki'));
        Http::fake(['*' => Http::response($this->getSampleApiResponse())]);
        $this->assertSame(0, Artisan::call('contracts:fetch', ['--fetch-only' => true, '--skip-logos' => true]));
        $payload = $this->lastJsonLine()['payload'];
        $fetchOwner = DataFreshnessCheckpoint::sole()->metadata['run_uuid'];
        $emptyRows = $this->importedRows();

        $holder = Cache::lock(self::TRAIT_STORE_LOCK, 1800);
        $this->assertTrue($holder->get());
        Sleep::fake(syncWithCarbon: true);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('contracts:fetch', ['--from-payload' => $payload['path'], '--skip-logos' => true]));

        $this->assertSame([
            'status' => 'failed', 'step' => 'store', 'error' => 'store_busy',
            'payload_path' => $payload['path'], 'payload_sha256' => $payload['sha256'],
        ], $this->lastJsonLine());
        $checkpoint = DataFreshnessCheckpoint::sole();
        $this->assertSame(DataFreshnessCheckpoint::STATUS_FAILED, $checkpoint->status);
        $this->assertSame([
            'run_uuid' => $fetchOwner,
            'stage' => 'store_busy',
            'payload' => ['path' => $payload['path'], 'sha256' => $payload['sha256']],
        ], $checkpoint->metadata);
        $this->assertSame($emptyRows, $this->importedRows());
        $this->assertImportIssue('contracts', 'error', ['store_busy' => 1]);
        $this->assertTrue(Cache::lock(FetchContracts::STORE_LOCK, 1)->get(), 'The inner lock was never taken.');
        $holder->release();
    }

    /**
     * A scoped run whose answered postcodes were empty and whose other postcodes failed has no
     * contracts and an incomplete acquisition: it exits 1 (`fetch_incomplete`), as before the
     * staged import. An empty answer alone stays a warning with exit 0.
     */
    public function test_a_scoped_run_without_contracts_and_with_failed_postcodes_exits_one(): void
    {
        $this->captureSentryIssues();
        Sleep::fake();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/02230')
            ? Http::response(['error' => 'Server Error'], 500)
            : Http::response([]));

        $this->assertSame(1, Artisan::call('contracts:fetch', ['--postcodes' => '00100,02230', '--skip-logos' => true]));

        $line = $this->lastJsonLine();
        $this->assertSame(['failed', 'fetch', 'fetch_incomplete', false], [$line['status'], $line['step'], $line['error'], $line['complete']]);
        $this->assertSame(['contracts' => 0, 'failed_postcodes' => 1], $line['counts']);
        $this->assertSame(0, ElectricityContract::count());
        $this->assertImportIssue('contracts', 'error', ['acquisition' => 1, 'no_contracts' => 1]);
    }

    /** @return list<array<string, mixed>> the sample product list with this General energy price */
    private function sampleWithEnergyPrice(float $price): array
    {
        $response = $this->getSampleApiResponse();
        $response[0]['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] = $price;

        return $response;
    }

    /** @param array<string, list<array<string, mixed>>> $responses  postcode => product list */
    private function fakeProductLists(array $responses): void
    {
        Http::fake(array_combine(
            array_map(fn (string $postcode) => "ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/{$postcode}", array_keys($responses)),
            array_map(fn (array $contracts) => Http::response($contracts), $responses),
        ));
    }

    /** @return array<string, list<array<string, mixed>>> every import table, without write times */
    private function importedRows(): array
    {
        $tables = ['companies', 'electricity_contracts', 'price_components', 'electricity_sources', 'contract_source_snapshots',
            'contract_source_observations', 'active_contracts', 'contract_postcode', 'contract_dso', 'dsos', 'spot_futures'];
        // A new contract's ID has a random prefix, so rows name contracts by their API ID.
        $apiIds = DB::table('electricity_contracts')->pluck('api_id', 'id')->all();
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->get()
                ->map(fn ($row) => array_map(
                    fn ($value) => is_string($value) && isset($apiIds[$value]) ? 'contract:'.$apiIds[$value] : $value,
                    array_diff_key((array) $row, array_flip(['created_at', 'updated_at'])),
                ))
                ->sortBy(fn (array $row) => json_encode($row))->values()->all();
        }

        return $rows;
    }

    /** A Helsinki time as the application stores it (wall time in the app timezone, like now()). */
    private function appWallTime(string $helsinki): string
    {
        return CarbonImmutable::parse($helsinki, 'Europe/Helsinki')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /** MySQL strict mode's answer to a too-long varchar value, with the value among the bindings. */
    private function dataTooLong(ElectricityContract $contract): QueryException
    {
        $driver = new \PDOException("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'product_link' at row 1");
        $driver->errorInfo = ['22001', 1406, "Data too long for column 'product_link' at row 1"];

        return new QueryException(
            'mysql',
            'insert into `electricity_contracts` (`id`, `api_id`, `product_link`) values (?, ?, ?)',
            [$contract->id, $contract->api_id, $contract->product_link],
            $driver,
        );
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(?string $output = null): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output ?? Artisan::output()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Get a sample API response for testing.
     */
    protected function getSampleApiResponse(
        string $contractId = 'contract-12345',
        string $contractName = 'Sähkösopimus Perus',
        bool $hasDiscount = false
    ): array {
        return [
            [
                'Id' => $contractId,
                'Name' => $contractName,
                'Company' => [
                    'Name' => 'Energia Oy',
                    'CompanyUrl' => 'https://energia.fi',
                    'StreetAddress' => 'Energiakatu 1',
                    'PostalCode' => '00100',
                    'PostalName' => 'Helsinki',
                    'LogoURL' => 'https://storage.example.com/logos/energia.png',
                ],
                'Details' => [
                    'ContractType' => 'Fixed',
                    'SpotPriceSelection' => null,
                    'FixedTimeRange' => '24 kk',
                    'Metering' => 'General',
                    'PricingModel' => 'FixedPrice',
                    'TargetGroup' => 'Household',
                    'Pricing' => [
                        'Name' => 'General Price',
                        'HasDiscount' => $hasDiscount,
                        'ElectricitySupplyProductId' => $contractId,
                        'PriceComponents' => [
                            [
                                'Id' => 'pc-general-'.$contractId,
                                'PriceComponentType' => 'General',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 5.5,
                                    'PaymentUnit' => 'c/kWh',
                                ],
                            ],
                            [
                                'Id' => 'pc-monthly-'.$contractId,
                                'PriceComponentType' => 'Monthly',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 2.95,
                                    'PaymentUnit' => 'EUR/month',
                                ],
                            ],
                        ],
                    ],
                    'ConsumptionControl' => false,
                    'ConsumptionLimitation' => [
                        'MinXKWhPerY' => null,
                        'MaxXKWhPerY' => null,
                    ],
                    'PreBilling' => false,
                    'AvailableForExistingUsers' => true,
                    'DeliveryResponsibilityProduct' => false,
                    'OrderLink' => 'https://energia.fi/order',
                    'ProductLink' => 'https://energia.fi/product',
                    'BillingFrequency' => ['Monthly' => true],
                    'TransparencyIndex' => ['Score' => 85],
                    'ExtraInformation' => [
                        'Default' => 'Extra info',
                        'FI' => 'Lisätietoja',
                        'EN' => 'Extra info',
                        'SV' => 'Extra information',
                    ],
                    'MicroProduction' => [
                        'Buys' => true,
                        'Details' => [
                            'Default' => 'Microproduction info',
                            'FI' => 'Pientuotanto',
                            'SV' => 'Mikroproduktion',
                            'EN' => 'Microproduction',
                        ],
                    ],
                    'AvailabilityArea' => [
                        'IsNational' => false,
                        'PostalCodes' => ['00100', '02230'],
                    ],
                    'ElectricitySource' => [
                        'Renewable' => [
                            'Total' => 100.0,
                            'BioMass' => 0.0,
                            'Solar' => 0.0,
                            'Wind' => 50.0,
                            'General' => 0.0,
                            'Hydro' => 50.0,
                        ],
                        'Fossil' => [
                            'Total' => 0.0,
                            'Oil' => 0.0,
                            'Coal' => 0.0,
                            'NaturalGas' => 0.0,
                            'Peat' => 0.0,
                        ],
                        'Nuclear' => [
                            'Total' => 0.0,
                            'General' => 0.0,
                        ],
                    ],
                    'SpotFutures' => 4.25,
                ],
            ],
        ];
    }

    /**
     * Get a sample API response with discount data.
     */
    protected function getSampleApiResponseWithDiscount(): array
    {
        $response = $this->getSampleApiResponse('discount-contract', 'Discount Contract', true);
        $response[0]['Details']['Pricing']['PriceComponents'][0]['HasDiscount'] = true;
        $response[0]['Details']['Pricing']['PriceComponents'][0]['Discount'] = [
            'DiscountValue' => 1.0,
            'IsPercentage' => false,
            'DiscountType' => 'FirstMonths',
            'NFirstKwh' => null,
            'NfirstMonths' => 3,
            'UntilDate' => '2027-01-01T00:00:00',
        ];

        return $response;
    }

    /**
     * Get a sample API response with time-based pricing.
     */
    protected function getSampleApiResponseWithTimePricing(): array
    {
        return [
            [
                'Id' => 'time-contract',
                'Name' => 'Time Metering Contract',
                'Company' => [
                    'Name' => 'Energia Oy',
                    'CompanyUrl' => 'https://energia.fi',
                    'StreetAddress' => 'Energiakatu 1',
                    'PostalCode' => '00100',
                    'PostalName' => 'Helsinki',
                    'LogoURL' => 'https://storage.example.com/logos/energia.png',
                ],
                'Details' => [
                    'ContractType' => 'Fixed',
                    'SpotPriceSelection' => null,
                    'FixedTimeRange' => '12 kk',
                    'Metering' => 'Time',
                    'Pricing' => [
                        'Name' => 'Time Price',
                        'HasDiscount' => false,
                        'ElectricitySupplyProductId' => 'time-contract',
                        'PriceComponents' => [
                            [
                                'Id' => 'pc-day',
                                'PriceComponentType' => 'DayTime',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 6.5,
                                    'PaymentUnit' => 'c/kWh',
                                ],
                            ],
                            [
                                'Id' => 'pc-night',
                                'PriceComponentType' => 'NightTime',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 4.5,
                                    'PaymentUnit' => 'c/kWh',
                                ],
                            ],
                            [
                                'Id' => 'pc-monthly-time',
                                'PriceComponentType' => 'Monthly',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 3.5,
                                    'PaymentUnit' => 'EUR/month',
                                ],
                            ],
                        ],
                    ],
                    'ConsumptionControl' => false,
                    'ConsumptionLimitation' => [
                        'MinXKWhPerY' => null,
                        'MaxXKWhPerY' => null,
                    ],
                    'PreBilling' => false,
                    'AvailableForExistingUsers' => true,
                    'DeliveryResponsibilityProduct' => false,
                    'OrderLink' => 'https://energia.fi/order',
                    'ProductLink' => 'https://energia.fi/product',
                    'BillingFrequency' => ['Monthly' => true],
                    'TransparencyIndex' => ['Score' => 85],
                    'ExtraInformation' => [
                        'Default' => 'Extra info',
                        'FI' => 'Lisätietoja',
                        'EN' => 'Extra info',
                        'SV' => 'Extra information',
                    ],
                    'MicroProduction' => [
                        'Buys' => false,
                        'Details' => [
                            'Default' => '',
                            'FI' => '',
                            'SV' => '',
                            'EN' => '',
                        ],
                    ],
                    'AvailabilityArea' => [
                        'IsNational' => true,
                        'PostalCodes' => [],
                    ],
                    'ElectricitySource' => [
                        'Renewable' => [
                            'Total' => 80.0,
                            'BioMass' => 0.0,
                            'Solar' => 10.0,
                            'Wind' => 40.0,
                            'General' => 0.0,
                            'Hydro' => 30.0,
                        ],
                        'Fossil' => [
                            'Total' => 10.0,
                            'Oil' => 0.0,
                            'Coal' => 0.0,
                            'NaturalGas' => 10.0,
                            'Peat' => 0.0,
                        ],
                        'Nuclear' => [
                            'Total' => 10.0,
                            'General' => 10.0,
                        ],
                    ],
                    'SpotFutures' => 4.50,
                ],
            ],
        ];
    }

    /**
     * Test command handles null UUID price components correctly.
     *
     * The Azure API sometimes returns null UUIDs (00000000-0000-0000-0000-000000000000)
     * for price component IDs. The command should generate unique IDs in this case
     * to avoid composite key conflicts.
     */
    public function test_command_handles_null_uuid_price_components(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponseWithNullUuidPriceComponents(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Both contracts should have their price components saved
        // even though they both have the same null UUID from the API
        $contract1 = ElectricityContract::where('api_id', 'contract-null-1')->first();
        $contract2 = ElectricityContract::where('api_id', 'contract-null-2')->first();
        $contract1Components = PriceComponent::where('electricity_contract_id', $contract1->id)->count();
        $contract2Components = PriceComponent::where('electricity_contract_id', $contract2->id)->count();

        // Each contract should have 2 price components (General and Monthly)
        $this->assertEquals(2, $contract1Components);
        $this->assertEquals(2, $contract2Components);

        // Total should be 4 price components
        $this->assertEquals(4, PriceComponent::count());
    }

    /**
     * Test command generates unique IDs for multiple null UUID components on same contract.
     */
    public function test_command_handles_multiple_null_uuid_components_on_same_contract(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponseWithMultipleNullUuidsOnSameContract(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Contract should have 3 price components (General, Monthly both with null UUID, plus one normal)
        $contract = ElectricityContract::where('api_id', 'contract-multi-null')->first();
        $componentCount = PriceComponent::where('electricity_contract_id', $contract->id)->count();
        $this->assertEquals(3, $componentCount);
    }

    /**
     * Get a sample API response with null UUID price components.
     */
    protected function getSampleApiResponseWithNullUuidPriceComponents(): array
    {
        $nullUuid = '00000000-0000-0000-0000-000000000000';

        return [
            [
                'Id' => 'contract-null-1',
                'Name' => 'Contract With Null UUID 1',
                'Company' => [
                    'Name' => 'Energia Oy',
                    'CompanyUrl' => 'https://energia.fi',
                    'StreetAddress' => 'Energiakatu 1',
                    'PostalCode' => '00100',
                    'PostalName' => 'Helsinki',
                    'LogoURL' => '',
                ],
                'Details' => [
                    'ContractType' => 'Fixed',
                    'SpotPriceSelection' => null,
                    'FixedTimeRange' => '12 kk',
                    'Metering' => 'General',
                    'Pricing' => [
                        'Name' => 'Price',
                        'HasDiscount' => false,
                        'ElectricitySupplyProductId' => 'contract-null-1',
                        'PriceComponents' => [
                            [
                                'Id' => $nullUuid,
                                'PriceComponentType' => 'General',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => ['Price' => 5.0, 'PaymentUnit' => 'c/kWh'],
                            ],
                            [
                                'Id' => $nullUuid,
                                'PriceComponentType' => 'Monthly',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => ['Price' => 2.0, 'PaymentUnit' => 'EUR/month'],
                            ],
                        ],
                    ],
                    'ConsumptionControl' => false,
                    'ConsumptionLimitation' => ['MinXKWhPerY' => null, 'MaxXKWhPerY' => null],
                    'PreBilling' => false,
                    'AvailableForExistingUsers' => true,
                    'DeliveryResponsibilityProduct' => false,
                    'OrderLink' => '',
                    'ProductLink' => '',
                    'BillingFrequency' => ['Monthly' => true],
                    'TransparencyIndex' => ['Score' => 80],
                    'ExtraInformation' => ['Default' => '', 'FI' => '', 'EN' => '', 'SV' => ''],
                    'MicroProduction' => ['Buys' => false, 'Details' => ['Default' => '', 'FI' => '', 'SV' => '', 'EN' => '']],
                    'AvailabilityArea' => ['IsNational' => true, 'PostalCodes' => []],
                    'ElectricitySource' => [
                        'Renewable' => ['Total' => 100.0, 'BioMass' => 0.0, 'Solar' => 0.0, 'Wind' => 100.0, 'General' => 0.0, 'Hydro' => 0.0],
                        'Fossil' => ['Total' => 0.0, 'Oil' => 0.0, 'Coal' => 0.0, 'NaturalGas' => 0.0, 'Peat' => 0.0],
                        'Nuclear' => ['Total' => 0.0, 'General' => 0.0],
                    ],
                    'SpotFutures' => 4.0,
                ],
            ],
            [
                'Id' => 'contract-null-2',
                'Name' => 'Contract With Null UUID 2',
                'Company' => [
                    'Name' => 'Energia Oy',
                    'CompanyUrl' => 'https://energia.fi',
                    'StreetAddress' => 'Energiakatu 1',
                    'PostalCode' => '00100',
                    'PostalName' => 'Helsinki',
                    'LogoURL' => '',
                ],
                'Details' => [
                    'ContractType' => 'Fixed',
                    'SpotPriceSelection' => null,
                    'FixedTimeRange' => '24 kk',
                    'Metering' => 'General',
                    'Pricing' => [
                        'Name' => 'Price',
                        'HasDiscount' => false,
                        'ElectricitySupplyProductId' => 'contract-null-2',
                        'PriceComponents' => [
                            [
                                'Id' => $nullUuid,
                                'PriceComponentType' => 'General',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => ['Price' => 6.0, 'PaymentUnit' => 'c/kWh'],
                            ],
                            [
                                'Id' => $nullUuid,
                                'PriceComponentType' => 'Monthly',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => ['Price' => 3.0, 'PaymentUnit' => 'EUR/month'],
                            ],
                        ],
                    ],
                    'ConsumptionControl' => false,
                    'ConsumptionLimitation' => ['MinXKWhPerY' => null, 'MaxXKWhPerY' => null],
                    'PreBilling' => false,
                    'AvailableForExistingUsers' => true,
                    'DeliveryResponsibilityProduct' => false,
                    'OrderLink' => '',
                    'ProductLink' => '',
                    'BillingFrequency' => ['Monthly' => true],
                    'TransparencyIndex' => ['Score' => 85],
                    'ExtraInformation' => ['Default' => '', 'FI' => '', 'EN' => '', 'SV' => ''],
                    'MicroProduction' => ['Buys' => false, 'Details' => ['Default' => '', 'FI' => '', 'SV' => '', 'EN' => '']],
                    'AvailabilityArea' => ['IsNational' => true, 'PostalCodes' => []],
                    'ElectricitySource' => [
                        'Renewable' => ['Total' => 80.0, 'BioMass' => 0.0, 'Solar' => 0.0, 'Wind' => 80.0, 'General' => 0.0, 'Hydro' => 0.0],
                        'Fossil' => ['Total' => 10.0, 'Oil' => 0.0, 'Coal' => 0.0, 'NaturalGas' => 10.0, 'Peat' => 0.0],
                        'Nuclear' => ['Total' => 10.0, 'General' => 10.0],
                    ],
                    'SpotFutures' => 4.5,
                ],
            ],
        ];
    }

    /**
     * Get a sample API response with multiple null UUID components on same contract.
     */
    protected function getSampleApiResponseWithMultipleNullUuidsOnSameContract(): array
    {
        $nullUuid = '00000000-0000-0000-0000-000000000000';

        return [
            [
                'Id' => 'contract-multi-null',
                'Name' => 'Contract With Multiple Null UUIDs',
                'Company' => [
                    'Name' => 'Energia Oy',
                    'CompanyUrl' => 'https://energia.fi',
                    'StreetAddress' => 'Energiakatu 1',
                    'PostalCode' => '00100',
                    'PostalName' => 'Helsinki',
                    'LogoURL' => '',
                ],
                'Details' => [
                    'ContractType' => 'Fixed',
                    'SpotPriceSelection' => null,
                    'FixedTimeRange' => '12 kk',
                    'Metering' => 'General',
                    'Pricing' => [
                        'Name' => 'Price',
                        'HasDiscount' => false,
                        'ElectricitySupplyProductId' => 'contract-multi-null',
                        'PriceComponents' => [
                            [
                                'Id' => $nullUuid,
                                'PriceComponentType' => 'General',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => ['Price' => 5.0, 'PaymentUnit' => 'c/kWh'],
                            ],
                            [
                                'Id' => $nullUuid,
                                'PriceComponentType' => 'Monthly',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => ['Price' => 2.5, 'PaymentUnit' => 'EUR/month'],
                            ],
                            [
                                'Id' => 'valid-uuid-123',
                                'PriceComponentType' => 'Monthly',
                                'FuseSize' => '25A',
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => ['Price' => 3.5, 'PaymentUnit' => 'EUR/month'],
                            ],
                        ],
                    ],
                    'ConsumptionControl' => false,
                    'ConsumptionLimitation' => ['MinXKWhPerY' => null, 'MaxXKWhPerY' => null],
                    'PreBilling' => false,
                    'AvailableForExistingUsers' => true,
                    'DeliveryResponsibilityProduct' => false,
                    'OrderLink' => '',
                    'ProductLink' => '',
                    'BillingFrequency' => ['Monthly' => true],
                    'TransparencyIndex' => ['Score' => 90],
                    'ExtraInformation' => ['Default' => '', 'FI' => '', 'EN' => '', 'SV' => ''],
                    'MicroProduction' => ['Buys' => false, 'Details' => ['Default' => '', 'FI' => '', 'SV' => '', 'EN' => '']],
                    'AvailabilityArea' => ['IsNational' => true, 'PostalCodes' => []],
                    'ElectricitySource' => [
                        'Renewable' => ['Total' => 100.0, 'BioMass' => 0.0, 'Solar' => 0.0, 'Wind' => 100.0, 'General' => 0.0, 'Hydro' => 0.0],
                        'Fossil' => ['Total' => 0.0, 'Oil' => 0.0, 'Coal' => 0.0, 'NaturalGas' => 0.0, 'Peat' => 0.0],
                        'Nuclear' => ['Total' => 0.0, 'General' => 0.0],
                    ],
                    'SpotFutures' => 4.0,
                ],
            ],
        ];
    }

    /**
     * Get a sample API response with seasonal pricing.
     */
    protected function getSampleApiResponseWithSeasonalPricing(): array
    {
        return [
            [
                'Id' => 'seasonal-contract',
                'Name' => 'Seasonal Metering Contract',
                'Company' => [
                    'Name' => 'Energia Oy',
                    'CompanyUrl' => 'https://energia.fi',
                    'StreetAddress' => 'Energiakatu 1',
                    'PostalCode' => '00100',
                    'PostalName' => 'Helsinki',
                    'LogoURL' => 'https://storage.example.com/logos/energia.png',
                ],
                'Details' => [
                    'ContractType' => 'Fixed',
                    'SpotPriceSelection' => null,
                    'FixedTimeRange' => '24 kk',
                    'Metering' => 'Seasonal',
                    'Pricing' => [
                        'Name' => 'Seasonal Price',
                        'HasDiscount' => false,
                        'ElectricitySupplyProductId' => 'seasonal-contract',
                        'PriceComponents' => [
                            [
                                'Id' => 'pc-winter',
                                'PriceComponentType' => 'SeasonalWinterDay',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 8.0,
                                    'PaymentUnit' => 'c/kWh',
                                ],
                            ],
                            [
                                'Id' => 'pc-other',
                                'PriceComponentType' => 'SeasonalOther',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 5.0,
                                    'PaymentUnit' => 'c/kWh',
                                ],
                            ],
                            [
                                'Id' => 'pc-monthly-seasonal',
                                'PriceComponentType' => 'Monthly',
                                'FuseSize' => null,
                                'HasDiscount' => false,
                                'Discount' => [
                                    'DiscountValue' => 0,
                                    'IsPercentage' => false,
                                    'DiscountType' => null,
                                    'NFirstKwh' => null,
                                    'NfirstMonths' => null,
                                    'UntilDate' => '0001-01-01T00:00:00',
                                ],
                                'OriginalPayment' => [
                                    'Price' => 4.0,
                                    'PaymentUnit' => 'EUR/month',
                                ],
                            ],
                        ],
                    ],
                    'ConsumptionControl' => false,
                    'ConsumptionLimitation' => [
                        'MinXKWhPerY' => null,
                        'MaxXKWhPerY' => null,
                    ],
                    'PreBilling' => false,
                    'AvailableForExistingUsers' => true,
                    'DeliveryResponsibilityProduct' => false,
                    'OrderLink' => 'https://energia.fi/order',
                    'ProductLink' => 'https://energia.fi/product',
                    'BillingFrequency' => ['Monthly' => true],
                    'TransparencyIndex' => ['Score' => 90],
                    'ExtraInformation' => [
                        'Default' => 'Extra info',
                        'FI' => 'Lisätietoja',
                        'EN' => 'Extra info',
                        'SV' => 'Extra information',
                    ],
                    'MicroProduction' => [
                        'Buys' => true,
                        'Details' => [
                            'Default' => 'Info',
                            'FI' => 'Tietoa',
                            'SV' => 'Info',
                            'EN' => 'Info',
                        ],
                    ],
                    'AvailabilityArea' => [
                        'IsNational' => true,
                        'PostalCodes' => [],
                    ],
                    'ElectricitySource' => [
                        'Renewable' => [
                            'Total' => 60.0,
                            'BioMass' => 10.0,
                            'Solar' => 5.0,
                            'Wind' => 25.0,
                            'General' => 0.0,
                            'Hydro' => 20.0,
                        ],
                        'Fossil' => [
                            'Total' => 20.0,
                            'Oil' => 5.0,
                            'Coal' => 5.0,
                            'NaturalGas' => 10.0,
                            'Peat' => 0.0,
                        ],
                        'Nuclear' => [
                            'Total' => 20.0,
                            'General' => 20.0,
                        ],
                    ],
                    'SpotFutures' => 4.00,
                ],
            ],
        ];
    }

    /**
     * Test command downloads company logos by default.
     */
    public function test_command_downloads_company_logos(): void
    {
        Storage::fake('public');

        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
            'storage.example.com/logos/energia.png' => Http::response($pngContent, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Verify company has local_logo_path set
        $company = Company::where('name', 'Energia Oy')->first();
        $this->assertNotNull($company->local_logo_path);
        $this->assertEquals('logos/energia-oy.png', $company->local_logo_path);

        // Verify logo file was stored
        Storage::disk('public')->assertExists('logos/energia-oy.png');
    }

    /**
     * Test command skips logo download with --skip-logos flag.
     */
    public function test_command_skips_logo_download_with_flag(): void
    {
        Storage::fake('public');

        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        // Verify company was created but without local_logo_path
        $company = Company::where('name', 'Energia Oy')->first();
        $this->assertNotNull($company);
        $this->assertNull($company->local_logo_path);

        // Logo URL should not have been fetched
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'storage.example.com');
        });
    }

    /**
     * Test command skips logo download if company already has local logo.
     */
    public function test_command_skips_logo_download_if_local_exists(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/energia-oy.png', 'existing logo');

        // Create existing company with local logo
        Company::create([
            'name' => 'Energia Oy',
            'name_slug' => 'energia-oy',
            'logo_url' => 'https://storage.example.com/logos/energia.png',
            'local_logo_path' => 'logos/energia-oy.png',
        ]);

        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Logo URL should not have been fetched
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'storage.example.com');
        });
    }

    /**
     * Test command continues if logo download fails.
     */
    public function test_command_continues_if_logo_download_fails(): void
    {
        Storage::fake('public');

        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse(),
                200
            ),
            'storage.example.com/logos/energia.png' => Http::response('Not Found', 404),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Company should still be created
        $company = Company::where('name', 'Energia Oy')->first();
        $this->assertNotNull($company);
        // local_logo_path should be null since download failed
        $this->assertNull($company->local_logo_path);
    }
}
