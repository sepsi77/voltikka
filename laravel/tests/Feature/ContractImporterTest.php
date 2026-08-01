<?php

namespace Tests\Feature;

use App\Enums\MeteringType;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Services\ContractImport\ContractImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_returns_typed_completeness_and_observation_ids_without_artisan(): void
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
        $this->assertCount(1, $first->changedObservationIds);
        $this->assertSame($first->changedObservationIds, $first->observedObservationIds);
        $this->assertDatabaseHas('electricity_contracts', ['api_id' => 'direct-import-contract']);

        $this->travelTo('2026-08-02 10:00:00');
        $second = $importer->import($payload, ['00100'], '2026-08-02', true);

        $this->assertTrue($second->complete);
        $this->assertSame([], $second->changedObservationIds);
        $this->assertSame($first->changedObservationIds, $second->observedObservationIds);
        $this->assertSame(1, ContractSourceSnapshot::count());
        $this->assertSame(1, ContractSourceObservation::count());
        $this->assertSame(
            '2026-08-02 10:00:00',
            ContractSourceSnapshot::sole()->last_observed_at->toDateTimeString(),
        );
    }

    public function test_import_normalizes_classification_aliases_and_preserves_exact_source_values(): void
    {
        $importer = $this->app->make(ContractImporter::class);
        $payload = $this->contractPayload();
        $payload['Details']['PricingModel'] = '  fIxEd  ';
        $payload['Details']['ContractType'] = "\tFIXED\n";
        $payload['Details']['TargetGroup'] = ' Consumer ';
        $payload['Details']['Metering'] = ' Seasonal ';

        $importer->import([$payload], [], '2026-08-01', true);

        $contract = ElectricityContract::where('api_id', 'direct-import-contract')->firstOrFail();
        $snapshot = ContractSourceSnapshot::sole();
        $this->assertSame('FixedPrice', $contract->pricing_model);
        $this->assertSame('FixedTerm', $contract->contract_type);
        $this->assertSame('Household', $contract->target_group);
        $this->assertSame('Seasonal', $contract->metering);
        $this->assertSame(MeteringType::Season, $contract->meteringType());
        $this->assertSame('  fIxEd  ', $snapshot->source_payload['Details']['PricingModel']);
        $this->assertSame("\tFIXED\n", $snapshot->source_payload['Details']['ContractType']);
        $this->assertSame(' Consumer ', $snapshot->source_payload['Details']['TargetGroup']);
        $this->assertSame(' Seasonal ', $snapshot->source_payload['Details']['Metering']);
    }

    public function test_import_stores_unknown_classifications_and_preserves_unsupported_source_values(): void
    {
        $importer = $this->app->make(ContractImporter::class);
        $payload = $this->contractPayload();
        $payload['Details']['PricingModel'] = ' Future Model ';
        $payload['Details']['ContractType'] = ['malformed'];
        $payload['Details']['TargetGroup'] = null;

        $importer->import([$payload], [], '2026-08-01', true);

        $contract = ElectricityContract::where('api_id', 'direct-import-contract')->firstOrFail();
        $snapshot = ContractSourceSnapshot::sole();
        $this->assertSame('Unknown', $contract->pricing_model);
        $this->assertSame('Unknown', $contract->contract_type);
        $this->assertSame('Unknown', $contract->target_group);
        $this->assertSame(' Future Model ', $snapshot->source_payload['Details']['PricingModel']);
        $this->assertSame(['malformed'], $snapshot->source_payload['Details']['ContractType']);
        $this->assertNull($snapshot->source_payload['Details']['TargetGroup']);
    }

    public function test_recurrent_payload_creates_three_episodes_and_extends_only_the_pointed_episode(): void
    {
        Postcode::create([
            'postcode' => '00100',
            'postcode_name' => 'Helsinki',
            'municipality_code' => '091',
        ]);
        $importer = $this->app->make(ContractImporter::class);
        $payloadA = $this->contractPayload();
        $payloadB = $payloadA;
        $payloadB['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] = 8.5;

        foreach ([
            ['2026-08-01 10:00:00', $payloadA],
            ['2026-08-02 10:00:00', $payloadA],
            ['2026-08-03 10:00:00', $payloadB],
            ['2026-08-04 10:00:00', $payloadA],
            ['2026-08-05 10:00:00', $payloadA],
        ] as [$observedAt, $payload]) {
            $this->travelTo($observedAt);
            $importer->import([$payload], ['00100'], substr($observedAt, 0, 10), true);
        }

        $contract = ElectricityContract::where('api_id', 'direct-import-contract')->firstOrFail();
        $episodes = ContractSourceObservation::query()->orderBy('id')->get();

        $this->assertSame(2, ContractSourceSnapshot::count());
        $this->assertCount(3, $episodes);
        $this->assertSame([
            '2026-08-02 10:00:00',
            '2026-08-03 10:00:00',
            '2026-08-05 10:00:00',
        ], $episodes->pluck('last_observed_at')->map->toDateTimeString()->all());
        $this->assertSame($episodes[0]->source_snapshot_id, $episodes[2]->source_snapshot_id);
        $this->assertNotSame($episodes[0]->source_snapshot_id, $episodes[1]->source_snapshot_id);
        $this->assertSame($episodes[2]->id, $contract->current_source_observation_id);
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
