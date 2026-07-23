<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchContractsCommandTest extends TestCase
{
    use RefreshDatabase;

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

        // Verify contract was created with api_id preserving the original API ID
        $this->assertDatabaseHas('electricity_contracts', [
            'api_id' => 'contract-12345',
            'name' => 'Sähkösopimus Perus',
            'company_name' => 'Energia Oy',
            'contract_type' => 'Fixed',
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
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response($firstResponse, 200),
        ]);
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

        $secondResponse = $this->getSampleApiResponse();
        $secondResponse[0]['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] = 6.5;
        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response($secondResponse, 200),
        ]);

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
            \App\Services\ContractReplacement\ContractReplacementLinker::class,
            new class extends \App\Services\ContractReplacement\ContractReplacementLinker
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

    /**
     * Test command handles API errors gracefully.
     */
    public function test_command_handles_api_errors(): void
    {
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                ['error' => 'Server Error'],
                500
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(1);
    }

    /**
     * Test command retries failed API requests.
     */
    public function test_command_retries_on_failure(): void
    {
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
