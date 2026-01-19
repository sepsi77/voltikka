<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Postcode;
use App\Models\PriceComponent;
use App\Models\SpotFutures;
use App\Services\AzureConsumerApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        // Verify contract was created
        $this->assertDatabaseHas('electricity_contracts', [
            'id' => 'contract-12345',
            'name' => 'Sähkösopimus Perus',
            'company_name' => 'Energia Oy',
            'contract_type' => 'Fixed',
            'metering' => 'General',
        ]);

        // Verify electricity source was created
        $this->assertDatabaseHas('electricity_sources', [
            'contract_id' => 'contract-12345',
            'renewable_total' => 100.0,
            'renewable_wind' => 50.0,
            'renewable_hydro' => 50.0,
        ]);

        // Verify price components were created
        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => 'contract-12345',
            'price_component_type' => 'General',
            'price' => 5.5,
        ]);

        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => 'contract-12345',
            'price_component_type' => 'Monthly',
            'price' => 2.95,
        ]);

        // Verify active contract was created
        $this->assertDatabaseHas('active_contracts', [
            'id' => 'contract-12345',
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

        // Should have both contracts
        $this->assertDatabaseHas('electricity_contracts', ['id' => 'contract-12345']);
        $this->assertDatabaseHas('electricity_contracts', ['id' => 'contract-67890']);
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

        // Should have fetched from at least one postcode
        Http::assertSentCount(30); // Default is 30 postcodes from trigger-contract-fetch
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
            'id' => 'contract-12345',
            'name' => 'Old Name',
            'company_name' => 'Energia Oy',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'pricing_has_discounts' => false,
            'availability_is_national' => true,
        ]);

        // API returns contract with updated discount status
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                $this->getSampleApiResponse('contract-12345', 'Sähkösopimus Perus', true),
                200
            ),
        ]);

        $this->artisan('contracts:fetch', ['--postcodes' => '00100'])
            ->assertExitCode(0);

        // Contract should be updated
        $contract = ElectricityContract::find('contract-12345');
        $this->assertTrue($contract->pricing_has_discounts);
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
        ElectricityContract::create([
            'id' => 'old-contract',
            'name' => 'Old Contract',
            'company_name' => 'Old Company',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        ActiveContract::create(['id' => 'old-contract']);

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
        $this->assertDatabaseMissing('active_contracts', ['id' => 'old-contract']);
        $this->assertDatabaseHas('active_contracts', ['id' => 'contract-12345']);
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

        // Verify postcode relationship
        $this->assertDatabaseHas('contract_postcode', [
            'contract_id' => 'contract-12345',
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
        $this->assertDatabaseHas('electricity_contracts', ['id' => 'contract-12345']);
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

        // Verify discount data was saved
        $priceComponent = PriceComponent::where('electricity_contract_id', 'discount-contract')
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

        // Verify day and night price components
        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => 'time-contract',
            'price_component_type' => 'DayTime',
            'price' => 6.5,
        ]);

        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => 'time-contract',
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

        // Verify seasonal price components
        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => 'seasonal-contract',
            'price_component_type' => 'SeasonalWinter',
            'price' => 8.0,
        ]);

        $this->assertDatabaseHas('price_components', [
            'electricity_contract_id' => 'seasonal-contract',
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
                    'Pricing' => [
                        'Name' => 'General Price',
                        'HasDiscount' => $hasDiscount,
                        'ElectricitySupplyProductId' => $contractId,
                        'PriceComponents' => [
                            [
                                'Id' => 'pc-general-' . $contractId,
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
                                'Id' => 'pc-monthly-' . $contractId,
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
        $contract1Components = PriceComponent::where('electricity_contract_id', 'contract-null-1')->count();
        $contract2Components = PriceComponent::where('electricity_contract_id', 'contract-null-2')->count();

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
        $componentCount = PriceComponent::where('electricity_contract_id', 'contract-multi-null')->count();
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
                                'PriceComponentType' => 'SeasonalWinter',
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
}
