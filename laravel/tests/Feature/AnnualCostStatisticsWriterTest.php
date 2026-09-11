<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractStatistics\AnnualCostStatisticsWriter;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostResult;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AnnualCostStatisticsWriterTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'Writer Energy Oy', 'name_slug' => 'writer-energy-oy']);
    }

    public function test_writer_replaces_only_the_selected_date_and_method(): void
    {
        $contract = $this->contract('writer-one');
        $snapshot = $this->snapshot($contract)->fresh();
        $snapshotBefore = $snapshot->getRawOriginal();
        $unit = $this->daily('energy_price', ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION, null)->fresh();
        $legacy = $this->daily('annual_cost', AnnualCostMethodVersion::Legacy->value, 5000)->fresh();
        $otherDate = $this->daily('annual_cost', AnnualCostMethodVersion::AsOf->value, 5000, '2026-05-31')->fresh();
        $oldAsOf = $this->daily('annual_cost', AnnualCostMethodVersion::AsOf->value, 5000);
        ContractPriceAnnualCost::create([
            ...$this->annualAttributes($contract),
            'method_version' => AnnualCostMethodVersion::Legacy,
        ]);
        ContractPriceAnnualCost::create([
            ...$this->annualAttributes($contract),
            'method_version' => AnnualCostMethodVersion::AsOf,
            'annual_cost' => 999.0,
        ]);
        $this->assertSame(2, ContractPriceAnnualCost::count());

        $summary = app(AnnualCostStatisticsWriter::class)->write(self::DATE, [
            $this->annualResult($contract->id, 2000, 120.0),
            $this->annualResult($contract->id, 5000, 300.0),
            $this->annualResult($contract->id, 18000, 900.0),
        ]);

        $this->assertSame(3, $summary->persistedCount);
        $this->assertSame(3, $summary->aggregateCount);
        $this->assertSame($snapshotBefore, $snapshot->fresh()->getRawOriginal());
        $this->assertSame($unit->getRawOriginal(), $unit->fresh()->getRawOriginal());
        $this->assertSame($legacy->getRawOriginal(), $legacy->fresh()->getRawOriginal());
        $this->assertSame($otherDate->getRawOriginal(), $otherDate->fresh()->getRawOriginal());
        $this->assertNull($oldAsOf->fresh());
        $this->assertSame(
            500.0,
            ContractPriceAnnualCost::query()->where('method_version', AnnualCostMethodVersion::Legacy->value)->sole()->annual_cost,
        );
        $this->assertSame(
            300.0,
            ContractPriceAnnualCost::query()
                ->where('method_version', AnnualCostMethodVersion::AsOf->value)
                ->where('consumption_kwh', 5000)
                ->sole()
                ->annual_cost,
        );
    }

    public function test_percentiles_mixed_basis_and_compatibility_are_deterministic(): void
    {
        $results = [];
        foreach ([100.0, 200.0, 300.0, 400.0, 500.0] as $index => $cost) {
            $contract = $this->contract('writer-mixed-'.$index);
            foreach ([2000, 5000, 18000] as $consumption) {
                $results[] = $this->annualResult(
                    $contract->id,
                    $consumption,
                    $cost,
                    pricingBasis: $index === 4 ? ContractPriceBasis::CanonicalCalculation : ContractPriceBasis::ObservedSellerData,
                    calculationBasis: $index === 4 ? AnnualCostCalculationBasis::CanonicalOutcome : AnnualCostCalculationBasis::ObservedRelationalComponents,
                    estimateBasis: $index === 4 ? 'canonical_disclosed_phase_timeline' : 'exact_date_components_held_flat',
                    compatibilityKey: 'member-'.$index,
                );
            }
        }
        $unavailable = $this->contract('writer-unavailable');
        foreach ([2000, 5000, 18000] as $consumption) {
            $results[] = $this->annualResult($unavailable->id, $consumption, null, unavailableReason: 'missing_evidence');
        }

        $writer = app(AnnualCostStatisticsWriter::class);
        $preview = $writer->preview(self::DATE, array_reverse($results));
        $applied = $writer->write(self::DATE, $results);
        $statistic = ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)
            ->where('consumption_kwh', 5000)
            ->sole();

        $this->assertSame(18, $preview->evidenceResultCount);
        $this->assertSame(15, $preview->availableCount);
        $this->assertSame(3, $preview->unavailableCount);
        $this->assertSame(0, $preview->persistedCount);
        $this->assertSame(15, $applied->persistedCount);
        $this->assertSame('mixed_evidence', $statistic->pricing_basis);
        $this->assertSame('mixed', $statistic->calculation_basis);
        $this->assertSame('mixed', $statistic->estimate_basis);
        $this->assertSame(100.0, $statistic->min_value);
        $this->assertSame(180.0, $statistic->p20_value);
        $this->assertSame(300.0, $statistic->avg_value);
        $this->assertSame(300.0, $statistic->median_value);
        $this->assertSame(420.0, $statistic->p80_value);
        $this->assertSame(500.0, $statistic->max_value);
        $this->assertSame(5, $statistic->contract_count);
        $this->assertSame($preview->aggregates[0]->compatibilityKey, $statistic->compatibility_key);
        $this->assertSame(1, $statistic->basis_counts['unavailable_reasons']['missing_evidence']);
        $this->assertSame(3, $applied->basisCounts['unavailable_reasons']['missing_evidence']);
        $this->assertSame(4, $statistic->basis_counts['pricing_basis'][ContractPriceBasis::ObservedSellerData->value]);
        $this->assertSame(1, $statistic->basis_counts['pricing_basis'][ContractPriceBasis::CanonicalCalculation->value]);
    }

    public function test_empty_and_incomplete_apply_preserve_existing_rows(): void
    {
        $contract = $this->contract('writer-completeness');
        $existing = ContractPriceAnnualCost::create([
            ...$this->annualAttributes($contract),
            'method_version' => AnnualCostMethodVersion::AsOf,
        ])->fresh();
        $existingStatistic = $this->daily('annual_cost', AnnualCostMethodVersion::AsOf->value, 5000)->fresh();

        foreach ([[], [$this->annualResult($contract->id, 5000, 300.0)]] as $results) {
            try {
                app(AnnualCostStatisticsWriter::class)->write(self::DATE, $results);
                $this->fail('An empty or incomplete annual cost result set was applied.');
            } catch (InvalidArgumentException) {
                $this->assertSame($existing->getRawOriginal(), $existing->fresh()->getRawOriginal());
                $this->assertSame($existingStatistic->getRawOriginal(), $existingStatistic->fresh()->getRawOriginal());
            }
        }
    }

    public function test_validation_failure_leaves_the_complete_date_unchanged(): void
    {
        $contract = $this->contract('writer-valid');
        $existing = ContractPriceAnnualCost::create([
            ...$this->annualAttributes($contract),
            'method_version' => AnnualCostMethodVersion::AsOf,
        ])->fresh();
        $existingStatistic = $this->daily('annual_cost', AnnualCostMethodVersion::AsOf->value, 5000)->fresh();
        $invalid = $this->annualResult($contract->id, 2000, 100.0, compatibilityKey: '');

        try {
            app(AnnualCostStatisticsWriter::class)->write(self::DATE, [$invalid]);
            $this->fail('Invalid provenance was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($existing->getRawOriginal(), $existing->fresh()->getRawOriginal());
            $this->assertSame($existingStatistic->getRawOriginal(), $existingStatistic->fresh()->getRawOriginal());
        }
    }

    public function test_v2_is_idempotent_cleans_unavailable_rows_and_preserves_v1_exactly(): void
    {
        $contract = $this->contract('versioned');
        $v1 = ContractPriceAnnualCost::create([
            ...$this->annualAttributes($contract),
            'method_version' => AnnualCostMethodVersion::AsOf,
        ])->fresh();
        $aggregate = $this->daily('annual_cost', AnnualCostMethodVersion::AsOf->value, 5000)->fresh();
        $writer = app(AnnualCostStatisticsWriter::class);
        $results = array_map(fn ($kwh) => $this->annualResult($contract->id, $kwh, 400.0, methodVersion: AnnualCostMethodVersion::AsOfV2), [2000, 5000, 18000]);
        $preview = $writer->preview(self::DATE, $results, AnnualCostMethodVersion::AsOfV2);
        $this->assertSame(AnnualCostMethodVersion::AsOfV2, $preview->methodVersion);
        foreach (range(1, 2) as $run) {
            $summary = $writer->write(self::DATE, $results, AnnualCostMethodVersion::AsOfV2);
            $this->assertSame(AnnualCostMethodVersion::AsOfV2, $summary->methodVersion);
            $this->assertSame(4, ContractPriceAnnualCost::count());
            $this->assertSame(3, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOfV2)->count());
        }
        $unavailable = array_map(fn ($kwh) => $this->annualResult($contract->id, $kwh, null, unavailableReason: 'missing', methodVersion: AnnualCostMethodVersion::AsOfV2), [2000, 5000, 18000]);
        $writer->write(self::DATE, $unavailable, AnnualCostMethodVersion::AsOfV2);
        $this->assertSame(1, ContractPriceAnnualCost::count());
        $this->assertSame(0, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOfV2)->count());
        $this->assertSame($v1->getRawOriginal(), $v1->fresh()->getRawOriginal());
        $this->assertSame($aggregate->getRawOriginal(), $aggregate->fresh()->getRawOriginal());
    }

    public function test_v2_invalid_batches_and_legacy_target_do_not_write(): void
    {
        $contract = $this->contract('invalid-version');
        $writer = app(AnnualCostStatisticsWriter::class);
        $results = array_map(fn ($kwh) => $this->annualResult($contract->id, $kwh, 400.0, methodVersion: AnnualCostMethodVersion::AsOfV2), [2000, 5000, 18000]);
        $mixed = $results;
        $mixed[0] = $this->annualResult($contract->id, 2000, 200.0);
        $wrongDate = $results;
        $wrongDate[0] = new AsOfAnnualCostResult(...[...get_object_vars($results[0]), 'date' => CarbonImmutable::parse('2026-06-02', 'Europe/Helsinki')]);
        foreach ([
            [$results, AnnualCostMethodVersion::Legacy],
            [$results, AnnualCostMethodVersion::AsOf],
            [$mixed, AnnualCostMethodVersion::AsOfV2],
            [$wrongDate, AnnualCostMethodVersion::AsOfV2],
            [[...$results, $results[0]], AnnualCostMethodVersion::AsOfV2],
            [[$results[0]], AnnualCostMethodVersion::AsOfV2],
            [[], AnnualCostMethodVersion::AsOfV2],
        ] as [$batch, $method]) {
            try {
                $writer->write(self::DATE, $batch, $method);
                $this->fail('Invalid method or incomplete batch was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, ContractPriceAnnualCost::count());
                $this->assertSame(0, ContractPriceDailyStatistic::count());
            }
        }
    }

    public function test_v2_insert_failure_rolls_back_deleted_rows_and_aggregates(): void
    {
        $contract = $this->contract('rollback-v2');
        $existing = ContractPriceAnnualCost::create([
            ...$this->annualAttributes($contract),
            'method_version' => AnnualCostMethodVersion::AsOfV2,
        ])->fresh();
        $aggregate = $this->daily('annual_cost', AnnualCostMethodVersion::AsOfV2->value, 5000)->fresh();
        $results = array_map(fn ($kwh) => $this->annualResult('missing-foreign-contract', $kwh, 400.0, methodVersion: AnnualCostMethodVersion::AsOfV2), [2000, 5000, 18000]);
        try {
            app(AnnualCostStatisticsWriter::class)->write(self::DATE, $results, AnnualCostMethodVersion::AsOfV2);
            $this->fail('The invalid foreign key did not fail.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($existing->getRawOriginal(), $existing->fresh()->getRawOriginal());
            $this->assertSame($aggregate->getRawOriginal(), $aggregate->fresh()->getRawOriginal());
        }
    }

    private function contract(string $id): ElectricityContract
    {
        return ElectricityContract::factory()->forCompany('Writer Energy Oy')->create(['id' => $id]);
    }

    private function snapshot(ElectricityContract $contract): ContractPriceSnapshot
    {
        return ContractPriceSnapshot::create([
            'snapshot_date' => self::DATE,
            'contract_id' => $contract->id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'segment_key' => 'open_ended',
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'energy_price_cents_per_kwh' => 8.0,
            'annual_cost_5000_kwh' => 500.0,
        ]);
    }

    private function daily(string $metric, string $method, ?int $consumption, string $date = self::DATE): ContractPriceDailyStatistic
    {
        return ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => 'open_ended',
            'metric_key' => $metric,
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'method_version' => $method,
            'consumption_kwh' => $consumption,
            'min_value' => 500.0,
            'p20_value' => 500.0,
            'avg_value' => 500.0,
            'median_value' => 500.0,
            'p80_value' => 500.0,
            'max_value' => 500.0,
            'contract_count' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function annualAttributes(ElectricityContract $contract): array
    {
        return [
            'snapshot_date' => self::DATE,
            'contract_id' => $contract->id,
            'segment_key' => 'open_ended',
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'consumption_kwh' => 5000,
            'annual_cost' => 500.0,
            'calculation_basis' => AnnualCostCalculationBasis::ObservedRelationalComponents,
            'estimate_method' => 'none',
            'estimate_basis' => 'exact_date_components_held_flat',
            'compatibility_key' => 'existing-key',
            'provenance' => ['flags' => ['existing']],
        ];
    }

    private function annualResult(
        string $contractId,
        int $consumption,
        ?float $cost,
        ContractPriceBasis $pricingBasis = ContractPriceBasis::ObservedSellerData,
        AnnualCostCalculationBasis $calculationBasis = AnnualCostCalculationBasis::ObservedRelationalComponents,
        string $estimateBasis = 'exact_date_components_held_flat',
        string $compatibilityKey = 'member-key',
        ?string $unavailableReason = null,
        AnnualCostMethodVersion $methodVersion = AnnualCostMethodVersion::AsOf,
    ): AsOfAnnualCostResult {
        return new AsOfAnnualCostResult(
            contractId: $contractId,
            date: CarbonImmutable::parse(self::DATE, 'Europe/Helsinki'),
            segmentKey: 'open_ended',
            consumptionKwh: $consumption,
            totalCost: $cost,
            methodVersion: $methodVersion,
            pricingBasis: $pricingBasis,
            calculationBasis: $calculationBasis,
            estimateMethod: $cost === null ? null : 'none',
            estimateBasis: $cost === null ? null : $estimateBasis,
            compatibilityKey: $compatibilityKey,
            sourceEvidenceIds: [
                'price_snapshot_id' => 1,
                'price_component_ids' => ['component-'.$contractId],
                'observation_ids' => [],
                'source_snapshot_id' => null,
                'interpretation_id' => null,
                'historical_episode_id' => null,
                'historical_interpretation_id' => null,
                'historical_evidence_grade' => null,
            ],
            priceEpisodeStartedAt: null,
            provenanceFlags: ['exact_date_test_evidence'],
            unavailableReason: $unavailableReason,
        );
    }
}
