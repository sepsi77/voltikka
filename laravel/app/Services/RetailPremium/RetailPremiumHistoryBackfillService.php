<?php

namespace App\Services\RetailPremium;

use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RetailPremiumHistoryBackfillService
{
    public const METHOD_VERSION = 'retail-premium-history-v1';

    private const RAW_ROLE_MAP = [
        'General' => 'energy_general',
        'DayTime' => 'energy_day',
        'NightTime' => 'energy_night',
        'SeasonalWinter' => 'energy_seasonal_winter',
        'SeasonalWinterDay' => 'energy_seasonal_winter',
        'SeasonalOther' => 'energy_seasonal_other',
        'Monthly' => 'monthly_fee',
    ];

    public function __construct(
        private readonly RetailPremiumObservationService $observationService,
        private readonly VintageAwareReferencePriceService $referencePriceService,
    ) {}

    /**
     * Reconstruct historical semantic price periods from daily relational evidence.
     *
     * @return array{observations: Collection<int, array<string, mixed>>, stats: array<string, int>}
     */
    public function build(
        ElectricityContract $lineageTip,
        CarbonInterface $fromDate,
        CarbonInterface $toDate,
        bool $includeOpenPeriod = false,
    ): array {
        $stats = $this->emptyStats();
        $from = CarbonImmutable::instance($fromDate)->startOfDay();
        $to = CarbonImmutable::instance($toDate)->startOfDay();
        $lineageTip->loadMissing('publishedInterpretation.sourceSnapshot');
        $templateInterpretation = $lineageTip->publishedInterpretation;
        $templateSnapshot = $templateInterpretation?->sourceSnapshot;

        if ($templateInterpretation === null || $templateSnapshot === null || $to->lt($from)) {
            return ['observations' => collect(), 'stats' => $stats];
        }

        if (! $this->isSupportedHistoricalModel($lineageTip)) {
            $stats['unsupported_lineages']++;

            return ['observations' => collect(), 'stats' => $stats];
        }

        if (! $includeOpenPeriod && $templateSnapshot->first_observed_at !== null) {
            $to = $to->min($templateSnapshot->first_observed_at->toImmutable()->startOfDay()->subDay());
        }

        if ($to->lt($from)) {
            return ['observations' => collect(), 'stats' => $stats];
        }

        $lineageIdentity = $this->observationService->getLineageIdentity($lineageTip);
        $lineageContracts = ElectricityContract::query()
            ->whereIn('id', $lineageIdentity['contract_ids'])
            ->get()
            ->filter(fn (ElectricityContract $candidate) => $this->isCompatibleAncestor($lineageTip, $candidate))
            ->keyBy('id');
        $calibration = $this->calibrateRoleMap($lineageTip);

        if ($calibration === []) {
            $stats['uncalibrated_lineages']++;

            return ['observations' => collect(), 'stats' => $stats];
        }

        $historyStart = PriceComponent::query()
            ->whereIn('electricity_contract_id', $lineageContracts->keys())
            ->min('price_date');
        $queryFrom = $historyStart === null
            ? $from
            : CarbonImmutable::parse($historyStart)->startOfDay()->min($from);
        $rows = PriceComponent::query()
            ->whereIn('electricity_contract_id', $lineageContracts->keys())
            ->where('price_date', '>=', $queryFrom->startOfDay())
            ->where('price_date', '<=', $to->endOfDay())
            ->orderBy('price_date')
            ->orderBy('electricity_contract_id')
            ->orderBy('price_component_type')
            ->orderBy('id')
            ->get();
        $states = collect();

        foreach ($rows->groupBy(fn (PriceComponent $row) => $row->price_date->toDateString()) as $date => $dateRows) {
            $stats['daily_snapshots_seen']++;
            $carrierStates = collect();
            $ambiguous = false;
            $incomplete = false;

            foreach ($dateRows->groupBy('electricity_contract_id') as $carrierId => $carrierRows) {
                $state = $this->normalizeCarrierDay($carrierRows, $calibration['raw_roles']);

                if ($state === null) {
                    $ambiguous = true;
                    break;
                }

                $missingRoles = collect($calibration['required_energy_roles'])
                    ->diff($state['energy_components']->keys());

                if ($state['energy_components']->isEmpty() || $missingRoles->isNotEmpty()) {
                    $incomplete = true;
                    break;
                }

                $carrierStates->push($state + ['carrier_ids' => [(string) $carrierId]]);
            }

            if ($ambiguous) {
                $stats['ambiguous_daily_snapshots']++;

                continue;
            }

            if ($incomplete) {
                $stats['incomplete_daily_snapshots']++;

                continue;
            }

            if ($carrierStates->isEmpty()) {
                $stats['incomplete_daily_snapshots']++;

                continue;
            }

            $signatures = $carrierStates->groupBy('signature');

            if ($signatures->count() !== 1) {
                $stats['lineage_overlap_conflicts']++;

                continue;
            }

            $sameState = $signatures->first();
            $first = $sameState->first();
            $states->put($date, [
                'date' => CarbonImmutable::parse($date)->startOfDay(),
                'signature' => $first['signature'],
                'components' => $first['components'],
                'energy_components' => $first['energy_components'],
                'monthly_fee' => $first['monthly_fee'],
                'quality_flags' => $sameState->pluck('quality_flags')->flatten()->unique()->values()->all(),
                'carrier_ids' => $sameState->pluck('carrier_ids')->flatten()->unique()->sort()->values()->all(),
                'component_ids' => $sameState->pluck('component_ids')->flatten()->unique()->sort()->values()->all(),
            ]);
            $stats['daily_snapshots_accepted']++;
        }

        $periods = $this->compressPeriods($states->values())
            ->filter(fn (array $period) => $period['last_observed']->gte($from))
            ->values();
        $stats['periods_reconstructed'] = $periods->count();
        $observations = $periods->flatMap(fn (array $period) => $this->buildPeriodObservations(
            $lineageTip,
            $lineageContracts,
            $lineageIdentity,
            $templateInterpretation->id,
            $templateSnapshot->id,
            $period,
        ));
        $stats['observations_built'] = $observations->count();
        $stats['curve_unavailable_rows'] = $observations
            ->where('reference_kind', 'curve_unavailable')
            ->count();
        $stats['vat_unknown_rows'] = $observations
            ->whereIn('vat_basis', ['unknown', 'mixed'])
            ->count();
        $stats['discount_unresolved_rows'] = $observations
            ->filter(fn (array $row) => in_array('discount_effect_unresolved', $row['quality_flags'], true))
            ->count();

        return ['observations' => $observations->values(), 'stats' => $stats];
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'daily_snapshots_seen' => 0,
            'daily_snapshots_accepted' => 0,
            'ambiguous_daily_snapshots' => 0,
            'incomplete_daily_snapshots' => 0,
            'lineage_overlap_conflicts' => 0,
            'uncalibrated_lineages' => 0,
            'unsupported_lineages' => 0,
            'periods_reconstructed' => 0,
            'observations_built' => 0,
            'curve_unavailable_rows' => 0,
            'vat_unknown_rows' => 0,
            'discount_unresolved_rows' => 0,
        ];
    }

    private function isSupportedHistoricalModel(ElectricityContract $tip): bool
    {
        if (in_array($tip->pricing_model, ['Spot', 'Hybrid'], true)) {
            return true;
        }

        if ($tip->pricing_model !== 'FixedPrice') {
            return false;
        }

        if (($tip->canonical_pricing['recurring_schedule']['present'] ?? false) === true) {
            return true;
        }

        return $this->fixedTermDuration($tip) !== null;
    }

    private function fixedTermDuration(ElectricityContract $tip): ?int
    {
        if ($tip->contract_type !== 'FixedTerm') {
            return null;
        }

        $duration = $tip->publishedInterpretation?->output['classification']['fixed_duration_months'] ?? null;
        $expected = match ($tip->fixed_time_range) {
            'Fixed6' => 6,
            'Fixed12' => 12,
            'Fixed24' => 24,
            default => null,
        };

        return is_int($duration) && $duration === $expected ? $duration : null;
    }

    private function isCompatibleAncestor(ElectricityContract $tip, ElectricityContract $candidate): bool
    {
        foreach (['company_name', 'pricing_model', 'contract_type', 'metering', 'target_group'] as $field) {
            if ($tip->{$field} !== $candidate->{$field}) {
                return false;
            }
        }

        return $tip->contract_type !== 'FixedTerm'
            || $tip->fixed_time_range === $candidate->fixed_time_range;
    }

    /**
     * @return array{raw_roles: array<string, array{role: string, vat_status: string}>, required_energy_roles: list<string>}|array{}
     */
    private function calibrateRoleMap(ElectricityContract $tip): array
    {
        $canonicalByRole = collect($tip->canonical_pricing['phases'] ?? [])
            ->flatMap(fn ($phase) => is_array($phase['components'] ?? null) ? $phase['components'] : [])
            ->filter(fn ($component) => is_array($component)
                && in_array($component['source_kind'] ?? null, ['structured', 'both'], true)
                && collect($component['evidence'] ?? [])->contains(
                    fn ($evidence) => is_array($evidence)
                        && is_string($evidence['source'] ?? null)
                        && str_starts_with($evidence['source'], 'components['),
                ))
            ->groupBy('component_type');
        $requiredEnergyRoles = $canonicalByRole->keys()
            ->filter(fn (string $role) => $role === 'spot_margin' || str_starts_with($role, 'energy_'))
            ->values();
        $latestDate = $tip->priceComponents()->max('price_date');

        if ($latestDate === null || $requiredEnergyRoles->isEmpty()) {
            return [];
        }

        $latestDate = CarbonImmutable::parse($latestDate)->toDateString();
        $latestRows = $tip->priceComponents()
            ->whereDate('price_date', $latestDate)
            ->get()
            ->filter(fn (PriceComponent $row) => $this->hasGeneralFuseScope($row));
        $mapping = [];

        foreach ($latestRows->groupBy(fn (PriceComponent $row) => $this->rawRoleKey($row)) as $rawKey => $roleRows) {
            $rawType = (string) $roleRows->first()->price_component_type;
            $role = $this->roleForRawType($tip, $rawType);
            $canonical = $canonicalByRole->get($role, collect())
                ->filter(fn (array $component) => ($component['unit'] ?? 'unknown') === $this->normalizedPaymentUnit(
                    $roleRows->first()->payment_unit,
                ));

            if ($role === null || $canonical->isEmpty()) {
                continue;
            }

            $canonicalValues = $canonical
                ->flatMap(fn (array $component) => [$component['amount'] ?? null, $component['normal_amount'] ?? null])
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (float) $value)
                ->unique();
            $matches = $roleRows->contains(fn (PriceComponent $row) => $canonicalValues->contains(
                fn (float $value) => abs($value - (float) $row->price) < 0.0001,
            ));

            if (! $matches) {
                continue;
            }

            $vatStatuses = $canonical
                ->pluck('vat_status')
                ->filter(fn ($status) => in_array($status, ['included', 'excluded', 'unknown'], true))
                ->unique()
                ->values();
            $mapping[(string) $rawKey] = [
                'role' => $role,
                'vat_status' => $vatStatuses->count() === 1 ? $vatStatuses->first() : 'unknown',
            ];
        }

        $mappedEnergyRoles = collect($mapping)->pluck('role')->unique();
        $hasUnmappedCurrentRole = $latestRows->contains(fn (PriceComponent $row) => $this->isRecognizedRawType(
            $row->price_component_type,
        ) && ! isset($mapping[$this->rawRoleKey($row)]));

        if ($hasUnmappedCurrentRole || $requiredEnergyRoles->diff($mappedEnergyRoles)->isNotEmpty()) {
            return [];
        }

        return [
            'raw_roles' => $mapping,
            'required_energy_roles' => $requiredEnergyRoles->all(),
        ];
    }

    private function roleForRawType(ElectricityContract $tip, string $rawType): ?string
    {
        if ($tip->pricing_model === 'Spot'
            && in_array($rawType, ['Spot', 'General', 'DayTime', 'NightTime', 'SeasonalWinter', 'SeasonalWinterDay', 'SeasonalOther'], true)) {
            return 'spot_margin';
        }

        return self::RAW_ROLE_MAP[$rawType] ?? null;
    }

    private function isRecognizedRawType(string $rawType): bool
    {
        return $rawType === 'Spot' || array_key_exists($rawType, self::RAW_ROLE_MAP);
    }

    private function hasGeneralFuseScope(PriceComponent $row): bool
    {
        return $row->fuse_size === null
            || trim((string) $row->fuse_size) === ''
            || mb_strtolower((string) $row->fuse_size) === 'any';
    }

    private function rawRoleKey(PriceComponent $row): string
    {
        return $row->price_component_type.'|'.$this->normalizedPaymentUnit($row->payment_unit);
    }

    private function normalizedPaymentUnit(?string $unit): string
    {
        return match (mb_strtolower((string) $unit)) {
            'eurpermonth' => 'eur_per_month',
            'centperkiwatthour', 'centperkilowatthour' => 'cents_per_kwh',
            default => 'unknown',
        };
    }

    /**
     * @param  Collection<int, PriceComponent>  $rows
     * @param  array<string, array{role: string, vat_status: string}>  $roleMap
     * @return array<string, mixed>|null
     */
    private function normalizeCarrierDay(Collection $rows, array $roleMap): ?array
    {
        $generalRows = $rows->filter(fn (PriceComponent $row) => $this->hasGeneralFuseScope($row));
        $hasUnmappedRecognizedRow = $generalRows->contains(fn (PriceComponent $row) => $this->isRecognizedRawType(
            $row->price_component_type,
        ) && ! isset($roleMap[$this->rawRoleKey($row)]));

        if ($hasUnmappedRecognizedRow) {
            return null;
        }

        $mapped = $generalRows
            ->filter(fn (PriceComponent $row) => isset($roleMap[$this->rawRoleKey($row)]))
            ->map(function (PriceComponent $row) use ($roleMap) {
                $mapping = $roleMap[$this->rawRoleKey($row)];
                $discount = $row->has_discount ? [
                    'value' => $row->discount_value,
                    'is_percentage' => $row->discount_is_percentage,
                    'type' => $row->discount_type,
                    'first_kwh' => $row->discount_discount_n_first_kwh,
                    'first_months' => $row->discount_discount_n_first_months,
                    'until_date' => $row->discount_discount_until_date?->toDateString(),
                ] : null;

                return [
                    'id' => (string) $row->id,
                    'raw_type' => $row->price_component_type,
                    'role' => $mapping['role'],
                    'price' => (float) $row->price,
                    'payment_unit' => $row->payment_unit,
                    'vat_status' => $mapping['vat_status'],
                    'discount' => $discount,
                ];
            })
            ->values();
        $normalized = collect();

        foreach ($mapped->groupBy('role') as $role => $roleRows) {
            $variants = $roleRows
                ->groupBy(fn (array $row) => json_encode([
                    'role' => $row['role'],
                    'price' => $row['price'],
                    'payment_unit' => $row['payment_unit'],
                    'discount' => $row['discount'],
                ], JSON_THROW_ON_ERROR));

            if ($variants->count() !== 1) {
                return null;
            }

            $variantRows = $variants->first();
            $first = $variantRows->first();
            $normalized->put($role, $first + [
                'raw_types' => $variantRows->pluck('raw_type')->unique()->sort()->values()->all(),
                'ids' => $variantRows->pluck('id')->unique()->sort()->values()->all(),
            ]);
        }

        $energyComponents = $normalized->filter(fn (array $component, string $role) => $role !== 'monthly_fee');
        $monthlyFee = $normalized->get('monthly_fee');
        $flags = collect(['historical_relational_components', 'vat_mapping_propagated_to_history']);

        if ($energyComponents->contains(fn (array $component) => $component['discount'] !== null)
            || ($monthlyFee['discount'] ?? null) !== null) {
            $flags->push('discount_effect_unresolved');
        }

        if ($monthlyFee === null) {
            $flags->push('monthly_fee_missing');
        }

        $signature = hash('sha256', json_encode(
            $normalized->map(fn (array $component) => [
                'role' => $component['role'],
                'price' => $component['price'],
                'payment_unit' => $component['payment_unit'],
                'vat_status' => $component['vat_status'],
                'discount' => $component['discount'],
            ])->sortKeys()->all(),
            JSON_THROW_ON_ERROR,
        ));

        return [
            'signature' => $signature,
            'components' => $normalized,
            'energy_components' => $energyComponents,
            'monthly_fee' => $monthlyFee,
            'quality_flags' => $flags->values()->all(),
            'component_ids' => $normalized->pluck('ids')->flatten()->unique()->sort()->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $states
     * @return Collection<int, array<string, mixed>>
     */
    private function compressPeriods(Collection $states): Collection
    {
        $periods = collect();
        $current = null;

        foreach ($states->sortBy('date') as $state) {
            $continues = $current !== null
                && $current['signature'] === $state['signature']
                && $current['last_observed']->addDay()->isSameDay($state['date']);

            if (! $continues) {
                if ($current !== null) {
                    $periods->push($current);
                }

                $current = $state + [
                    'first_observed' => $state['date'],
                    'last_observed' => $state['date'],
                    'first_carrier_id' => $state['carrier_ids'][0],
                ];

                continue;
            }

            $current['last_observed'] = $state['date'];
            $current['carrier_ids'] = collect($current['carrier_ids'])
                ->concat($state['carrier_ids'])->unique()->sort()->values()->all();
            $current['component_ids'] = collect($current['component_ids'])
                ->concat($state['component_ids'])->unique()->sort()->values()->all();
            $current['quality_flags'] = collect($current['quality_flags'])
                ->concat($state['quality_flags'])->unique()->values()->all();
        }

        if ($current !== null) {
            $periods->push($current);
        }

        return $periods;
    }

    /**
     * @param  Collection<string, ElectricityContract>  $lineageContracts
     * @param  array{key: string, contract_ids: list<string>, root_ids: list<string>}  $lineageIdentity
     * @param  array<string, mixed>  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function buildPeriodObservations(
        ElectricityContract $tip,
        Collection $lineageContracts,
        array $lineageIdentity,
        int $templateInterpretationId,
        int $templateSnapshotId,
        array $period,
    ): Collection {
        $carrier = $lineageContracts->get($period['first_carrier_id'], $tip);
        $energyComponents = $period['energy_components'];
        $monthlyComponent = $period['monthly_fee'];
        $monthlyFee = $monthlyComponent !== null && $monthlyComponent['discount'] === null
            ? (float) $monthlyComponent['price']
            : null;
        $quality = 'inferred';
        $references = collect();

        if ($tip->pricing_model === 'Spot') {
            $quality = 'exact';
            $references = collect(['spot_disclosed' => [
                'reference_kind' => 'spot_disclosed',
                'trade_date' => null,
                'price_cents_per_kwh_including_vat' => null,
                'price_cents_per_kwh_excluding_vat' => null,
                'metadata' => ['source' => 'historical_relational_component'],
            ]]);
        } elseif ($tip->pricing_model === 'Hybrid') {
            $quality = 'not_comparable';
            $references = collect(['hybrid_base' => [
                'reference_kind' => 'hybrid_base',
                'trade_date' => null,
                'price_cents_per_kwh_including_vat' => null,
                'price_cents_per_kwh_excluding_vat' => null,
                'metadata' => ['reason' => 'consumption_effect_not_comparable'],
            ]]);
        } elseif (($tip->canonical_pricing['recurring_schedule']['present'] ?? false) === true) {
            $references = $this->referencePriceService->forDeliveryMonth(
                $period['first_observed'],
                $period['first_observed'],
            );
        } elseif (($duration = $this->fixedTermDuration($tip)) !== null) {
            $references = $this->referencePriceService->forDeliveryTerm($period['first_observed'], $duration);
        }

        if ($references->isEmpty()) {
            $references = collect(['curve_unavailable' => [
                'reference_kind' => 'curve_unavailable',
                'trade_date' => null,
                'price_cents_per_kwh_including_vat' => null,
                'price_cents_per_kwh_excluding_vat' => null,
                'metadata' => ['reason' => 'no_prior_futures_vintage'],
            ]]);
        }

        return $energyComponents->flatMap(function (array $energyComponent, string $role) use (
            $tip,
            $carrier,
            $lineageIdentity,
            $templateInterpretationId,
            $templateSnapshotId,
            $period,
            $monthlyComponent,
            $monthlyFee,
            $quality,
            $references,
        ) {
            $vatBasis = $this->combinedVatBasis($energyComponent, $monthlyComponent);
            $hasUsableVatBasis = in_array($vatBasis, ['included', 'excluded'], true);
            $discountUnresolved = $energyComponent['discount'] !== null;
            $retailPrice = (float) $energyComponent['price'];
            $priceSignature = hash('sha256', implode('|', [
                $period['signature'],
                $role,
                self::METHOD_VERSION,
            ]));
            $observationKey = hash('sha256', implode('|', [
                $lineageIdentity['key'],
                $period['first_observed']->toDateString(),
                $priceSignature,
            ]));

            return $references->map(function (array $reference) use (
                $tip,
                $carrier,
                $lineageIdentity,
                $templateInterpretationId,
                $templateSnapshotId,
                $period,
                $monthlyFee,
                $quality,
                $role,
                $vatBasis,
                $discountUnresolved,
                $hasUsableVatBasis,
                $retailPrice,
                $priceSignature,
                $observationKey,
            ) {
                $referenceKind = $reference['reference_kind'];
                $referencePrice = $quality === 'inferred' && ! $discountUnresolved
                    ? $this->referencePriceService->priceForVatBasis($reference, $vatBasis)
                    : null;
                $premium = match ($quality) {
                    'exact' => $discountUnresolved || ! $hasUsableVatBasis ? null : $retailPrice,
                    'inferred' => $referencePrice === null ? null : $retailPrice - $referencePrice,
                    default => null,
                };
                $premiumWithFee = $premium !== null && $monthlyFee !== null
                    ? $premium + ($monthlyFee * 12 * 100 / RetailPremiumObservationService::REFERENCE_CONSUMPTION_KWH)
                    : null;
                $flags = collect($period['quality_flags']);

                if ($quality === 'not_comparable') {
                    $flags->push('hybrid_consumption_effect_not_comparable');
                }

                if ($period['energy_components']->count() > 1) {
                    $flags->push('multi_rate_component_not_aggregated');
                }

                if ($referenceKind === 'curve_unavailable') {
                    $flags->push('reference_curve_unavailable');
                }

                if ($vatBasis === 'unknown') {
                    $flags->push('vat_unknown');
                } elseif ($vatBasis === 'mixed') {
                    $flags->push('vat_mixed');
                }

                if ($retailPrice <= 0) {
                    $flags->push($quality === 'exact'
                        ? 'zero_or_negative_retail_premium'
                        : 'zero_or_negative_retail_energy_price');
                }

                return [
                    'observation_key' => $observationKey,
                    'price_signature' => $priceSignature,
                    'lineage_key' => $lineageIdentity['key'],
                    'lineage_contract_id' => $tip->id,
                    'contract_id' => $carrier->id,
                    'published_interpretation_id' => null,
                    'source_snapshot_id' => null,
                    'company_name' => $carrier->company_name,
                    'pricing_model' => $carrier->pricing_model,
                    'cadence' => $tip->canonical_pricing['recurring_schedule']['cadence'] ?? null,
                    'contract_type' => $carrier->contract_type,
                    'target_group' => $carrier->target_group,
                    'metering' => $carrier->metering,
                    'phase_index' => 0,
                    'phase_kind' => 'historical_observed',
                    'phase_label' => 'Observed relational price period',
                    'period_start' => null,
                    'period_end' => null,
                    'first_observed_date' => $period['first_observed']->toDateString(),
                    'last_observed_date' => $period['last_observed']->toDateString(),
                    'energy_components' => $this->storedComponents($period['components']),
                    'energy_component_type' => $role,
                    'retail_energy_price_cents_per_kwh' => $quality === 'exact' ? null : $retailPrice,
                    'monthly_fee_eur' => $monthlyFee,
                    'vat_basis' => $vatBasis,
                    'reference_consumption_kwh' => RetailPremiumObservationService::REFERENCE_CONSUMPTION_KWH,
                    'reference_kind' => $referenceKind,
                    'reference_trade_date' => $reference['trade_date'],
                    'reference_price_cents_per_kwh' => $referencePrice,
                    'retail_premium_cents_per_kwh' => $premium,
                    'retail_premium_with_fee_cents_per_kwh' => $premiumWithFee,
                    'method_version' => self::METHOD_VERSION,
                    'quality' => $quality,
                    'quality_flags' => $flags->unique()->values()->all(),
                    'source_metadata' => [
                        'boundary_provenance' => 'observed_import_dates',
                        'lineage_contract_ids' => $lineageIdentity['contract_ids'],
                        'lineage_root_ids' => $lineageIdentity['root_ids'],
                        'period_carrier_ids' => $period['carrier_ids'],
                        'price_component_ids' => $period['component_ids'],
                        'template_interpretation_id' => $templateInterpretationId,
                        'template_source_snapshot_id' => $templateSnapshotId,
                        'duration_months' => $this->fixedTermDuration($tip),
                        'vat_provenance' => 'active_tip_canonical_mapping',
                        'vat_multiplier' => (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255),
                        'reference' => $reference['metadata'] ?? null,
                    ],
                ];
            })->values();
        })->values();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    private function storedComponents(Collection $components): array
    {
        return $components->map(fn (array $component) => [
            'component_type' => $component['role'],
            'amount' => $component['price'],
            'normal_amount' => null,
            'unit' => $component['payment_unit'],
            'vat_status' => $component['vat_status'],
            'price_role' => 'historical_observed',
            'source_kind' => 'structured',
            'raw_component_types' => $component['raw_types'],
            'discount' => $component['discount'],
        ])->values()->all();
    }

    private function combinedVatBasis(array $energyComponent, ?array $monthlyComponent): string
    {
        $statuses = collect([
            $energyComponent['vat_status'] ?? 'unknown',
            $monthlyComponent['vat_status'] ?? null,
        ])->filter()->unique()->values();

        if ($statuses->count() !== 1) {
            return 'mixed';
        }

        return in_array($statuses->first(), ['included', 'excluded'], true)
            ? $statuses->first()
            : 'unknown';
    }
}
