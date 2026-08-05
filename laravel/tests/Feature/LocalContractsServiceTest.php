<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Models\PriceComponent;
use App\Services\LocalContractsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalContractsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_nearby_company_postcodes_are_loaded_in_bulk(): void
    {
        $municipality = Municipality::create([
            'code' => '091',
            'slug' => 'helsinki',
            'name' => 'Helsinki',
            'name_locative' => 'Helsingissä',
            'name_genitive' => 'Helsingin',
            'center_latitude' => 60.1699,
            'center_longitude' => 24.9384,
        ]);

        foreach (range(1, 5) as $index) {
            $postcode = sprintf('0010%d', $index);

            Postcode::create([
                'postcode' => $postcode,
                'postcode_fi_name' => "Helsinki {$index}",
                'postcode_fi_name_slug' => "helsinki-{$index}",
                'municipal_code' => '091',
                'municipal_name_fi' => 'Helsinki',
                'municipal_name_fi_slug' => 'helsinki',
                'latitude' => 60.1699 + ($index * 0.001),
                'longitude' => 24.9384 + ($index * 0.001),
            ]);

            $companyName = "Local Energia {$index}";
            Company::create([
                'name' => $companyName,
                'name_slug' => "local-energia-{$index}",
                'postal_code' => $postcode,
                'postal_name' => 'Helsinki',
            ]);

            $this->createContract("contract-{$index}", $companyName);
        }

        DB::enableQueryLog();

        app(LocalContractsService::class)->getLocalContracts($municipality, 5000);

        $directPostcodeQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "postcodes"')
                && str_contains($query, 'where "postcode" in'))
            ->count();

        $perCompanyPostcodeQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "postcodes"')
                && str_contains($query, 'where "postcodes"."postcode" ='))
            ->count();

        $bulkPriceComponentQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from (select')
                && str_contains($query, 'price_components')
                && str_contains($query, 'ROW_NUMBER()'))
            ->count();

        $perContractPriceComponentQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "price_components"')
                && str_contains($query, '"electricity_contract_id" ='))
            ->count();

        $this->assertSame(1, $directPostcodeQueries);
        $this->assertSame(0, $perCompanyPostcodeQueries);
        $this->assertSame(1, $bulkPriceComponentQueries);
        $this->assertSame(0, $perContractPriceComponentQueries);
    }

    public function test_local_and_regional_sections_require_the_same_exact_postcode_eligibility(): void
    {
        $municipality = Municipality::create([
            'code' => '091',
            'slug' => 'helsinki',
            'name' => 'Helsinki',
            'name_locative' => 'Helsingissä',
            'name_genitive' => 'Helsingin',
        ]);

        Postcode::create([
            'postcode' => '00100',
            'postcode_fi_name' => 'Helsinki',
            'postcode_fi_name_slug' => 'helsinki',
            'municipal_code' => '091',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);
        Postcode::create([
            'postcode' => '00101',
            'postcode_fi_name' => 'Helsinki',
            'postcode_fi_name_slug' => 'helsinki',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);
        Postcode::create([
            'postcode' => '33100',
            'postcode_fi_name' => 'Tampere',
            'postcode_fi_name_slug' => 'tampere',
            'municipal_code' => '837',
            'municipal_name_fi' => 'Tampere',
            'municipal_name_fi_slug' => 'tampere',
        ]);

        Company::create([
            'name' => 'Local Energia Oy',
            'name_slug' => 'local-energia-oy',
            'postal_name' => 'Helsinki',
        ]);
        Company::create([
            'name' => 'Regional Energia Oy',
            'name_slug' => 'regional-energia-oy',
            'postal_name' => 'Tampere',
        ]);

        $this->createContract('local-national', 'Local Energia Oy');
        $localRegional = $this->createContract('local-regional', 'Local Energia Oy', false);
        $otherRegional = $this->createContract('other-regional', 'Regional Energia Oy', false);
        $localRegional->availabilityPostcodes()->attach(['00100', '00101', '33100']);
        $otherRegional->availabilityPostcodes()->attach(['00100', '00101', '33100']);

        $service = app(LocalContractsService::class);
        $nationalOnly = $service->getLocalContracts($municipality, 5000);

        $this->assertSame(['local-national'], $nationalOnly['local_companies']->pluck('id')->all());
        $this->assertTrue($nationalOnly['regional_contracts']->isEmpty());

        $exact = $service->getLocalContracts($municipality, 5000, '00100');

        $this->assertEqualsCanonicalizing(
            ['local-national', 'local-regional'],
            $exact['local_companies']->pluck('id')->all(),
        );
        $this->assertSame(['other-regional'], $exact['regional_contracts']->pluck('id')->all());

        $nameFallback = $service->getLocalContracts($municipality, 5000, '00101');
        $this->assertSame(['other-regional'], $nameFallback['regional_contracts']->pluck('id')->all());

        $otherMunicipality = $service->getLocalContracts($municipality, 5000, '33100');
        $this->assertEqualsCanonicalizing(
            ['local-national', 'local-regional'],
            $otherMunicipality['local_companies']->pluck('id')->all(),
        );
        $this->assertTrue($otherMunicipality['regional_contracts']->isEmpty());

        $invalid = $service->getLocalContracts($municipality, 5000, '99999');
        $this->assertTrue($invalid['regional_contracts']->isEmpty());
    }

    public function test_bulk_latest_price_component_loader_prefers_latest_non_zero_component_per_type(): void
    {
        Company::create([
            'name' => 'Bulk Energia Oy',
            'name_slug' => 'bulk-energia-oy',
        ]);

        $this->createContract('bulk-contract', 'Bulk Energia Oy');

        PriceComponent::create([
            'id' => 'pc-general-bulk-contract-zero',
            'electricity_contract_id' => 'bulk-contract',
            'price_component_type' => 'General',
            'price_date' => now()->addDay()->format('Y-m-d'),
            'price' => 0.0,
            'payment_unit' => 'c/kWh',
        ]);

        $components = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds(['bulk-contract']);

        $general = collect($components['bulk-contract'])->firstWhere('price_component_type', 'General');

        $this->assertNotNull($general);
        $this->assertSame(6.0, (float) $general['price']);
    }

    private function createContract(
        string $id,
        string $companyName,
        ?bool $isNational = true,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $companyName,
            'name' => "Sopimus {$id}",
            'name_slug' => $id,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'target_group' => 'Household',
            'availability_is_national' => $isNational,
        ]);

        PriceComponent::create([
            'id' => "pc-general-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => "pc-monthly-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.0,
            'payment_unit' => 'EUR/month',
        ]);

        ActiveContract::create(['id' => $id]);

        return $contract;
    }
}
