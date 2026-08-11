<?php

namespace App\Services\ContractStatistics;

use App\Enums\ContractType;
use App\Enums\MeteringType;
use App\Enums\PricingModel;
use App\Enums\TargetGroup;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use App\Services\ContractStatistics\DTO\SellerSetEnergyPriceIndexDateSummary;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SellerSetEnergyPriceIndexService
{
    public const METRIC_KEY = 'seller_set_energy_price_index_v1';

    public const SERIES_START_DATE = '2026-01-21';

    public const BASKET_DATE = '2026-08-11';

    public const COMPATIBILITY_KEY = 'seller_set_energy_price_index_v1:2026-08-11:company_median:fixed_weights';

    public const CALCULATION_BASIS = 'canonical_direct_general_company_median_v1';

    public const ESTIMATE_BASIS = 'fixed_supplier_family_weights_2026_08_11';

    public const MAX_ENERGY_PRICE_CENTS_PER_KWH = 50.0;

    public const MIN_FAMILY_SUPPLIERS = 3;

    public const SEGMENT_OVERALL = 'seller_set_overall';

    public const SEGMENT_FIXED_TERM = 'seller_set_fixed_term';

    public const SEGMENT_OPEN_ENDED = 'seller_set_open_ended';

    public const SEGMENT_MARKET_RESET = 'seller_set_market_reset';

    public const SEGMENT_HYBRID_BASE = 'seller_set_hybrid_base';

    /** @var array<string, float> */
    public const FAMILY_WEIGHTS = [
        'fixed_term' => 0.500000,
        'open_ended' => 0.295455,
        'market_reset' => 0.204545,
    ];

    /** @var array<string, string> */
    public const FAMILY_SEGMENTS = [
        'fixed_term' => self::SEGMENT_FIXED_TERM,
        'open_ended' => self::SEGMENT_OPEN_ENDED,
        'market_reset' => self::SEGMENT_MARKET_RESET,
    ];

    public function __construct(
        private readonly AsOfAnnualCostEvidenceResolver $evidenceResolver,
        private readonly CanonicalContractPriceCalculator $canonicalCalculator,
    ) {}

    /**
     * Replace this metric's rows for one current canonical collection date.
     *
     * @return int Number of rows written.
     */
    public function writeForDate(CarbonInterface|string $date): int
    {
        $dateString = $this->dateString($date);
        if ($dateString < self::SERIES_START_DATE) {
            $this->replaceRows($dateString, []);

            return 0;
        }

        $candidates = [];
        foreach ($this->eligibleSnapshots($dateString) as $snapshot) {
            $family = $this->familyForCurrentSnapshot($snapshot);
            $value = $snapshot->energy_price_cents_per_kwh;
            if ($family === null || ! $this->validRate($value)) {
                continue;
            }

            $candidates[] = [
                'family' => $family,
                'company_name' => (string) $snapshot->company_name,
                'rate' => (float) $value,
                'provenance' => 'current_canonical_collection',
            ];
        }

        $rows = $this->aggregateRows($dateString, $candidates, 'current_canonical_collection');
        $this->replaceRows($dateString, $rows);

        return count($rows);
    }

    /**
     * Resolve and aggregate one historical date without changing stored index rows.
     */
    public function previewHistoricalForDate(CarbonInterface|string $date): SellerSetEnergyPriceIndexDateSummary
    {
        $dateString = $this->dateString($date);
        $evidence = $this->evidenceResolver->resolveDate($dateString);
        $proofIds = ContractPriceAnnualCost::query()
            ->whereDate('snapshot_date', $dateString)
            ->where('consumption_kwh', 5000)
            ->where('method_version', AnnualCostMethodVersion::AsOf->value)
            ->where('calculation_basis', AnnualCostCalculationBasis::CanonicalOutcome->value)
            ->whereNotNull('annual_cost')
            ->pluck('contract_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();
        $eligibleIds = ElectricityContract::query()
            ->whereIn('id', array_keys($evidence))
            ->where('availability_is_national', true)
            ->where(function ($query): void {
                $query->whereIn('target_group', [TargetGroup::Household->value, TargetGroup::Both->value])
                    ->orWhereNull('target_group');
            })
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $candidates = [];
        $exclusions = [];
        $eligibleCount = 0;

        foreach ($evidence as $item) {
            if (! isset($eligibleLookup[$item->contractId])) {
                $this->increment($exclusions, 'not_currently_proven_national_household');

                continue;
            }
            $eligibleCount++;
            if ($item->canonicalData === null) {
                $this->increment($exclusions, $item->exclusionReason ?? 'missing_validated_canonical_data');

                continue;
            }
            if ($item->companyName === null || trim($item->companyName) === '') {
                $this->increment($exclusions, 'missing_historical_company_identity');

                continue;
            }

            $family = $this->familyForHistoricalEvidence($item);
            if ($family === null) {
                $this->increment($exclusions, 'excluded_pricing_family');

                continue;
            }

            $rate = $this->canonicalCalculator->directGeneralRate(
                $item->canonicalData,
                new ContractContext(
                    pricingModel: $item->pricingModel,
                    contractType: $item->contractType,
                    metering: $item->metering,
                    fixedTimeRange: $item->fixedTimeRange,
                    targetGroup: TargetGroup::Household->value,
                ),
                $dateString,
            );
            if (! $this->validRate($rate)) {
                $this->increment($exclusions, 'missing_or_unsupported_direct_general_rate');

                continue;
            }

            $candidates[] = [
                'family' => $family,
                'company_name' => $item->companyName,
                'rate' => (float) $rate,
                'provenance' => $item->sourceEvidenceIds['historical_interpretation_id'] !== null
                    ? 'retrospective_historical_interpretation'
                    : 'date_bounded_source_interpretation',
            ];
        }

        $rows = $dateString < self::SERIES_START_DATE
            ? []
            : $this->aggregateRows($dateString, $candidates, 'historical_reconstruction');
        $familyCounts = array_count_values(array_column($candidates, 'family'));
        $provenanceCounts = array_count_values(array_column($candidates, 'provenance'));
        ksort($familyCounts);
        ksort($exclusions);
        ksort($provenanceCounts);

        return new SellerSetEnergyPriceIndexDateSummary(
            date: $dateString,
            evidenceCount: count($evidence),
            annualProofCount: $proofIds->count(),
            eligibleContractCount: $eligibleCount,
            directRateCount: count($candidates),
            rowCount: count($rows),
            familyOfferCounts: $familyCounts,
            exclusionCounts: $exclusions,
            provenanceCounts: $provenanceCounts,
            rows: $rows,
        );
    }

    /**
     * Replace one date with a fully resolved historical preview.
     */
    public function writeHistoricalForDate(CarbonInterface|string $date): SellerSetEnergyPriceIndexDateSummary
    {
        $summary = $this->previewHistoricalForDate($date);
        $this->replaceRows($summary->date, $summary->rows);

        return $summary;
    }

    /**
     * @return Collection<int, ContractPriceSnapshot>
     */
    private function eligibleSnapshots(string $dateString): Collection
    {
        return ContractPriceSnapshot::query()
            ->whereDate('snapshot_date', $dateString)
            ->where('pricing_basis', ContractPriceBasis::CanonicalCalculation->value)
            ->where('metering', MeteringType::General->value)
            ->whereNotNull('energy_price_cents_per_kwh')
            ->whereHas('contract', function ($query): void {
                $query->where('availability_is_national', true)
                    ->where('metering', MeteringType::General->value)
                    ->where(function ($targets): void {
                        $targets->whereIn('target_group', [
                            TargetGroup::Household->value,
                            TargetGroup::Both->value,
                        ])->orWhereNull('target_group');
                    });
            })
            ->with(['contract' => fn ($query) => $query->select([
                'id',
                'contract_type',
                'pricing_model',
                'metering',
                'target_group',
                'availability_is_national',
            ])])
            ->orderBy('contract_id')
            ->get();
    }

    private function familyForCurrentSnapshot(ContractPriceSnapshot $snapshot): ?string
    {
        /** @var ElectricityContract|null $contract */
        $contract = $snapshot->contract;
        if ($contract === null || $contract->pricingModelType() === PricingModel::Spot) {
            return null;
        }
        if ($contract->pricingModelType() === PricingModel::Hybrid) {
            return $snapshot->segment_key === 'hybrid' ? 'hybrid_base' : null;
        }
        if ($contract->contractTypeValue() === ContractType::FixedTerm) {
            return 'fixed_term';
        }
        if (in_array($snapshot->segment_key, ['quarterly', 'market_reset'], true)) {
            return 'market_reset';
        }
        if ($snapshot->segment_key === 'open_ended'
            && $contract->contractTypeValue() === ContractType::OpenEnded
        ) {
            return 'open_ended';
        }

        return null;
    }

    private function familyForHistoricalEvidence(AsOfAnnualCostEvidence $evidence): ?string
    {
        $pricingModel = PricingModel::fromSource($evidence->pricingModel);
        if ($pricingModel === PricingModel::Spot) {
            return null;
        }
        if ($pricingModel === PricingModel::Hybrid) {
            return 'hybrid_base';
        }
        if (ContractType::fromSource($evidence->contractType) === ContractType::FixedTerm) {
            return 'fixed_term';
        }
        if ($evidence->canonicalData?->recurringSchedule->isActiveReset()
            || in_array($evidence->segmentKey, ['quarterly', 'market_reset'], true)
        ) {
            return 'market_reset';
        }

        return 'open_ended';
    }

    /**
     * @param  list<array{family:string,company_name:string,rate:float,provenance:string}>  $candidates
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(string $dateString, array $candidates, string $evidenceMode): array
    {
        $rates = [];
        $origins = [];
        foreach ($candidates as $candidate) {
            $rates[$candidate['family']][$candidate['company_name']][] = $candidate['rate'];
            $origins[$candidate['family']][$candidate['provenance']] = ($origins[$candidate['family']][$candidate['provenance']] ?? 0) + 1;
        }

        $familyCounts = [];
        $familyValues = [];
        foreach ([...array_keys(self::FAMILY_WEIGHTS), 'hybrid_base'] as $family) {
            $companyRates = $rates[$family] ?? [];
            $companyMedians = array_values(array_map(
                fn (array $values): float => $this->median($values),
                $companyRates,
            ));
            $familyCounts[$family] = [
                'contract_count' => array_sum(array_map('count', $companyRates)),
                'supplier_count' => count($companyRates),
            ];
            $minimumSuppliers = isset(self::FAMILY_WEIGHTS[$family]) ? self::MIN_FAMILY_SUPPLIERS : 1;
            $familyValues[$family] = count($companyMedians) < $minimumSuppliers
                ? null
                : array_sum($companyMedians) / count($companyMedians);
        }

        $allProvenanceCounts = [];
        foreach (array_intersect_key($origins, self::FAMILY_WEIGHTS) as $counts) {
            foreach ($counts as $origin => $count) {
                $allProvenanceCounts[$origin] = ($allProvenanceCounts[$origin] ?? 0) + $count;
            }
        }
        ksort($allProvenanceCounts);

        $rows = [];
        foreach (self::FAMILY_SEGMENTS as $family => $segment) {
            if ($familyValues[$family] === null) {
                continue;
            }
            $rows[] = $this->row(
                $dateString,
                $segment,
                $familyValues[$family],
                $familyCounts[$family],
                $familyCounts,
                includedInOverall: true,
                evidenceMode: $evidenceMode,
                provenanceCounts: $origins[$family] ?? [],
            );
        }

        if ($familyValues['hybrid_base'] !== null) {
            $rows[] = $this->row(
                $dateString,
                self::SEGMENT_HYBRID_BASE,
                $familyValues['hybrid_base'],
                $familyCounts['hybrid_base'],
                $familyCounts,
                includedInOverall: false,
                evidenceMode: $evidenceMode,
                provenanceCounts: $origins['hybrid_base'] ?? [],
            );
        }

        $requiredFamiliesAvailable = collect(array_keys(self::FAMILY_WEIGHTS))
            ->every(fn (string $family): bool => $familyValues[$family] !== null);
        if ($requiredFamiliesAvailable) {
            $weights = $this->normalizedWeights();
            $overallValue = 0.0;
            foreach ($weights as $family => $weight) {
                $overallValue += $familyValues[$family] * $weight;
            }
            $overallContractCount = array_sum(array_map(
                fn (string $family): int => $familyCounts[$family]['contract_count'],
                array_keys(self::FAMILY_WEIGHTS),
            ));
            $overallSuppliers = collect(array_keys(self::FAMILY_WEIGHTS))
                ->flatMap(fn (string $family): array => array_keys($rates[$family]))
                ->unique()
                ->count();
            $rows[] = $this->row(
                $dateString,
                self::SEGMENT_OVERALL,
                $overallValue,
                ['contract_count' => $overallContractCount, 'supplier_count' => $overallSuppliers],
                $familyCounts,
                includedInOverall: true,
                evidenceMode: $evidenceMode,
                provenanceCounts: $allProvenanceCounts,
            );
        }

        return $rows;
    }

    /**
     * @param  array{contract_count:int,supplier_count:int}  $counts
     * @param  array<string,array{contract_count:int,supplier_count:int}>  $familyCounts
     * @param  array<string, int>  $provenanceCounts
     * @return array<string, mixed>
     */
    private function row(
        string $dateString,
        string $segment,
        float $value,
        array $counts,
        array $familyCounts,
        bool $includedInOverall,
        string $evidenceMode,
        array $provenanceCounts,
    ): array {
        $now = now();
        ksort($provenanceCounts);

        return [
            'stat_date' => $dateString,
            'segment_key' => $segment,
            'metric_key' => self::METRIC_KEY,
            'pricing_basis' => ContractPriceBasis::CanonicalCalculation->value,
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            'calculation_basis' => self::CALCULATION_BASIS,
            'estimate_basis' => self::ESTIMATE_BASIS,
            'compatibility_key' => self::COMPATIBILITY_KEY,
            'basis_counts' => json_encode([
                'metric_version' => self::METRIC_KEY,
                'basket_date' => self::BASKET_DATE,
                'series_start_date' => self::SERIES_START_DATE,
                'weighting' => 'company_median_then_family_mean_fixed_weights_v1',
                'family_weights' => self::FAMILY_WEIGHTS,
                'minimum_family_suppliers' => self::MIN_FAMILY_SUPPLIERS,
                'family_counts' => $familyCounts,
                'contract_count' => $counts['contract_count'],
                'supplier_count' => $counts['supplier_count'],
                'included_in_overall' => $includedInOverall,
                'evidence_mode' => $evidenceMode,
                'historical_provenance_counts' => $provenanceCounts,
            ], JSON_THROW_ON_ERROR),
            'consumption_kwh' => null,
            'min_value' => null,
            'p20_value' => null,
            'avg_value' => $value,
            'median_value' => null,
            'p80_value' => null,
            'max_value' => null,
            'contract_count' => $counts['contract_count'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceRows(string $dateString, array $rows): void
    {
        DB::transaction(function () use ($dateString, $rows): void {
            ContractPriceDailyStatistic::query()
                ->whereDate('stat_date', $dateString)
                ->where('metric_key', self::METRIC_KEY)
                ->delete();
            if ($rows !== []) {
                ContractPriceDailyStatistic::query()->insert($rows);
            }
        });
    }

    private function validRate(mixed $value): bool
    {
        return $value !== null
            && is_finite((float) $value)
            && (float) $value >= 0.005
            && (float) $value <= self::MAX_ENERGY_PRICE_CENTS_PER_KWH;
    }

    /** @return array<string, float> */
    private function normalizedWeights(): array
    {
        $sum = array_sum(self::FAMILY_WEIGHTS);
        if ($sum <= 0.0 || abs($sum - 1.0) > 0.000001) {
            throw new \RuntimeException('Seller-set energy-price family weights are invalid.');
        }

        return array_map(fn (float $weight): float => $weight / $sum, self::FAMILY_WEIGHTS);
    }

    /** @param array<int, float> $values */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date, 'Europe/Helsinki')->toDateString();
    }
}
