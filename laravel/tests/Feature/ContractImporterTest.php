<?php

namespace Tests\Feature;

use App\Models\ContractSourceSnapshot;
use App\Models\Postcode;
use App\Services\ContractImport\ContractImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_returns_typed_completeness_and_only_changed_snapshots_without_artisan(): void
    {
        Postcode::create([
            'postcode' => '00100',
            'postcode_name' => 'Helsinki',
            'municipality_code' => '091',
        ]);
        $importer = $this->app->make(ContractImporter::class);
        $payload = [$this->contractPayload()];

        $this->travelTo('2026-08-01 10:00:00');
        $first = $importer->import($payload, ['00100'], '2026-08-01', false);

        $this->assertFalse($first->complete);
        $this->assertSame(1, $first->contractCount);
        $this->assertSame(1, $first->activeContractCount);
        $this->assertSame(1, $first->priceComponentCount);
        $this->assertCount(1, $first->changedSnapshotIds);
        $this->assertSame($first->changedSnapshotIds, $first->observedSnapshotIds);
        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'direct-import-contract']);

        $this->travelTo('2026-08-02 10:00:00');
        $second = $importer->import($payload, ['00100'], '2026-08-02', true);

        $this->assertTrue($second->complete);
        $this->assertSame([], $second->changedSnapshotIds);
        $this->assertSame($first->changedSnapshotIds, $second->observedSnapshotIds);
        $this->assertSame(1, ContractSourceSnapshot::count());
        $this->assertSame(
            '2026-08-02 10:00:00',
            ContractSourceSnapshot::sole()->last_observed_at->toDateTimeString(),
        );
    }

    /** @return array<string, mixed> */
    private function contractPayload(): array
    {
        return [
            'Id' => 'direct-import-contract',
            'Name' => 'Direct Import Contract',
            'Company' => [
                'Name' => 'Direct Energy Oy',
                'CompanyUrl' => 'https://example.test',
                'LogoURL' => '',
            ],
            'Details' => [
                'ContractType' => 'FixedTerm',
                'FixedTimeRange' => 'Fixed12',
                'Metering' => 'General',
                'PricingModel' => 'FixedPrice',
                'TargetGroup' => 'Household',
                'Pricing' => [
                    'Name' => 'General',
                    'HasDiscount' => false,
                    'PriceComponents' => [[
                        'Id' => 'direct-general',
                        'PriceComponentType' => 'General',
                        'FuseSize' => null,
                        'HasDiscount' => false,
                        'Discount' => [],
                        'OriginalPayment' => [
                            'Price' => 7.5,
                            'PaymentUnit' => 'c/kWh',
                        ],
                    ]],
                ],
                'AvailabilityArea' => [
                    'IsNational' => false,
                    'PostalCodes' => ['00100'],
                    'Dsos' => ['Test DSO'],
                ],
                'ElectricitySource' => [],
                'SpotFutures' => 4.2,
            ],
        ];
    }
}
