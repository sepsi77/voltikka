<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `contracts:repair-price-component-collisions` rebuilds the rows that a
 * null-UUID collision poisoned, from the immutable source snapshots.
 *
 * The upstream API can send two components sharing the null UUID, the type and
 * the fuse size, one carrying the real price and one carrying zero. Both
 * collapse to `md5("{contract}:{type}:{fuse}")`, and before the writer learned
 * to prefer the positive candidate the zero could win the day's upsert. The
 * contract's price-development chart then drew a crash to 0,00 c/kWh while the
 * version timeline beside it showed the real price. Ingestion is fixed; this
 * command repairs what was written before that.
 */
class RepairPriceComponentCollisionsTest extends TestCase
{
    use RefreshDatabase;

    private const NULL_UUID = '00000000-0000-0000-0000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'name' => 'Testi Energia Oy',
            'name_slug' => 'testi-energia-oy',
            'company_url' => 'https://testienergia.fi',
        ]);
    }

    private function contract(string $id): ElectricityContract
    {
        return ElectricityContract::create([
            'id' => $id,
            'api_id' => $id.'-api',
            'company_name' => 'Testi Energia Oy',
            'name' => 'Testisopimus',
            'name_slug' => $id,
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);
    }

    /** The collided key the importer would generate for a null-UUID component. */
    private function storageKey(string $contractId, string $type = 'General', string $fuse = 'Any'): string
    {
        return md5("{$contractId}:{$type}:{$fuse}");
    }

    /**
     * A payload holding the real price beside the zero, in that collision order.
     *
     * @param  list<float>  $prices
     */
    private function snapshot(string $contractId, array $prices, string $from, string $until): ContractSourceSnapshot
    {
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $contractId,
            'source_fingerprint' => hash('sha256', $contractId.$from),
            'source_payload' => [
                'Id' => $contractId.'-api',
                'Details' => ['Pricing' => [
                    'ElectricitySupplyProductId' => $contractId.'-api',
                    'PriceComponents' => array_map(fn (float $p) => [
                        'Id' => self::NULL_UUID,
                        'PriceComponentType' => 'General',
                        'FuseSize' => 'Any',
                        'HasDiscount' => false,
                        'OriginalPayment' => ['Price' => $p, 'PaymentUnit' => 'CentPerKiwattHour'],
                    ], $prices),
                ]],
            ],
            'first_observed_at' => $from.' 06:00:00',
            'last_observed_at' => $until.' 06:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contractId,
            'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => $from.' 06:00:00',
            'last_observed_at' => $until.' 06:00:00',
        ]);
        ElectricityContract::whereKey($contractId)->update([
            'current_source_observation_id' => $observation->id,
        ]);

        return $snapshot;
    }

    private function priceRow(string $contractId, string $date, float $price): void
    {
        PriceComponent::create([
            'id' => $this->storageKey($contractId),
            'electricity_contract_id' => $contractId,
            'price_component_type' => 'General',
            'fuse_size' => 'Any',
            'price_date' => $date,
            'price' => $price,
            'payment_unit' => 'CentPerKiwattHour',
        ]);
    }

    private function storedPrice(string $contractId, string $date): float
    {
        return (float) DB::table('price_components')
            ->where('electricity_contract_id', $contractId)
            ->whereDate('price_date', $date)
            ->value('price');
    }

    public function test_a_collided_zero_is_rebuilt_from_the_source_snapshot(): void
    {
        $id = 'collision-contract';
        $this->contract($id);
        $this->snapshot($id, [7.88, 0.0], '2026-07-20', '2026-07-24');
        $this->priceRow($id, '2026-07-22', 7.88);
        $this->priceRow($id, '2026-07-23', 0.0);
        $this->priceRow($id, '2026-07-24', 0.0);

        $this->artisan('contracts:repair-price-component-collisions', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(7.88, $this->storedPrice($id, '2026-07-23'));
        $this->assertSame(7.88, $this->storedPrice($id, '2026-07-24'));
        $this->assertSame(7.88, $this->storedPrice($id, '2026-07-22'), 'An untouched day must not move.');
    }

    public function test_recurrent_a_b_a_episodes_select_the_exact_payload_for_each_day(): void
    {
        $id = 'recurrent-collision-contract';
        $this->contract($id);
        $snapshotA = $this->snapshot($id, [7.0, 0.0], '2026-07-21', '2026-07-21');
        $this->snapshot($id, [8.0, 0.0], '2026-07-22', '2026-07-22');
        $recurrentA = ContractSourceObservation::create([
            'contract_id' => $id,
            'source_snapshot_id' => $snapshotA->id,
            'first_observed_at' => '2026-07-23 06:00:00',
            'last_observed_at' => '2026-07-23 06:00:00',
        ]);
        ElectricityContract::whereKey($id)->update(['current_source_observation_id' => $recurrentA->id]);
        $this->priceRow($id, '2026-07-20', 7.0);
        $this->priceRow($id, '2026-07-21', 0.0);
        $this->priceRow($id, '2026-07-22', 0.0);
        $this->priceRow($id, '2026-07-23', 0.0);

        $this->artisan('contracts:repair-price-component-collisions', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(7.0, $this->storedPrice($id, '2026-07-21'));
        $this->assertSame(8.0, $this->storedPrice($id, '2026-07-22'));
        $this->assertSame(7.0, $this->storedPrice($id, '2026-07-23'));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $id = 'dryrun-contract';
        $this->contract($id);
        $this->snapshot($id, [7.88, 0.0], '2026-07-20', '2026-07-24');
        $this->priceRow($id, '2026-07-22', 7.88);
        $this->priceRow($id, '2026-07-23', 0.0);

        $this->artisan('contracts:repair-price-component-collisions')->assertSuccessful();

        $this->assertSame(0.0, $this->storedPrice($id, '2026-07-23'), 'Dry run must not write.');
    }

    /**
     * Some contracts genuinely charge nothing per kWh. Their key is zero on every
     * observed date, so it is never a candidate and the command must leave it be.
     */
    public function test_a_contract_priced_at_zero_throughout_is_left_alone(): void
    {
        $id = 'genuinely-free-contract';
        $this->contract($id);
        $this->snapshot($id, [0.0], '2026-07-20', '2026-07-24');
        $this->priceRow($id, '2026-07-22', 0.0);
        $this->priceRow($id, '2026-07-23', 0.0);

        $this->artisan('contracts:repair-price-component-collisions', ['--apply' => true])
            ->expectsOutputToContain('No collided zero-price rows found.')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->storedPrice($id, '2026-07-23'));
    }

    /**
     * Evidence, never inference: with no positive candidate in the payload the
     * row is reported and left exactly as it is, not filled in from a neighbour.
     */
    public function test_a_row_whose_snapshot_holds_no_positive_price_is_skipped(): void
    {
        $id = 'no-evidence-contract';
        $this->contract($id);
        $this->snapshot($id, [7.88], '2026-07-01', '2026-07-10');
        $this->snapshot($id, [0.0], '2026-07-20', '2026-07-24');
        $this->priceRow($id, '2026-07-05', 7.88);
        $this->priceRow($id, '2026-07-23', 0.0);

        $this->artisan('contracts:repair-price-component-collisions', ['--apply' => true])
            ->expectsOutputToContain('holds no positive price either')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->storedPrice($id, '2026-07-23'));
    }

    public function test_a_row_with_no_covering_snapshot_is_skipped(): void
    {
        $id = 'no-snapshot-contract';
        $this->contract($id);
        $this->snapshot($id, [7.88, 0.0], '2026-07-01', '2026-07-10');
        $this->priceRow($id, '2026-07-05', 7.88);
        $this->priceRow($id, '2026-07-23', 0.0);

        $this->artisan('contracts:repair-price-component-collisions', ['--apply' => true])
            ->expectsOutputToContain('no covering source episode')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->storedPrice($id, '2026-07-23'));
    }
}
