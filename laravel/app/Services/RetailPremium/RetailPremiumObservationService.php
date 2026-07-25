<?php

namespace App\Services\RetailPremium;

use App\Models\ElectricityContract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RetailPremiumObservationService
{
    public const METHOD_VERSION = 'retail-premium-v2';

    public const REFERENCE_CONSUMPTION_KWH = 5000;

    public function __construct(private readonly VintageAwareReferencePriceService $referencePriceService) {}

    /**
     * Build every currently supported observation for one active contract.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function buildObservations(ElectricityContract $contract, CarbonInterface $asOfDate): Collection
    {
        if ($contract->pricing_model === 'Spot') {
            return $this->buildSpotObservations($contract, $asOfDate);
        }

        if ($contract->pricing_model === 'Hybrid') {
            return $this->buildHybridObservations($contract, $asOfDate);
        }

        if ($contract->pricing_model !== 'FixedPrice') {
            return collect();
        }

        $contract->loadMissing('publishedInterpretation.sourceSnapshot');
        $pricing = $contract->canonical_pricing;

        if (($pricing['recurring_schedule']['present'] ?? false) === true) {
            return $this->buildMarketResetObservations($contract, $asOfDate);
        }

        $durationMonths = $contract->publishedInterpretation?->output['classification']['fixed_duration_months'] ?? null;

        if ($contract->contract_type === 'FixedTerm' && is_int($durationMonths) && $durationMonths > 0) {
            return $this->buildFixedTermObservations($contract, $asOfDate, $durationMonths);
        }

        return collect();
    }

    /**
     * Build the disclosed Spot premium observations for one active offer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function buildSpotObservations(ElectricityContract $contract, CarbonInterface $asOfDate): Collection
    {
        if ($contract->pricing_model !== 'Spot' || $contract->published_interpretation_id === null) {
            return collect();
        }

        $contract->loadMissing('publishedInterpretation.sourceSnapshot');
        $interpretation = $contract->publishedInterpretation;
        $snapshot = $interpretation?->sourceSnapshot;

        if ($interpretation === null || $snapshot === null || $interpretation->status !== 'published') {
            return collect();
        }

        $pricing = $contract->canonical_pricing;
        $phases = is_array($pricing['phases'] ?? null) ? $pricing['phases'] : [];
        $asOf = CarbonImmutable::instance($asOfDate)->startOfDay();
        $firstObserved = $snapshot->first_observed_at?->toImmutable()->startOfDay() ?? $asOf;
        $lastObserved = $snapshot->last_observed_at?->toImmutable()->startOfDay() ?? $asOf;
        $lineage = $this->getLineageIdentity($contract);

        return collect($phases)
            ->values()
            ->flatMap(function ($phase, int $phaseIndex) use (
                $contract,
                $interpretation,
                $snapshot,
                $pricing,
                $firstObserved,
                $lastObserved,
                $lineage,
            ) {
                if (! is_array($phase)) {
                    return [];
                }

                $components = collect(is_array($phase['components'] ?? null) ? $phase['components'] : [])
                    ->filter(fn ($component) => is_array($component))
                    ->values();
                $spotMargins = $components
                    ->where('component_type', 'spot_margin')
                    ->filter(fn (array $component) => is_numeric($component['amount'] ?? null))
                    ->values();

                if ($spotMargins->isEmpty()) {
                    return [];
                }

                $monthlyFees = $components
                    ->where('component_type', 'monthly_fee')
                    ->filter(fn (array $component) => is_numeric($component['amount'] ?? null))
                    ->values();
                $spotPremium = (float) $spotMargins->first()['amount'];
                $monthlyFee = $monthlyFees->isEmpty() ? null : (float) $monthlyFees->first()['amount'];
                $disclosedVatBasis = $this->contractDisclosedVatBasis($pricing);
                $energyVat = $this->resolveVatBasis($spotMargins->first(), $disclosedVatBasis);
                $vatBasis = $energyVat['basis'];
                $feeVatBasis = $monthlyFees->isEmpty()
                    ? null
                    : $this->resolveVatBasis($monthlyFees->first(), $disclosedVatBasis)['basis'];
                $periodStart = $this->periodBoundary($phase['starts'] ?? null);
                $periodEnd = $this->periodBoundary($phase['ends'] ?? null);
                $cadence = $pricing['recurring_schedule']['cadence'] ?? null;

                if (($phase['phase_kind'] ?? null) === 'recurring_period') {
                    $periodStart ??= $this->dateOrNull($pricing['recurring_schedule']['current_period_start'] ?? null);
                    $periodEnd ??= $this->dateOrNull($pricing['recurring_schedule']['current_period_end'] ?? null);
                }

                $priceSignature = hash('sha256', json_encode([
                    'phase_index' => $phaseIndex,
                    'phase_kind' => $phase['phase_kind'] ?? null,
                    'starts' => $phase['starts'] ?? null,
                    'ends' => $phase['ends'] ?? null,
                    'spot_margins' => $spotMargins->map(fn (array $component) => $this->componentSignature($component))->all(),
                    'monthly_fees' => $monthlyFees->map(fn (array $component) => $this->componentSignature($component))->all(),
                    'vat_basis' => $vatBasis,
                ], JSON_THROW_ON_ERROR));
                $observationKey = hash('sha256', implode('|', [
                    $lineage['key'],
                    $priceSignature,
                    $firstObserved->toDateString(),
                    (string) $interpretation->id,
                ]));
                $premiumWithFee = $monthlyFee === null || $feeVatBasis !== $vatBasis
                    ? null
                    : $spotPremium + ($monthlyFee * 12 * 100 / self::REFERENCE_CONSUMPTION_KWH);

                return [[
                    'observation_key' => $observationKey,
                    'price_signature' => $priceSignature,
                    'lineage_key' => $lineage['key'],
                    'lineage_contract_id' => $contract->id,
                    'contract_id' => $contract->id,
                    'published_interpretation_id' => $interpretation->id,
                    'source_snapshot_id' => $snapshot->id,
                    'company_name' => $contract->company_name,
                    'pricing_model' => $contract->pricing_model,
                    'cadence' => $cadence,
                    'contract_type' => $contract->contract_type,
                    'target_group' => $contract->target_group,
                    'metering' => $contract->metering,
                    'phase_index' => $phaseIndex,
                    'phase_kind' => $phase['phase_kind'] ?? null,
                    'phase_label' => $phase['label'] ?? null,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'first_observed_date' => $firstObserved->toDateString(),
                    'last_observed_date' => $lastObserved->toDateString(),
                    'energy_components' => $components->all(),
                    'energy_component_type' => 'spot_margin',
                    'retail_energy_price_cents_per_kwh' => null,
                    'monthly_fee_eur' => $monthlyFee,
                    'vat_basis' => $vatBasis,
                    'fee_vat_basis' => $feeVatBasis,
                    'vat_basis_source' => $energyVat['source'],
                    'reference_consumption_kwh' => self::REFERENCE_CONSUMPTION_KWH,
                    'reference_kind' => 'spot_disclosed',
                    'reference_trade_date' => null,
                    'reference_price_cents_per_kwh' => null,
                    'reference_price_including_vat_cents_per_kwh' => null,
                    'reference_price_excluding_vat_cents_per_kwh' => null,
                    'reference_settlement_price_eur_per_mwh' => null,
                    'retail_premium_cents_per_kwh' => $spotPremium,
                    'retail_premium_with_fee_cents_per_kwh' => $premiumWithFee,
                    'method_version' => self::METHOD_VERSION,
                    'quality' => 'exact',
                    'quality_flags' => $this->qualityFlags(
                        $contract,
                        $interpretation->output,
                        $phase,
                        $spotMargins,
                        $monthlyFees,
                        $vatBasis,
                        $feeVatBasis,
                        $energyVat['source'],
                        $spotPremium,
                    ),
                    'source_metadata' => [
                        'lineage_contract_ids' => $lineage['contract_ids'],
                        'lineage_root_ids' => $lineage['root_ids'],
                        'schema_version' => $interpretation->schema_version,
                        'prompt_version' => $interpretation->prompt_version,
                        'validator_version' => $interpretation->validator_version,
                        'source_fingerprint' => $snapshot->source_fingerprint,
                    ],
                ]];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildMarketResetObservations(ElectricityContract $contract, CarbonInterface $asOfDate): Collection
    {
        $context = $this->observationContext($contract, $asOfDate);

        if ($context === null) {
            return collect();
        }

        $references = $this->referencePriceService->forResetPeriod(
            $context['first_observed'],
            $context['first_observed'],
        );

        return $this->buildReferencedPhaseRows($contract, $context, $references, 'inferred');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildFixedTermObservations(
        ElectricityContract $contract,
        CarbonInterface $asOfDate,
        int $durationMonths,
    ): Collection {
        $context = $this->observationContext($contract, $asOfDate);

        if ($context === null) {
            return collect();
        }

        $references = $this->referencePriceService->forDeliveryTerm(
            $context['first_observed'],
            $durationMonths,
        );
        $context['duration_months'] = $durationMonths;
        $context['excluded_phase_kinds'] = ['continuation'];

        return $this->buildReferencedPhaseRows($contract, $context, $references, 'inferred');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildHybridObservations(ElectricityContract $contract, CarbonInterface $asOfDate): Collection
    {
        $context = $this->observationContext($contract, $asOfDate);

        if ($context === null) {
            return collect();
        }

        $references = collect(['hybrid_base' => [
            'reference_kind' => 'hybrid_base',
            'trade_date' => null,
            'price_cents_per_kwh_including_vat' => null,
            'price_cents_per_kwh_excluding_vat' => null,
            'metadata' => ['reason' => 'consumption_effect_not_comparable'],
        ]]);

        return $this->buildReferencedPhaseRows($contract, $context, $references, 'not_comparable');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function observationContext(ElectricityContract $contract, CarbonInterface $asOfDate): ?array
    {
        if ($contract->published_interpretation_id === null) {
            return null;
        }

        $contract->loadMissing('publishedInterpretation.sourceSnapshot');
        $interpretation = $contract->publishedInterpretation;
        $snapshot = $interpretation?->sourceSnapshot;

        if ($interpretation === null || $snapshot === null || $interpretation->status !== 'published') {
            return null;
        }

        $asOf = CarbonImmutable::instance($asOfDate)->startOfDay();

        return [
            'interpretation' => $interpretation,
            'snapshot' => $snapshot,
            'pricing' => $contract->canonical_pricing,
            'first_observed' => $snapshot->first_observed_at?->toImmutable()->startOfDay() ?? $asOf,
            'last_observed' => $snapshot->last_observed_at?->toImmutable()->startOfDay() ?? $asOf,
            'lineage' => $this->getLineageIdentity($contract),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Collection<string, array<string, mixed>>  $references
     * @return Collection<int, array<string, mixed>>
     */
    private function buildReferencedPhaseRows(
        ElectricityContract $contract,
        array $context,
        Collection $references,
        string $quality,
    ): Collection {
        $pricing = $context['pricing'];
        $phases = is_array($pricing['phases'] ?? null) ? $pricing['phases'] : [];

        if ($references->isEmpty()) {
            $references = collect(['curve_unavailable' => [
                'reference_kind' => 'curve_unavailable',
                'trade_date' => null,
                'price_cents_per_kwh_including_vat' => null,
                'price_cents_per_kwh_excluding_vat' => null,
                'metadata' => ['reason' => 'no_prior_futures_vintage'],
            ]]);
        }

        return collect($phases)->values()->flatMap(function ($phase, int $phaseIndex) use (
            $contract,
            $context,
            $pricing,
            $references,
            $quality,
        ) {
            if (! is_array($phase)
                || in_array($phase['phase_kind'] ?? null, $context['excluded_phase_kinds'] ?? [], true)) {
                return [];
            }

            $components = collect(is_array($phase['components'] ?? null) ? $phase['components'] : [])
                ->filter(fn ($component) => is_array($component))
                ->values();
            $energyComponents = $components
                ->whereIn('component_type', [
                    'energy_general',
                    'energy_day',
                    'energy_night',
                    'energy_seasonal_winter',
                    'energy_seasonal_other',
                ])
                ->filter(fn (array $component) => is_numeric($component['amount'] ?? null))
                ->values();

            if ($energyComponents->isEmpty()) {
                return [];
            }

            $monthlyFees = $components
                ->where('component_type', 'monthly_fee')
                ->filter(fn (array $component) => is_numeric($component['amount'] ?? null))
                ->values();
            $monthlyFee = $monthlyFees->isEmpty() ? null : (float) $monthlyFees->first()['amount'];
            $periodStart = $this->periodBoundary($phase['starts'] ?? null);
            $periodEnd = $this->periodBoundary($phase['ends'] ?? null);

            if (($phase['phase_kind'] ?? null) === 'recurring_period') {
                $periodStart ??= $this->dateOrNull($pricing['recurring_schedule']['current_period_start'] ?? null);
                $periodEnd ??= $this->dateOrNull($pricing['recurring_schedule']['current_period_end'] ?? null);
            }

            return $energyComponents->flatMap(function (array $energyComponent) use (
                $contract,
                $context,
                $pricing,
                $references,
                $quality,
                $phase,
                $phaseIndex,
                $components,
                $monthlyFees,
                $monthlyFee,
                $periodStart,
                $periodEnd,
                $energyComponents,
            ) {
                $retailPrice = (float) $energyComponent['amount'];
                $disclosedVatBasis = $this->contractDisclosedVatBasis($pricing);
                $energyVat = $this->resolveVatBasis($energyComponent, $disclosedVatBasis);
                $vatBasis = $energyVat['basis'];
                $feeVatBasis = $monthlyFees->isEmpty()
                    ? null
                    : $this->resolveVatBasis($monthlyFees->first(), $disclosedVatBasis)['basis'];
                $priceSignature = hash('sha256', json_encode([
                    'phase_index' => $phaseIndex,
                    'phase_kind' => $phase['phase_kind'] ?? null,
                    'starts' => $phase['starts'] ?? null,
                    'ends' => $phase['ends'] ?? null,
                    'energy_component' => $this->componentSignature($energyComponent),
                    'monthly_fees' => $monthlyFees->map(fn (array $component) => $this->componentSignature($component))->all(),
                    'vat_basis' => $vatBasis,
                ], JSON_THROW_ON_ERROR));
                $observationKey = hash('sha256', implode('|', [
                    $context['lineage']['key'],
                    $priceSignature,
                    $context['first_observed']->toDateString(),
                    (string) $context['interpretation']->id,
                ]));

                return $references->map(function (array $reference) use (
                    $contract,
                    $context,
                    $pricing,
                    $quality,
                    $phase,
                    $phaseIndex,
                    $components,
                    $energyComponent,
                    $energyComponents,
                    $monthlyFees,
                    $monthlyFee,
                    $periodStart,
                    $periodEnd,
                    $retailPrice,
                    $vatBasis,
                    $feeVatBasis,
                    $energyVat,
                    $priceSignature,
                    $observationKey,
                ) {
                    $referencePrice = $quality === 'not_comparable'
                        ? null
                        : $this->referencePriceService->priceForVatBasis($reference, $vatBasis);
                    $premium = $referencePrice === null ? null : $retailPrice - $referencePrice;
                    $premiumWithFee = $premium === null || $monthlyFee === null || $feeVatBasis !== $vatBasis
                        ? null
                        : $premium + ($monthlyFee * 12 * 100 / self::REFERENCE_CONSUMPTION_KWH);

                    return [
                        'observation_key' => $observationKey,
                        'price_signature' => $priceSignature,
                        'lineage_key' => $context['lineage']['key'],
                        'lineage_contract_id' => $contract->id,
                        'contract_id' => $contract->id,
                        'published_interpretation_id' => $context['interpretation']->id,
                        'source_snapshot_id' => $context['snapshot']->id,
                        'company_name' => $contract->company_name,
                        'pricing_model' => $contract->pricing_model,
                        'cadence' => $pricing['recurring_schedule']['cadence'] ?? null,
                        'contract_type' => $contract->contract_type,
                        'target_group' => $contract->target_group,
                        'metering' => $contract->metering,
                        'phase_index' => $phaseIndex,
                        'phase_kind' => $phase['phase_kind'] ?? null,
                        'phase_label' => $phase['label'] ?? null,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'first_observed_date' => $context['first_observed']->toDateString(),
                        'last_observed_date' => $context['last_observed']->toDateString(),
                        'energy_components' => $components->all(),
                        'energy_component_type' => $energyComponent['component_type'],
                        'retail_energy_price_cents_per_kwh' => $retailPrice,
                        'monthly_fee_eur' => $monthlyFee,
                        'vat_basis' => $vatBasis,
                        'fee_vat_basis' => $feeVatBasis,
                        'vat_basis_source' => $energyVat['source'],
                        'reference_consumption_kwh' => self::REFERENCE_CONSUMPTION_KWH,
                        'reference_kind' => $reference['reference_kind'],
                        'reference_trade_date' => $reference['trade_date'],
                        'reference_price_cents_per_kwh' => $referencePrice,
                        'reference_price_including_vat_cents_per_kwh' => $reference['price_cents_per_kwh_including_vat'] ?? null,
                        'reference_price_excluding_vat_cents_per_kwh' => $reference['price_cents_per_kwh_excluding_vat'] ?? null,
                        'reference_settlement_price_eur_per_mwh' => $reference['settlement_price_eur_per_mwh'] ?? null,
                        'retail_premium_cents_per_kwh' => $premium,
                        'retail_premium_with_fee_cents_per_kwh' => $premiumWithFee,
                        'method_version' => self::METHOD_VERSION,
                        'quality' => $quality,
                        'quality_flags' => $this->inferredQualityFlags(
                            $contract,
                            $context['interpretation']->output,
                            $phase,
                            $energyComponents,
                            $monthlyFees,
                            $vatBasis,
                            $feeVatBasis,
                            $energyVat['source'],
                            $reference,
                            $quality,
                        ),
                        'source_metadata' => [
                            'lineage_contract_ids' => $context['lineage']['contract_ids'],
                            'lineage_root_ids' => $context['lineage']['root_ids'],
                            'schema_version' => $context['interpretation']->schema_version,
                            'prompt_version' => $context['interpretation']->prompt_version,
                            'validator_version' => $context['interpretation']->validator_version,
                            'source_fingerprint' => $context['snapshot']->source_fingerprint,
                            'duration_months' => $context['duration_months'] ?? null,
                            'reference' => $reference['metadata'] ?? null,
                        ],
                    ];
                })->values();
            });
        });
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>  $phase
     * @param  Collection<int, array<string, mixed>>  $energyComponents
     * @param  Collection<int, array<string, mixed>>  $monthlyFees
     * @param  array<string, mixed>  $reference
     * @return list<string>
     */
    private function inferredQualityFlags(
        ElectricityContract $contract,
        ?array $output,
        array $phase,
        Collection $energyComponents,
        Collection $monthlyFees,
        string $vatBasis,
        ?string $feeVatBasis,
        string $vatBasisSource,
        array $reference,
        string $quality,
    ): array {
        $flags = collect($this->vatFlags($vatBasis, $feeVatBasis, $vatBasisSource));

        if ($monthlyFees->isEmpty()) {
            $flags->push('monthly_fee_missing');
        } elseif ($monthlyFees->count() > 1) {
            $flags->push('multiple_monthly_fees');
        }

        if ($energyComponents->count() > 1) {
            $flags->push('multi_rate_component_not_aggregated');
        }

        if ($energyComponents->contains(fn (array $component) => (float) $component['amount'] <= 0)) {
            $flags->push('zero_or_negative_retail_energy_price');
        }

        if (($reference['reference_kind'] ?? null) === 'curve_unavailable') {
            $flags->push('reference_curve_unavailable');
        }

        if (($reference['metadata']['vintage_inside_delivery_period'] ?? false) === true) {
            $flags->push('vintage_inside_delivery_period');
        }

        if (($reference['reference_kind'] ?? null) === 'quarter_month_average') {
            $flags->push('quarter_reference_derived_from_month_futures');
        }

        if ($quality === 'not_comparable') {
            $flags->push('hybrid_consumption_effect_not_comparable');
        }

        if ($this->periodBoundary($phase['starts'] ?? null) === null
            && $this->periodBoundary($phase['ends'] ?? null) === null) {
            $flags->push('calendar_period_unknown');
        }

        $sourceStatus = $contract->canonical_source_consistency['structured_pricing_status'] ?? null;

        if (is_string($sourceStatus) && $sourceStatus !== 'complete') {
            $flags->push('source_consistency_'.$sourceStatus);
        }

        if (($output['confidence']['pricing'] ?? null) === 'low') {
            $flags->push('low_pricing_confidence');
        }

        return $flags->unique()->values()->all();
    }

    /**
     * @return array{key: string, contract_ids: list<string>, root_ids: list<string>}
     */
    public function getLineageIdentity(ElectricityContract $contract): array
    {
        $lineageIds = $contract->getReplacementLineageIds()->sort()->values();
        $targetIds = ElectricityContract::query()
            ->whereIn('id', $lineageIds)
            ->whereNotNull('replaced_by_contract_id')
            ->pluck('replaced_by_contract_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $rootIds = $lineageIds
            ->reject(fn (string $id) => in_array($id, $targetIds, true))
            ->values();

        if ($rootIds->isEmpty()) {
            $rootIds = $lineageIds;
        }

        return [
            'key' => hash('sha256', $rootIds->implode('|')),
            'contract_ids' => $lineageIds->all(),
            'root_ids' => $rootIds->all(),
        ];
    }

    private function periodBoundary(mixed $boundary): ?string
    {
        if (! is_array($boundary) || ($boundary['kind'] ?? null) !== 'date') {
            return null;
        }

        return $this->dateOrNull($boundary['value'] ?? null);
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array<string, mixed>
     */
    private function componentSignature(array $component): array
    {
        return collect($component)
            ->only(['component_type', 'amount', 'normal_amount', 'unit', 'vat_status', 'price_role'])
            ->all();
    }

    /**
     * The single VAT basis this contract discloses, or null when it discloses none or two.
     *
     * This only fills gaps inside one contract's own published price list, which is stated on one
     * VAT basis. It never looks at `target_group` and never borrows from another contract.
     *
     * @param  array<string, mixed>|null  $pricing
     */
    public function contractDisclosedVatBasis(?array $pricing): ?string
    {
        $statuses = collect(is_array($pricing['phases'] ?? null) ? $pricing['phases'] : [])
            ->flatMap(fn ($phase) => is_array($phase['components'] ?? null) ? $phase['components'] : [])
            ->filter(fn ($component) => is_array($component))
            ->pluck('vat_status')
            ->filter(fn ($status) => in_array($status, ['included', 'excluded'], true))
            ->unique()
            ->values();

        return $statuses->count() === 1 ? $statuses->first() : null;
    }

    /**
     * @param  array<string, mixed>|null  $component
     * @return array{basis: string, source: string}
     */
    public function resolveVatBasis(?array $component, ?string $disclosedBasis): array
    {
        $status = $component['vat_status'] ?? null;

        if (in_array($status, ['included', 'excluded'], true)) {
            return ['basis' => $status, 'source' => 'component_explicit'];
        }

        if ($disclosedBasis !== null) {
            return ['basis' => $disclosedBasis, 'source' => 'contract_propagated'];
        }

        return ['basis' => 'unknown', 'source' => 'unresolved'];
    }

    /**
     * @return list<string>
     */
    public function vatFlags(string $vatBasis, ?string $feeVatBasis, string $vatBasisSource): array
    {
        $flags = collect();

        if ($vatBasis === 'unknown') {
            $flags->push('vat_unknown');
        }

        if ($vatBasisSource === 'contract_propagated') {
            $flags->push('vat_basis_propagated_within_contract');
        }

        if ($feeVatBasis !== null && $feeVatBasis !== $vatBasis) {
            $flags->push('fee_vat_basis_not_comparable');
        }

        return $flags->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>  $phase
     * @param  Collection<int, array<string, mixed>>  $spotMargins
     * @param  Collection<int, array<string, mixed>>  $monthlyFees
     * @return list<string>
     */
    private function qualityFlags(
        ElectricityContract $contract,
        ?array $output,
        array $phase,
        Collection $spotMargins,
        Collection $monthlyFees,
        string $vatBasis,
        ?string $feeVatBasis,
        string $vatBasisSource,
        float $spotPremium,
    ): array {
        $flags = collect($this->vatFlags($vatBasis, $feeVatBasis, $vatBasisSource));

        if ($monthlyFees->isEmpty()) {
            $flags->push('monthly_fee_missing');
        }

        if ($spotMargins->count() > 1) {
            $flags->push('multiple_spot_margins');

            if ($spotMargins->pluck('amount')->map(fn ($amount) => (float) $amount)->unique()->count() > 1) {
                $flags->push('conflicting_spot_margins');
            }
        }

        if ($monthlyFees->count() > 1) {
            $flags->push('multiple_monthly_fees');
        }

        if ($spotPremium <= 0) {
            $flags->push('zero_or_negative_retail_premium');
        } elseif ($spotPremium > 5) {
            $flags->push('high_retail_premium_outlier');
        }

        if ($this->periodBoundary($phase['starts'] ?? null) === null
            && $this->periodBoundary($phase['ends'] ?? null) === null) {
            $flags->push('calendar_period_unknown');
        }

        $sourceStatus = $contract->canonical_source_consistency['structured_pricing_status'] ?? null;

        if (is_string($sourceStatus) && $sourceStatus !== 'complete') {
            $flags->push('source_consistency_'.$sourceStatus);
        }

        $pricingConfidence = $output['confidence']['pricing'] ?? null;

        if ($pricingConfidence === 'low') {
            $flags->push('low_pricing_confidence');
        }

        return $flags->unique()->values()->all();
    }
}
