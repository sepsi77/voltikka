<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use App\Services\OpenAiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class GenerateDescriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a company for testing
        Company::create([
            'name' => 'Test Energy Oy',
            'name_slug' => 'test-energy-oy',
            'company_url' => 'https://test-energy.fi',
        ]);
    }

    /**
     * Test command generates descriptions for contracts without short_description.
     */
    public function test_command_generates_descriptions_for_contracts_without_description(): void
    {
        // Create an active contract without description
        $contract = ElectricityContract::create([
            'id' => 'test-contract-001',
            'company_name' => 'Test Energy Oy',
            'name' => 'Test Contract 12kk',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-001',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 10.5,
            'payment_unit' => 'snt/kWh',
        ]);

        PriceComponent::create([
            'id' => 'price-002',
            'price_date' => Carbon::today(),
            'price_component_type' => 'Monthly',
            'electricity_contract_id' => $contract->id,
            'price' => 4.99,
            'payment_unit' => 'EUR/kk',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Test Energy Oy:n Test Contract 12kk on määräaikainen yleissähkösopimus, jossa sähkön hinta on 10,5 snt/kWh ja kuukausimaksu 4,99 €/kk. Sähkö tuotetaan 100% tuulivoimalla.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        // Verify description was saved
        $contract->refresh();
        $this->assertNotNull($contract->short_description);
        $this->assertStringContainsString('Test Energy Oy', $contract->short_description);
    }

    /**
     * Test command skips contracts that already have descriptions.
     */
    public function test_command_skips_contracts_with_existing_description(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'test-contract-002',
            'company_name' => 'Test Energy Oy',
            'name' => 'Existing Description Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => 'Existing description that should not change.',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-003',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 10.5,
            'payment_unit' => 'snt/kWh',
        ]);

        // OpenAI should not be called
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'This should not appear.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        $contract->refresh();
        $this->assertEquals('Existing description that should not change.', $contract->short_description);

        // Verify no API calls were made
        Http::assertNothingSent();
    }

    /**
     * Test command only processes active contracts.
     */
    public function test_command_only_processes_active_contracts(): void
    {
        // Create inactive contract without description
        $inactiveContract = ElectricityContract::create([
            'id' => 'inactive-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'Inactive Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => null,
            'availability_is_national' => true,
        ]);
        // Note: NOT adding to ActiveContract

        ElectricitySource::create([
            'contract_id' => $inactiveContract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-004',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $inactiveContract->id,
            'price' => 10.5,
            'payment_unit' => 'snt/kWh',
        ]);

        // OpenAI should not be called
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'This should not appear.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        $inactiveContract->refresh();
        $this->assertNull($inactiveContract->short_description);

        // Verify no API calls were made
        Http::assertNothingSent();
    }

    /**
     * Test command uses latest price date for pricing information.
     */
    public function test_command_uses_latest_price_date(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'test-contract-003',
            'company_name' => 'Test Energy Oy',
            'name' => 'Latest Price Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        // Old price (should be ignored)
        PriceComponent::create([
            'id' => 'price-old',
            'price_date' => Carbon::today()->subDays(7),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 5.0, // Old price
            'payment_unit' => 'snt/kWh',
        ]);

        // Latest price (should be used)
        PriceComponent::create([
            'id' => 'price-new',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 12.0, // Latest price
            'payment_unit' => 'snt/kWh',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated description with latest price.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        // Verify the API was called with latest price
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $prompt = $body['messages'][0]['content'] ?? '';
            return str_contains($prompt, '12') && !str_contains($prompt, 'price": 5');
        });
    }

    /**
     * Test command handles multiple contracts.
     */
    public function test_command_handles_multiple_contracts(): void
    {
        // Create multiple contracts without descriptions
        for ($i = 1; $i <= 3; $i++) {
            $contract = ElectricityContract::create([
                'id' => "multi-contract-{$i}",
                'company_name' => 'Test Energy Oy',
                'name' => "Test Contract {$i}",
                'contract_type' => 'Fixed',
                'metering' => 'General',
                'short_description' => null,
                'availability_is_national' => true,
            ]);

            ActiveContract::create(['id' => $contract->id]);

            ElectricitySource::create([
                'contract_id' => $contract->id,
                'renewable_wind' => 100.0,
            ]);

            PriceComponent::create([
                'id' => "price-multi-{$i}",
                'price_date' => Carbon::today(),
                'price_component_type' => 'General',
                'electricity_contract_id' => $contract->id,
                'price' => 10.0 + $i,
                'payment_unit' => 'snt/kWh',
            ]);
        }

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated description.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        // Verify all contracts got descriptions
        for ($i = 1; $i <= 3; $i++) {
            $contract = ElectricityContract::find("multi-contract-{$i}");
            $this->assertNotNull($contract->short_description);
        }
    }

    /**
     * Test command handles API errors gracefully.
     */
    public function test_command_handles_api_errors_gracefully(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'error-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'Error Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-error',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 10.0,
            'payment_unit' => 'snt/kWh',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'API Error'],
            ], 500),
        ]);

        // Command should continue despite error (return success but log the error)
        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        // Description should remain null
        $contract->refresh();
        $this->assertNull($contract->short_description);
    }

    /**
     * Test command outputs progress information.
     */
    public function test_command_outputs_progress(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'progress-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'Progress Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-progress',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 10.0,
            'payment_unit' => 'snt/kWh',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated description.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->expectsOutputToContain('Generating contract descriptions')
            ->assertExitCode(0);
    }

    /**
     * Test command handles contracts without electricity source.
     */
    public function test_command_handles_contracts_without_electricity_source(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'no-source-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'No Source Contract',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        // Note: NOT creating ElectricitySource

        PriceComponent::create([
            'id' => 'price-no-source',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 10.0,
            'payment_unit' => 'snt/kWh',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated description without electricity source.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        $contract->refresh();
        $this->assertNotNull($contract->short_description);
    }

    /**
     * Test command handles spot contracts correctly.
     */
    public function test_command_handles_spot_contracts(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'spot-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'Pörssisähkö',
            'contract_type' => 'Spot',
            'metering' => 'General',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        // For spot contracts, the General price is the margin
        PriceComponent::create([
            'id' => 'price-spot-margin',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 0.49,
            'payment_unit' => 'snt/kWh',
        ]);

        PriceComponent::create([
            'id' => 'price-spot-monthly',
            'price_date' => Carbon::today(),
            'price_component_type' => 'Monthly',
            'electricity_contract_id' => $contract->id,
            'price' => 3.99,
            'payment_unit' => 'EUR/kk',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Test Energy Oy:n Pörssisähkö on pörssisähkösopimus, jonka hinta muodostuu Nord Pool Spot -markkinoiden sähkön spot-hinnasta, 0,49 sentin kWh-marginaalista ja arvonlisäverosta.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        $contract->refresh();
        $this->assertNotNull($contract->short_description);
        $this->assertStringContainsString('pörssisähkö', strtolower($contract->short_description));
    }

    /**
     * Test command handles contracts with consumption limit.
     */
    public function test_command_includes_consumption_limit(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'limited-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'KSS IISI M',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'short_description' => null,
            'consumption_limitation_max_x_kwh_per_y' => 5000,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-limited',
            'price_date' => Carbon::today(),
            'price_component_type' => 'Monthly',
            'electricity_contract_id' => $contract->id,
            'price' => 69.99,
            'payment_unit' => 'EUR/kk',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Test Energy Oy:n KSS IISI M on toistaiseksi voimassa oleva, kiinteähintainen sähkösopimus hintaan 69,99 €/kk. Sopimuksessa on vuosittainen kulutusraja 5000 kWh.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        // Verify the prompt includes the consumption limit
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $prompt = $body['messages'][0]['content'] ?? '';
            return str_contains($prompt, '5000');
        });
    }

    /**
     * Test command handles no price components in database.
     */
    public function test_command_handles_no_price_components(): void
    {
        // No contracts or price components created
        Http::fake([
            'api.openai.com/*' => Http::response([], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->expectsOutputToContain('No price components')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * Test command handles no contracts needing descriptions.
     */
    public function test_command_handles_no_contracts_without_descriptions(): void
    {
        // Create a contract WITH a description and a price component
        $contract = ElectricityContract::create([
            'id' => 'has-description',
            'company_name' => 'Test Energy Oy',
            'name' => 'Already has description',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'short_description' => 'This contract already has a description.',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'price-has-desc',
            'price_date' => Carbon::today(),
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'price' => 10.0,
            'payment_unit' => 'snt/kWh',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->expectsOutputToContain('No contracts need descriptions')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * Test command handles time-based metering contracts.
     */
    public function test_command_handles_time_metering_contracts(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'time-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'Aikasähkö',
            'contract_type' => 'OpenEnded',
            'metering' => 'Time',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-day',
            'price_date' => Carbon::today(),
            'price_component_type' => 'DayTime',
            'electricity_contract_id' => $contract->id,
            'price' => 12.0,
            'payment_unit' => 'snt/kWh',
        ]);

        PriceComponent::create([
            'id' => 'price-night',
            'price_date' => Carbon::today(),
            'price_component_type' => 'NightTime',
            'electricity_contract_id' => $contract->id,
            'price' => 8.0,
            'payment_unit' => 'snt/kWh',
        ]);

        PriceComponent::create([
            'id' => 'price-monthly-time',
            'price_date' => Carbon::today(),
            'price_component_type' => 'Monthly',
            'electricity_contract_id' => $contract->id,
            'price' => 4.50,
            'payment_unit' => 'EUR/kk',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Test Energy Oy:n Aikasähkö on toistaiseksi voimassaoleva aikasähkösopimus, jossa yön aikainen sähkön hinta on 8 snt/kWh ja päivän aikainen 12 snt/kWh, sekä kuukausihinta 4,50 €/kk.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        $contract->refresh();
        $this->assertNotNull($contract->short_description);
    }

    /**
     * Test command handles seasonal metering contracts.
     */
    public function test_command_handles_seasonal_metering_contracts(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'seasonal-contract',
            'company_name' => 'Test Energy Oy',
            'name' => 'Kausisähkö',
            'contract_type' => 'OpenEnded',
            'metering' => 'Seasonal',
            'short_description' => null,
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        ElectricitySource::create([
            'contract_id' => $contract->id,
            'renewable_wind' => 100.0,
        ]);

        PriceComponent::create([
            'id' => 'price-winter',
            'price_date' => Carbon::today(),
            'price_component_type' => 'SeasonalWinter',
            'electricity_contract_id' => $contract->id,
            'price' => 17.35,
            'payment_unit' => 'snt/kWh',
        ]);

        PriceComponent::create([
            'id' => 'price-other',
            'price_date' => Carbon::today(),
            'price_component_type' => 'SeasonalOther',
            'electricity_contract_id' => $contract->id,
            'price' => 14.29,
            'payment_unit' => 'snt/kWh',
        ]);

        PriceComponent::create([
            'id' => 'price-monthly-seasonal',
            'price_date' => Carbon::today(),
            'price_component_type' => 'Monthly',
            'electricity_contract_id' => $contract->id,
            'price' => 3.50,
            'payment_unit' => 'EUR/kk',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Test Energy Oy:n Kausisähkö on toistaiseksi voimassa oleva kausisähkösopimus, jossa sähkön hinta on 17,35 snt/kWh talvipäivisin ja 14,29 snt/kWh muina aikoina, sekä kuukausimaksu 3,50 €/kk.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('descriptions:generate')
            ->assertExitCode(0);

        $contract->refresh();
        $this->assertNotNull($contract->short_description);
    }
}
