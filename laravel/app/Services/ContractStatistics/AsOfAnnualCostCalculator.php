<?php

namespace App\Services\ContractStatistics;

use App\Enums\PricingModel;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\SpotForwardPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\ContractPriceCalculator;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostResult;
use App\Services\ContractStatistics\DTO\AsOfSpotAssumptionsResult;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AsOfAnnualCostCalculator
{
    public const DEFAULT_CONSUMPTIONS = [2000, 5000, 18000];

    private const MAXIMUM_ANNUAL_COST_EUR = 50000.0;

    public function __construct(
        private readonly AsOfAnnualCostEvidenceResolver $evidenceResolver,
        private readonly AsOfSpotAssumptionsProvider $spotAssumptionsProvider,
        private readonly SpotForwardPriceEstimator $spotEstimator,
        private readonly HistoricalPriceEpisodeResolver $priceEpisodeResolver,
        private readonly CanonicalContractPriceCalculator $canonicalCalculator,
        private readonly ContractPriceCalculator $relationalCalculator,
    ) {}

    /**
     * Calculate all three consumption results for every contract on one historical date.
     *
     * @return list<AsOfAnnualCostResult>
     */
    public function calculate(CarbonInterface|string $date): array
    {
        $target = CarbonImmutable::parse(
            $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'Europe/Helsinki',
        )->startOfDay();
        $evidence = $this->evidenceResolver->resolveDate($target);

        // These are date-level shared inputs. Resolve each only once before costing contracts.
        $spotResult = $this->spotAssumptionsProvider->resolve($target);
        $needsSpotEstimate = collect($evidence)->contains(function (AsOfAnnualCostEvidence $item): bool {
            return $item->canonicalData !== null
                ? $this->canonicalCalculator->usesSpotPricing($item->canonicalData, $this->context($item))
                : PricingModel::fromSource($item->pricingModel) === PricingModel::Spot;
        });
        $spotEstimate = $spotResult->isAvailable() && $needsSpotEstimate
            ? $this->spotEstimator->estimate($target, $spotResult->assumptions)
            : null;
        $spot = $spotResult->assumptions ?? new SpotAssumptions(null, null);

        $candidates = [];
        $candidateBases = [];
        foreach ($evidence as $item) {
            if ($item->canonicalData === null || $item->canonicalData->recurringSchedule->isActiveReset()) {
                continue;
            }

            $candidate = $this->canonicalCalculator->supplierAdjustedCandidate(
                $item->contractId,
                $item->canonicalData,
                $this->context($item),
            );
            if ($candidate !== null) {
                $candidates[$item->contractId] = $candidate;
                $candidateBases[$item->contractId] = $item->pricingBasis;
            }
        }
        $anchors = $this->priceEpisodeResolver->resolve($target, $candidates, $candidateBases);

        $results = [];
        foreach ($evidence as $item) {
            foreach (self::DEFAULT_CONSUMPTIONS as $consumption) {
                $results[] = $this->calculateOne(
                    $item,
                    $consumption,
                    $spot,
                    $spotResult->isAvailable(),
                    $spotEstimate,
                    $anchors[$item->contractId] ?? null,
                    $spotResult,
                );
            }
        }

        return $results;
    }

    private function calculateOne(
        AsOfAnnualCostEvidence $evidence,
        int $consumption,
        SpotAssumptions $spot,
        bool $spotAvailable,
        ?SpotEstimate $spotEstimate,
        ?PriceEpisodeAnchor $anchor,
        AsOfSpotAssumptionsResult $spotResult,
    ): AsOfAnnualCostResult {
        $canonical = $evidence->canonicalData !== null;
        $usesSpot = $canonical
            ? $this->canonicalCalculator->usesSpotPricing($evidence->canonicalData, $this->context($evidence))
            : PricingModel::fromSource($evidence->pricingModel) === PricingModel::Spot;
        $recurringHold = $canonical
            && ! $usesSpot
            && $evidence->canonicalData->recurringSchedule->isActiveReset();
        $calculationBasis = $canonical && ! $recurringHold
            ? AnnualCostCalculationBasis::CanonicalOutcome
            : AnnualCostCalculationBasis::ObservedRelationalComponents;
        $flags = [
            ...$evidence->provenanceFlags,
            ...$this->spotEvidenceFlags($spotResult),
        ];

        if ($evidence->exclusionReason !== null) {
            return $this->result(
                $evidence,
                $consumption,
                null,
                $calculationBasis,
                null,
                null,
                null,
                $flags,
                $evidence->exclusionReason,
            );
        }

        if (! $evidence->isAvailableForConsumption($consumption)) {
            return $this->result(
                $evidence,
                $consumption,
                null,
                $calculationBasis,
                null,
                null,
                $anchor,
                $flags,
                'legacy_annual_cost_mask_unavailable',
            );
        }

        if ($usesSpot && (! $spotAvailable || $spotEstimate === null)) {
            $flags[] = 'spot_assumptions_unavailable'.($spotResult->unavailableReason !== null ? '_'.$spotResult->unavailableReason : '');

            return $this->result(
                $evidence,
                $consumption,
                null,
                $calculationBasis,
                null,
                null,
                $anchor,
                $flags,
                'spot_assumptions_unavailable',
            );
        }

        if ($recurringHold) {
            if ($evidence->priceComponents === []) {
                return $this->result(
                    $evidence,
                    $consumption,
                    null,
                    $calculationBasis,
                    EstimateMethod::HoldCurrentRecurringPrice->value,
                    'exact_date_recurring_price_held_flat',
                    $anchor,
                    [...$flags, 'recurring_reset_relational_hold_flat_no_as_of_reset_estimator'],
                    'exact_date_components_unavailable_for_recurring_hold',
                );
            }

            $pricing = $this->relationalCost($evidence, $consumption, $spotEstimate);

            return $this->validatedResult(
                $evidence,
                $consumption,
                $pricing->totalCost,
                $calculationBasis,
                EstimateMethod::HoldCurrentRecurringPrice->value,
                'exact_date_recurring_price_held_flat',
                $anchor,
                [...$flags, 'recurring_reset_relational_hold_flat_no_as_of_reset_estimator'],
            );
        }

        if ($canonical) {
            $outcome = $this->canonicalCalculator->calculate(
                $evidence->canonicalData,
                $this->context($evidence),
                new EnergyUsage(total: $consumption, basicLiving: $consumption),
                $spot,
                $evidence->date,
                $anchor,
                $usesSpot ? $spotEstimate : null,
            );
            $estimateBasis = $this->canonicalEstimateBasis($outcome->supplierAdjustedEstimate, $outcome->spotEstimate);
            $outcomeFlags = [
                ...$flags,
                ...$outcome->assumptions,
                ...$this->nestedFlags($outcome->supplierAdjustedEstimate),
                ...$this->nestedFlags($outcome->spotEstimate),
            ];

            // The shared seasonal supplier provider uses present-day realized data and has
            // no as-of date boundary. Its result can never enter historical method v1.
            if ($outcome->estimateMethod === EstimateMethod::SupplierAdjustedSpotSeasonalIndex) {
                if ($evidence->priceComponents === []) {
                    return $this->result(
                        $evidence,
                        $consumption,
                        null,
                        AnnualCostCalculationBasis::ObservedRelationalComponents,
                        EstimateMethod::HoldCurrentSupplierPrice->value,
                        'supplier_adjusted_exact_date_relational_hold_flat',
                        $anchor,
                        [...$outcomeFlags, 'supplier_seasonal_index_rejected_not_date_bounded'],
                        'exact_date_components_unavailable_for_supplier_hold',
                    );
                }

                $pricing = $this->relationalCost($evidence, $consumption, null);

                return $this->validatedResult(
                    $evidence,
                    $consumption,
                    $pricing->totalCost,
                    AnnualCostCalculationBasis::ObservedRelationalComponents,
                    EstimateMethod::HoldCurrentSupplierPrice->value,
                    'supplier_adjusted_exact_date_relational_hold_flat',
                    $anchor,
                    [...$outcomeFlags, 'supplier_seasonal_index_rejected_not_date_bounded'],
                );
            }

            return $this->validatedResult(
                $evidence,
                $consumption,
                $outcome->isListed() ? $outcome->totalCost : null,
                $calculationBasis,
                $outcome->estimateMethod->value,
                $estimateBasis,
                $anchor,
                $outcomeFlags,
                $outcome->isListed() ? null : 'canonical_outcome_not_listed',
            );
        }

        if ($evidence->priceComponents === []) {
            return $this->result(
                $evidence,
                $consumption,
                null,
                $calculationBasis,
                null,
                null,
                $anchor,
                $flags,
                'exact_date_components_unavailable',
            );
        }

        $pricing = $this->relationalCost($evidence, $consumption, $spotEstimate);
        if (PricingModel::fromSource($evidence->pricingModel) === PricingModel::Spot) {
            $estimateMethod = $spotEstimate->basis->isForward()
                ? EstimateMethod::ForwardCurveSpot->value
                : EstimateMethod::Rolling365Spot->value;
            $estimateBasis = $spotEstimate->basis->value;
            $flags = [...$flags, ...$spotEstimate->flags];
        } else {
            $estimateMethod = EstimateMethod::None->value;
            $estimateBasis = 'exact_date_components_held_flat';
            if ($evidence->contractType === 'OpenEnded' && PricingModel::fromSource($evidence->pricingModel) === PricingModel::FixedPrice) {
                $estimateBasis = 'relational_open_ended_conservative_hold_flat';
                $flags[] = 'relational_open_ended_no_proven_historical_adjustment_mechanism';
            }
        }

        return $this->validatedResult(
            $evidence,
            $consumption,
            $pricing->totalCost,
            $calculationBasis,
            $estimateMethod,
            $estimateBasis,
            $anchor,
            $flags,
        );
    }

    private function relationalCost(
        AsOfAnnualCostEvidence $evidence,
        int $consumption,
        ?SpotEstimate $spotEstimate,
    ): \App\Services\DTO\ContractPricingResult {
        $isSpot = PricingModel::fromSource($evidence->pricingModel) === PricingModel::Spot;

        return $this->relationalCalculator->calculate(
            $evidence->priceComponents,
            [
                'contract_type' => $evidence->contractType,
                'pricing_model' => $evidence->pricingModel,
                'metering' => $evidence->metering,
            ],
            new EnergyUsage(total: $consumption, basicLiving: $consumption),
            $isSpot ? $spotEstimate?->annualEquivalentDayCentsPerKwh : null,
            $isSpot ? $spotEstimate?->annualEquivalentNightCentsPerKwh : null,
            $evidence->date,
        );
    }

    private function context(AsOfAnnualCostEvidence $evidence): ContractContext
    {
        return new ContractContext(
            pricingModel: $evidence->pricingModel,
            contractType: $evidence->contractType,
            metering: $evidence->metering,
            fixedTimeRange: $evidence->fixedTimeRange,
            targetGroup: 'Household',
        );
    }

    /**
     * @param  array<string, mixed>|null  $supplier
     * @param  array<string, mixed>|null  $spot
     */
    private function canonicalEstimateBasis(?array $supplier, ?array $spot): string
    {
        if (is_string($spot['basis'] ?? null)) {
            return (string) $spot['basis'];
        }
        if (is_string($supplier['basis'] ?? null)) {
            return 'supplier_adjusted_'.$supplier['basis'];
        }

        return 'canonical_disclosed_phase_timeline';
    }

    /** @return list<string> */
    private function spotEvidenceFlags(AsOfSpotAssumptionsResult $result): array
    {
        $flags = ['spot_assumptions_source_'.$result->source];
        foreach ($result->provenanceFlags as $flag) {
            $flags[] = 'spot_assumptions_'.$flag;
        }
        if ($result->coverageRatio !== null) {
            $flags[] = 'spot_assumptions_coverage_ratio_'.number_format($result->coverageRatio, 6, '.', '');
        }
        if ($result->expectedHours !== null) {
            $flags[] = 'spot_assumptions_expected_hours_'.$result->expectedHours;
        }
        if ($result->actualHours !== null) {
            $flags[] = 'spot_assumptions_actual_hours_'.$result->actualHours;
        }

        return $flags;
    }

    /** @param array<string, mixed>|null $payload
     * @return list<string>
     */
    private function nestedFlags(?array $payload): array
    {
        $flags = $payload['flags'] ?? [];

        return is_array($flags)
            ? array_values(array_filter($flags, fn ($flag): bool => is_string($flag)))
            : [];
    }

    /** @param list<string> $flags */
    private function validatedResult(
        AsOfAnnualCostEvidence $evidence,
        int $consumption,
        ?float $total,
        AnnualCostCalculationBasis $basis,
        ?string $estimateMethod,
        ?string $estimateBasis,
        ?PriceEpisodeAnchor $anchor,
        array $flags,
        ?string $unavailableReason = null,
    ): AsOfAnnualCostResult {
        if ($total === null) {
            $unavailableReason ??= 'annual_cost_unavailable';
        } elseif (! is_finite($total) || $total < 0) {
            $total = null;
            $unavailableReason = 'annual_cost_not_finite_non_negative';
        } elseif ($total > self::MAXIMUM_ANNUAL_COST_EUR) {
            $total = null;
            $unavailableReason = 'annual_cost_exceeds_ceiling';
        }

        return $this->result(
            $evidence,
            $consumption,
            $total,
            $basis,
            $estimateMethod,
            $estimateBasis,
            $anchor,
            $flags,
            $unavailableReason,
        );
    }

    /** @param list<string> $flags */
    private function result(
        AsOfAnnualCostEvidence $evidence,
        int $consumption,
        ?float $total,
        AnnualCostCalculationBasis $basis,
        ?string $estimateMethod,
        ?string $estimateBasis,
        ?PriceEpisodeAnchor $anchor,
        array $flags,
        ?string $unavailableReason,
    ): AsOfAnnualCostResult {
        return new AsOfAnnualCostResult(
            contractId: $evidence->contractId,
            date: $evidence->date,
            segmentKey: $evidence->segmentKey,
            consumptionKwh: $consumption,
            totalCost: $total,
            methodVersion: AnnualCostMethodVersion::AsOf,
            pricingBasis: $evidence->pricingBasis,
            calculationBasis: $basis,
            estimateMethod: $estimateMethod,
            estimateBasis: $estimateBasis,
            compatibilityKey: AnnualCostCompatibilityKey::make(
                AnnualCostMethodVersion::AsOf,
                $basis,
                $estimateMethod,
                $estimateBasis,
            ),
            sourceEvidenceIds: $evidence->sourceEvidenceIds,
            priceEpisodeStartedAt: $anchor?->startedAt,
            provenanceFlags: array_values(array_unique($flags)),
            unavailableReason: $unavailableReason,
        );
    }
}
