<?php

namespace App\Services\ContractStatistics;

use App\Models\ContractHistoricalInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\CurrentSourcePromotionEvidence;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationProfile;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\HistoricalContractEpisodeBuilder;
use App\Services\ContractInterpretation\HistoricalEvidenceNormalizer;
use App\Services\ContractInterpretation\HistoricalInterpretationFingerprint;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use App\Services\ContractStatistics\DTO\SourceInterpretationProvenance;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AsOfAnnualCostEvidenceResolver
{
    private const TIMEZONE = 'Europe/Helsinki';

    private const CONSUMPTIONS = [2000, 5000, 18000];

    public function __construct(
        private readonly CanonicalPricingParser $parser,
        private readonly HistoricalInterpretationFingerprint $historicalFingerprints,
        private readonly HistoricalEvidenceNormalizer $historicalNormalizer,
        private readonly ContractInterpretationInputBuilder $inputBuilder,
        private readonly ContractInterpretationValidator $validator,
    ) {}

    /**
     * Resolve all requested dates with one query per evidence table.
     *
     * The anchor caller can restrict contracts and prefer same-date observed snapshot identity.
     * Ordinary annual evidence keeps its strict unique-snapshot default.
     *
     * @param  iterable<CarbonInterface|string>  $dates
     * @param  list<string>|null  $contractIds
     * @return array<string, array<string, AsOfAnnualCostEvidence>>
     */
    public function resolveForDates(iterable $dates, AnnualCostMethodVersion $methodVersion = AnnualCostMethodVersion::AsOf, ?array $contractIds = null, bool $preferObservedSnapshots = false): array
    {
        $targets = collect($dates)
            ->map(fn (CarbonInterface|string $date): CarbonImmutable => $this->date($date))
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->sort()
            ->values();

        if ($targets->isEmpty()) {
            return [];
        }

        $dateStrings = $targets->map->toDateString()->all();
        $snapshots = DB::table('contract_price_snapshots')
            ->whereIn(DB::raw('DATE(snapshot_date)'), $dateStrings)
            ->when($contractIds !== null, fn ($query) => $query->whereIn('contract_id', $contractIds))
            ->orderBy('snapshot_date')
            ->orderBy('contract_id')
            ->get([
                'id',
                'snapshot_date',
                'contract_id',
                'company_name',
                'contract_name',
                'pricing_model',
                'contract_type',
                'fixed_time_range',
                'metering',
                'segment_key',
                'pricing_basis',
                'has_discount',
                'includes_spot_price',
                'annual_cost_2000_kwh',
                'annual_cost_5000_kwh',
                'annual_cost_18000_kwh',
            ]);
        $snapshotsByDateContract = $snapshots->groupBy(
            fn (object $row): string => $this->dateString($row->snapshot_date).'|'.$row->contract_id,
        );

        $componentRows = DB::table('price_components')
            ->whereIn(DB::raw('DATE(price_date)'), $dateStrings)
            ->when($contractIds !== null, fn ($query) => $query->whereIn('electricity_contract_id', $contractIds))
            ->orderBy('price_date')
            ->orderBy('electricity_contract_id')
            ->orderBy('id')
            ->get([
                'id',
                'price_date',
                'electricity_contract_id',
                'price_component_type',
                'fuse_size',
                'payment_unit',
                'price',
                'has_discount',
                'discount_value',
                'discount_is_percentage',
                'discount_type',
                'discount_discount_n_first_kwh',
                'discount_discount_n_first_months',
                'discount_discount_until_date',
            ]);
        $componentsByDateContract = $componentRows->groupBy(
            fn (object $row): string => $this->dateString($row->price_date).'|'.$row->electricity_contract_id,
        );

        $contractIdsByDate = array_fill_keys($dateStrings, []);
        foreach ($snapshots as $snapshot) {
            $contractIdsByDate[$this->dateString($snapshot->snapshot_date)][(string) $snapshot->contract_id] = true;
        }
        foreach ($componentRows as $component) {
            $contractIdsByDate[$this->dateString($component->price_date)][(string) $component->electricity_contract_id] = true;
        }

        $snapshotContractIds = $snapshots->pluck('contract_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
        $minimumStart = $targets->first()->startOfDay()->utc();
        $maximumEnd = $targets->last()->endOfDay()->utc();
        $observations = $snapshotContractIds === []
            ? collect()
            : DB::table('contract_source_observations')
                ->whereIn('contract_id', $snapshotContractIds)
                ->where('first_observed_at', '<=', $maximumEnd->format('Y-m-d H:i:s'))
                ->where('last_observed_at', '>=', $minimumStart->format('Y-m-d H:i:s'))
                ->orderBy('contract_id')
                ->orderBy('first_observed_at')
                ->get(['id', 'contract_id', 'source_snapshot_id', 'first_observed_at', 'last_observed_at'])
                ->groupBy('contract_id');

        $sourceSnapshotIds = $observations->flatten(1)->pluck('source_snapshot_id')->unique()->values()->all();
        $sourcePayloads = $sourceSnapshotIds === []
            ? collect()
            : DB::table('contract_source_snapshots')
                ->whereIn('id', $sourceSnapshotIds)
                ->get(['id', 'contract_id', 'source_payload'])
                ->keyBy('id');
        $interpretations = $sourceSnapshotIds === []
            ? collect()
            : DB::table('contract_interpretations')
                ->whereIn('source_snapshot_id', $sourceSnapshotIds)
                ->whereNotNull('completed_at')
                ->when($methodVersion !== AnnualCostMethodVersion::AsOfV3,
                    fn ($query) => $query->where('completed_at', '<=', $maximumEnd->format('Y-m-d H:i:s')))
                ->orderBy('source_snapshot_id')
                ->orderBy('completed_at')
                ->orderBy('id')
                ->get([
                    'id',
                    'contract_id',
                    'source_snapshot_id',
                    'analysis_source_observation_id',
                    'schema_version',
                    'prompt_version',
                    'validator_version',
                    'status',
                    'output',
                    'validation_errors',
                    'completed_at',
                ])
                ->groupBy('source_snapshot_id');

        $historicalEpisodes = $snapshotContractIds === []
            ? collect()
            : DB::table('contract_historical_interpretation_episodes')
                ->whereIn('contract_id', $snapshotContractIds)
                ->where('builder_version', HistoricalContractEpisodeBuilder::VERSION)
                ->whereDate('episode_start', '<=', $targets->last()->toDateString())
                ->whereDate('episode_end', '>=', $targets->first()->toDateString())
                ->orderBy('contract_id')
                ->orderBy('episode_start')
                ->orderBy('id')
                ->get([
                    'id',
                    'contract_id',
                    'episode_start',
                    'episode_end',
                    'builder_version',
                    'episode_fingerprint',
                    'evidence_fingerprint',
                    'manifest_fingerprint',
                    'evidence_grade',
                    'analysis_input',
                    'evidence_manifest',
                ])
                ->groupBy('contract_id');
        $historicalEpisodeIds = $historicalEpisodes->flatten(1)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $historicalInterpretations = $historicalEpisodeIds === []
            ? collect()
            : DB::table('contract_historical_interpretations')
                ->whereIn('episode_id', $historicalEpisodeIds)
                ->orderBy('episode_id')
                ->orderBy('id')
                ->get([
                    'id',
                    'episode_id',
                    'contract_id',
                    'analysis_fingerprint',
                    'status',
                    'schema_version',
                    'prompt_version',
                    'historical_addendum_version',
                    'validator_version',
                    'parser_version',
                    'provider',
                    'model',
                    'reasoning_effort',
                    'output',
                    'validation_errors',
                    'completed_at',
                ])
                ->groupBy('episode_id');

        $resolved = array_fill_keys($dateStrings, []);
        foreach ($targets as $target) {
            $dateString = $target->toDateString();
            $contractIds = array_keys($contractIdsByDate[$dateString]);
            sort($contractIds);

            foreach ($contractIds as $contractId) {
                $key = $dateString.'|'.$contractId;
                $snapshotRows = $snapshotsByDateContract->get($key, collect());
                if ($preferObservedSnapshots && $snapshotRows->contains('pricing_basis', ContractPriceBasis::ObservedSellerData->value)) {
                    $snapshotRows = $snapshotRows->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value);
                }
                $snapshot = $snapshotRows->count() === 1 ? $snapshotRows->first() : null;
                $rawComponents = $componentsByDateContract->get($key, collect());

                if ($snapshot === null) {
                    $reason = $snapshotRows->isEmpty()
                        ? 'missing_historical_snapshot_identity'
                        : 'ambiguous_historical_snapshot_identity';
                    $resolved[$dateString][$contractId] = $this->excludedEvidence(
                        $contractId,
                        $target,
                        $rawComponents,
                        $reason,
                    );

                    continue;
                }

                $basis = ContractPriceBasis::tryFrom((string) $snapshot->pricing_basis);
                if ($basis === null) {
                    $resolved[$dateString][$contractId] = $this->excludedEvidence(
                        $contractId,
                        $target,
                        $rawComponents,
                        'invalid_historical_snapshot_pricing_basis',
                        (int) $snapshot->id,
                    );

                    continue;
                }

                $selection = $this->canonicalEvidence(
                    $contractId,
                    $target,
                    $snapshot,
                    $rawComponents,
                    $observations->get($contractId, collect()),
                    $interpretations,
                    $historicalEpisodes->get($contractId, collect()),
                    $historicalInterpretations,
                    $methodVersion,
                    $sourcePayloads,
                );
                [$canonical, $sourceIds, $flags] = $selection;

                $sourceIds = [
                    'price_snapshot_id' => (int) $snapshot->id,
                    'price_component_ids' => $rawComponents->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                    ...$sourceIds,
                ];

                $source = $sourcePayloads->get($sourceIds['source_snapshot_id']);
                $payload = $source !== null && (string) $source->contract_id === $contractId
                    ? json_decode((string) $source->source_payload, true)
                    : null;
                $exclusionReason = in_array('historical_energy_rule_source_validation_unavailable', $flags, true)
                    ? 'historical_energy_rule_source_validation_unavailable' : null;
                if ($methodVersion === AnnualCostMethodVersion::AsOfV3 && $canonical !== null
                    && $sourceIds['source_snapshot_id'] !== null && ! is_array($payload)) {
                    $exclusionReason = 'historical_promotion_source_payload_unavailable';
                    $flags[] = $exclusionReason;
                }
                if ($methodVersion === AnnualCostMethodVersion::AsOfV3 && $canonical !== null && is_array($payload)) {
                    $canonical = $canonical->withComparisonEvidence(sourceCampaignEnergyRates: CurrentSourcePromotionEvidence::campaignRatesFromPayload($payload));
                }
                $details = is_array($payload['Details'] ?? null) ? $payload['Details'] : [];
                $limits = $details['ConsumptionLimitation'] ?? null;
                $eligibilityProven = is_array($limits)
                    && array_key_exists('MinXKWhPerY', $limits)
                    && array_key_exists('MaxXKWhPerY', $limits)
                    && $this->validConsumptionBound($limits['MinXKWhPerY'])
                    && $this->validConsumptionBound($limits['MaxXKWhPerY'])
                    && ($limits['MinXKWhPerY'] === null || $limits['MaxXKWhPerY'] === null
                        || $limits['MinXKWhPerY'] <= $limits['MaxXKWhPerY']);

                $resolved[$dateString][$contractId] = new AsOfAnnualCostEvidence(
                    contractId: $contractId,
                    date: $target,
                    companyName: $snapshot->company_name !== null ? (string) $snapshot->company_name : null,
                    segmentKey: (string) $snapshot->segment_key,
                    pricingModel: (string) ($snapshot->pricing_model ?? ''),
                    contractType: (string) ($snapshot->contract_type ?? ''),
                    fixedTimeRange: $snapshot->fixed_time_range !== null ? (string) $snapshot->fixed_time_range : null,
                    metering: $snapshot->metering !== null ? (string) $snapshot->metering : null,
                    pricingBasis: $basis,
                    priceComponents: $this->normalizeComponents($rawComponents),
                    consumptionAvailability: [
                        2000 => $snapshot->annual_cost_2000_kwh !== null,
                        5000 => $snapshot->annual_cost_5000_kwh !== null,
                        18000 => $snapshot->annual_cost_18000_kwh !== null,
                    ],
                    canonicalData: $canonical,
                    sourceEvidenceIds: $sourceIds,
                    provenanceFlags: $flags,
                    exclusionReason: $exclusionReason,
                    sourceInterpretationProvenance: $selection[3] ?? null,
                    consumptionEligibilityProven: $eligibilityProven,
                    minimumAnnualConsumptionKwh: $eligibilityProven && $limits['MinXKWhPerY'] !== null ? (int) $limits['MinXKWhPerY'] : null,
                    maximumAnnualConsumptionKwh: $eligibilityProven && $limits['MaxXKWhPerY'] !== null ? (int) $limits['MaxXKWhPerY'] : null,
                    householdAudienceConflict: ($details['TargetGroup'] ?? null) === 'Company',
                );
            }
        }

        return $resolved;
    }

    private function validConsumptionBound(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 0);
    }

    /** @return array<string, AsOfAnnualCostEvidence> */
    public function resolveDate(CarbonInterface|string $date, AnnualCostMethodVersion $methodVersion = AnnualCostMethodVersion::AsOf): array
    {
        $target = $this->date($date);

        return $this->resolveForDates([$target], $methodVersion)[$target->toDateString()] ?? [];
    }

    /**
     * @param  Collection<int, object>  $rawComponents
     * @param  Collection<int, object>  $contractObservations
     * @param  Collection<int|string, Collection<int, object>>  $interpretations
     * @param  Collection<int, object>  $historicalEpisodes
     * @param  Collection<int|string, Collection<int, object>>  $historicalInterpretations
     * @return array{0: CanonicalContractData|null, 1: array{price_snapshot_id?: int|null, price_component_ids?: list<string>, observation_ids: list<int>, source_snapshot_id: int|null, interpretation_id: int|null, historical_episode_id: int|null, historical_interpretation_id: int|null, historical_evidence_grade: string|null}, 2: list<string>, 3?: SourceInterpretationProvenance|null}
     */
    private function canonicalEvidence(
        string $contractId,
        CarbonImmutable $target,
        object $priceSnapshot,
        Collection $rawComponents,
        Collection $contractObservations,
        Collection $interpretations,
        Collection $historicalEpisodes,
        Collection $historicalInterpretations,
        AnnualCostMethodVersion $methodVersion,
        Collection $sourcePayloads,
    ): array {
        $startUtc = $target->startOfDay()->utc();
        $endUtc = $target->endOfDay()->utc();
        $covering = $contractObservations->filter(function (object $row) use ($startUtc, $endUtc): bool {
            $first = CarbonImmutable::parse((string) $row->first_observed_at, 'UTC');
            $last = CarbonImmutable::parse((string) $row->last_observed_at, 'UTC');

            return $first->lessThanOrEqualTo($endUtc) && $last->greaterThanOrEqualTo($startUtc);
        })->values();

        $observationIds = $covering->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $sourceSnapshotIds = $covering->pluck('source_snapshot_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $ids = [
            'observation_ids' => $observationIds,
            'source_snapshot_id' => $sourceSnapshotIds->count() === 1 ? $sourceSnapshotIds->first() : null,
            'interpretation_id' => null,
            'historical_episode_id' => null,
            'historical_interpretation_id' => null,
            'historical_evidence_grade' => null,
        ];

        if ($covering->isEmpty()) {
            return $this->historicalCanonicalEvidence(
                $contractId,
                $target,
                $priceSnapshot,
                $rawComponents,
                $historicalEpisodes,
                $historicalInterpretations,
                $ids,
            );
        }
        if ($sourceSnapshotIds->count() !== 1) {
            return [null, $ids, ['canonical_omitted_ambiguous_covering_source_snapshots']];
        }

        $sourceSnapshotId = (int) $sourceSnapshotIds->first();
        $isV3 = $methodVersion === AnnualCostMethodVersion::AsOfV3;
        if ($isV3 && (string) ($sourcePayloads->get($sourceSnapshotId)->contract_id ?? '') !== $contractId) {
            return [null, $ids, ['canonical_omitted_source_ownership_mismatch']];
        }
        $valid = [];
        $sawInvalid = false;
        $missingRuleProof = false;
        $targetRejections = [];
        $targetValidationFlags = [];
        foreach ($interpretations->get($sourceSnapshotId, collect()) as $row) {
            $completedAt = CarbonImmutable::parse((string) $row->completed_at, 'UTC');
            if (! $isV3 && $completedAt->greaterThan($endUtc)) {
                continue;
            }
            if ((string) $row->contract_id !== $contractId
                || ! in_array((string) $row->status, ['published', 'superseded'], true)
                || ! $this->isEmptyJsonList($row->validation_errors)) {
                $sawInvalid = true;

                continue;
            }
            if ($row->analysis_source_observation_id !== null
                && ! in_array((int) $row->analysis_source_observation_id, $observationIds, true)) {
                $sawInvalid = true;

                continue;
            }

            $output = $this->jsonObject($row->output);
            try {
                $data = $this->parser->parse(
                    $this->arrayValue($output, 'pricing'),
                    $this->arrayValue($output, 'calculation'),
                    $this->arrayValue($output, 'source_consistency'),
                    withEnergyRules: false,
                );
            } catch (CanonicalPricingParseException) {
                $sawInvalid = true;
                $missingRuleProof = $missingRuleProof || ($isV3 && ($row->schema_version === 'schema-v5'
                    || CurrentSourcePromotionEvidence::requiresEnergyRuleProof($output['pricing'] ?? null)));

                continue;
            }

            if ($isV3 && ($row->schema_version === 'schema-v5'
                || CurrentSourcePromotionEvidence::requiresEnergyRuleProof($output['pricing'] ?? null))) {
                $missingRuleProof = true;

                continue;
            }
            if ($isV3) {
                $payload = $this->jsonObject($sourcePayloads->get($sourceSnapshotId)->source_payload);
                try {
                    $profile = ContractInterpretationProfile::stored($row->schema_version, $row->prompt_version, $row->validator_version);
                    $source = new ContractSourceSnapshot(['contract_id' => $contractId, 'source_payload' => $payload]);
                    $input = $this->inputBuilder->build($source, $target, $profile);
                    $errors = $this->validator->validate($output, $input, $profile);
                    if ($errors === [] && ! $this->hasSupportedTargetDates($output, $input)) {
                        $errors[] = 'Historical date boundaries lack exact source proof.';
                        $targetValidationFlags[] = 'historical_temporal_source_validation_unavailable';
                    }
                } catch (\InvalidArgumentException) {
                    $errors = ['Unsupported stored interpretation profile.'];
                    $targetValidationFlags[] = 'historical_stored_interpretation_profile_unavailable';
                }
                if ($errors !== []) {
                    $sawInvalid = true;
                    $targetRejections[] = (int) $row->id;

                    continue;
                }
            }

            $valid[] = ['row' => $row, 'data' => $data, 'completed_at' => $completedAt];
        }

        if ($targetRejections !== []) {
            sort($targetRejections);
            $ids['target_evidence_rejected_interpretation_ids'] = $targetRejections;
        }
        $targetFlags = $targetRejections !== []
            ? ['canonical_interpretation_rejected_by_exact_target_validation', ...array_unique($targetValidationFlags)] : [];

        if ($valid === []) {
            if ($missingRuleProof) {
                return [null, $ids, [...$targetFlags, 'historical_energy_rule_source_validation_unavailable']];
            }

            return [null, $ids, [...$targetFlags, $sawInvalid
                ? 'canonical_omitted_no_valid_interpretation_as_of_date'
                : 'canonical_omitted_no_interpretation_as_of_date']];
        }

        // Completion orders reconstructions, never economic applicability. V1/v2 were
        // already restricted to timely output above; v3 validates the exact target instead.
        usort($valid, fn (array $left, array $right): int => $right['completed_at']->getTimestamp() <=> $left['completed_at']->getTimestamp());
        $latestTimestamp = $valid[0]['completed_at']->getTimestamp();
        $latest = array_values(array_filter(
            $valid,
            fn (array $candidate): bool => $candidate['completed_at']->getTimestamp() === $latestTimestamp,
        ));
        if (count($latest) !== 1) {
            return [null, $ids, [...$targetFlags, 'canonical_omitted_ambiguous_interpretation_chronology']];
        }

        $ids['interpretation_id'] = (int) $latest[0]['row']->id;

        $flags = ['historical_household_statistics_scope_assumed', ...$targetFlags];
        $retrospective = $isV3 && $latest[0]['completed_at']->greaterThan($endUtc);
        $provenance = null;
        if ($isV3) {
            $binding = $latest[0]['row']->analysis_source_observation_id;
            $provenance = new SourceInterpretationProvenance(
                sourceSnapshotId: $sourceSnapshotId,
                observationIds: $observationIds,
                interpretationId: $ids['interpretation_id'],
                analysisObservationId: $binding !== null ? (int) $binding : null,
                targetDate: $target,
                completedAt: $latest[0]['completed_at'],
                retrospective: $retrospective,
            );
            if ($retrospective) {
                $flags[] = 'retrospective_exact_source_interpretation';
            }
            if ($binding === null) {
                $flags[] = 'exact_source_legacy_null_observation_binding';
            }
        }

        return [$latest[0]['data'], $ids, $flags, $provenance];
    }

    /**
     * Legacy validation checks discount coverage, but does not prove arbitrary dates
     * inferred from relative prose. Keep those cases unresolved, not date assumptions.
     * This runs only after full validation of the exact target input.
     */
    private function hasSupportedTargetDates(array $output, array $input): bool
    {
        foreach ($output['pricing']['phases'] ?? [] as $phase) {
            foreach (['starts', 'ends'] as $side) {
                $boundary = $phase[$side];
                if (in_array($boundary['kind'], ['contract_start', 'none', 'unknown', 'period_boundary'], true)
                    || ($side === 'starts' && $boundary['kind'] === 'after_months' && (int) $boundary['value'] === 0)) {
                    continue;
                }
                $supported = $boundary['kind'] === 'after_months'
                    && ($input['contract_type'] ?? null) === 'FixedTerm'
                    && in_array($input['fixed_time_range'] ?? null, ['Fixed6', 'Fixed12', 'Fixed24'], true)
                    && (int) $boundary['value'] === (int) substr($input['fixed_time_range'], 5);
                foreach ($input['components'] ?? [] as $component) {
                    $type = $this->validator->canonicalComponentTypeForSource($component['price_component_type'] ?? null, $input['pricing_model'] ?? null);
                    if (($component['has_discount'] ?? false) !== true
                        || ! in_array($type, array_column($phase['components'] ?? [], 'component_type'), true)) {
                        continue;
                    }
                    if ($boundary['kind'] === 'after_months'
                        && $component['discount_type'] === 'NFirstMonth'
                        && (int) $boundary['value'] === (int) $component['discount_n_first_months']) {
                        $supported = true;
                    }
                    if ($boundary['kind'] === 'date' && $component['discount_type'] === 'UntilDate') {
                        $until = substr((string) $component['discount_until_date'], 0, 10);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) === 1
                            && CarbonImmutable::createFromFormat('!Y-m-d', $until)->toDateString() === $until) {
                            $supported = $supported || $boundary['value'] === $until
                                || $boundary['value'] === CarbonImmutable::parse($until)->addDay()->toDateString()
                                || ($side === 'starts' && $boundary['value'] <= $input['analysis_date']
                                    && ($phase['ends']['value'] ?? null) === $until && $until >= $input['analysis_date']);
                        }
                    }
                }
                if ($boundary['kind'] === 'date') {
                    // Exact calendar dates in validated phase citations are allowed.
                    // A bare day/month or relative phrase cannot supply its own year.
                    $date = CarbonImmutable::parse($boundary['value']);
                    foreach ($phase['evidence'] ?? [] as $evidence) {
                        $quote = (string) ($evidence['quote'] ?? '');
                        $supported = $supported || str_contains($quote, $date->toDateString())
                            || preg_match('/(?<!\d)0?'.$date->day.'\.0?'.$date->month.'\.'.$date->year.'(?!\d)/u', $quote) === 1;
                    }
                }
                if (! $supported) {
                    return false;
                }
            }
        }

        // Calendar reset windows must also be explicit, not guessed at completion.
        foreach (['current_period_start', 'current_period_end'] as $key) {
            $value = $output['pricing']['recurring_schedule'][$key] ?? null;
            if ($value !== null) {
                $quotes = array_column($output['pricing']['recurring_schedule']['evidence'] ?? [], 'quote');
                $date = CarbonImmutable::parse($value);
                if (! collect($quotes)->contains(fn (string $quote): bool => str_contains($quote, $value)
                    || preg_match('/(?<!\d)0?'.$date->day.'\.0?'.$date->month.'\.'.$date->year.'(?!\d)/u', $quote) === 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, object>  $rawComponents
     * @param  Collection<int, object>  $episodes
     * @param  Collection<int|string, Collection<int, object>>  $interpretations
     * @param  array<string, mixed>  $ids
     * @return array{0: CanonicalContractData|null, 1: array<string, mixed>, 2: list<string>}
     */
    private function historicalCanonicalEvidence(
        string $contractId,
        CarbonImmutable $target,
        object $priceSnapshot,
        Collection $rawComponents,
        Collection $episodes,
        Collection $interpretations,
        array $ids,
    ): array {
        $flags = ['canonical_omitted_no_covering_source_observation'];
        $date = $target->toDateString();
        $covering = $episodes->filter(fn (object $episode): bool => $this->dateString($episode->episode_start) <= $date
            && $this->dateString($episode->episode_end) >= $date)->values();

        if ($covering->isEmpty()) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_no_covering_current_builder_episode']];
        }
        if ($covering->count() !== 1) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_ambiguous_covering_current_builder_episodes']];
        }

        $episode = $covering->first();
        $analysisInput = $this->jsonObject($episode->analysis_input);
        $manifest = $this->jsonObject($episode->evidence_manifest);
        $recomputedManifest = $this->historicalFingerprints->manifest($manifest);
        $recomputedEvidence = $this->historicalFingerprints->evidence($analysisInput, $manifest);
        $recomputedEpisode = $this->historicalFingerprints->episode(
            HistoricalContractEpisodeBuilder::VERSION,
            $contractId,
            $this->dateString($episode->episode_start),
            $this->dateString($episode->episode_end),
            $recomputedEvidence,
        );
        if ((string) $episode->contract_id !== $contractId
            || (string) $episode->manifest_fingerprint !== $recomputedManifest
            || (string) $episode->evidence_fingerprint !== $recomputedEvidence
            || (string) $episode->episode_fingerprint !== $recomputedEpisode) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_episode_fingerprint_mismatch']];
        }

        $targetManifest = collect(is_array($manifest['target_days'] ?? null) ? $manifest['target_days'] : [])
            ->filter(fn (mixed $day): bool => is_array($day) && ($day['date'] ?? null) === $date)
            ->values();
        $expectedComponents = $this->componentCompositeIdentities($rawComponents);
        $economicDigest = $this->historicalNormalizer->targetEconomicDigest($priceSnapshot, $rawComponents);
        $manifestMatches = $targetManifest->count() === 1
            && (int) ($targetManifest[0]['snapshot_id'] ?? 0) === (int) $priceSnapshot->id
            && is_array($targetManifest[0]['component_ids'] ?? null)
            && $this->sortedStrings($targetManifest[0]['component_ids']) === $expectedComponents
            && is_string($targetManifest[0]['economic_digest'] ?? null)
            && hash_equals($targetManifest[0]['economic_digest'], $economicDigest);
        if (! $manifestMatches) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_exact_target_manifest_mismatch']];
        }

        $episodeInterpretations = $interpretations->get((int) $episode->id, collect());
        if ($episodeInterpretations->isEmpty()) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_no_interpretation']];
        }

        $expectedAnalysisFingerprint = $this->historicalFingerprints->analysis((string) $episode->episode_fingerprint);
        $current = $episodeInterpretations->filter(
            fn (object $row): bool => $this->historicalVersionsMatch($row)
                && (string) $row->analysis_fingerprint === $expectedAnalysisFingerprint,
        )->values();
        if ($current->isEmpty()) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_stale_wrong_version_or_fingerprint_interpretation']];
        }
        if ($current->count() !== 1) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_ambiguous_current_interpretations']];
        }

        $interpretation = $current->first();
        if ((string) $interpretation->contract_id !== $contractId
            || (int) $interpretation->episode_id !== (int) $episode->id) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_interpretation_ownership_mismatch']];
        }
        if ((string) $interpretation->status !== ContractHistoricalInterpretation::STATUS_VALIDATED) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_interpretation_status_'.(string) $interpretation->status]];
        }
        if (! $this->isEmptyJsonList($interpretation->validation_errors)) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_nonempty_validation_errors']];
        }
        if ($interpretation->completed_at === null) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_missing_completion_timestamp']];
        }

        $output = $this->jsonObject($interpretation->output);
        try {
            $canonical = $this->parser->parse(
                $this->arrayValue($output, 'pricing'),
                $this->arrayValue($output, 'calculation'),
                $this->arrayValue($output, 'source_consistency'),
                withEnergyRules: false,
            );
        } catch (CanonicalPricingParseException) {
            return [null, $ids, [...$flags, 'historical_canonical_omitted_parser_invalid_output']];
        }

        $completedAt = CarbonImmutable::parse((string) $interpretation->completed_at, 'UTC');
        $grade = (string) $episode->evidence_grade;
        $ids['historical_episode_id'] = (int) $episode->id;
        $ids['historical_interpretation_id'] = (int) $interpretation->id;
        $ids['historical_evidence_grade'] = $grade;

        return [$canonical, $ids, [
            ...$flags,
            'retrospective_historical_interpretation',
            'historical_evidence_grade_'.$grade,
            'historical_episode_id_'.(int) $episode->id,
            'historical_interpretation_id_'.(int) $interpretation->id,
            'historical_interpretation_completed_at_'.$completedAt->toAtomString(),
            'historical_household_statistics_scope_assumed',
        ]];
    }

    private function historicalVersionsMatch(object $row): bool
    {
        return (string) $row->schema_version === (string) config('contract_interpretation.historical.schema_version')
            && (string) $row->prompt_version === (string) config('contract_interpretation.historical.prompt_version')
            && (string) $row->historical_addendum_version === (string) config('contract_interpretation.historical.addendum_version')
            && (string) $row->validator_version === (string) config('contract_interpretation.historical.validator_version')
            && (string) $row->parser_version === (string) config('contract_interpretation.historical.parser_version')
            && (string) $row->provider === (string) config('contract_interpretation.provider')
            && (string) $row->model === (string) config('contract_interpretation.model')
            && (string) $row->reasoning_effort === (string) config('contract_interpretation.reasoning_effort');
    }

    /** @param Collection<int, object> $components @return list<string> */
    private function componentCompositeIdentities(Collection $components): array
    {
        return $this->sortedStrings($components->map(
            fn (object $component): string => (string) $component->id.'|'.$this->dateString($component->price_date),
        )->all());
    }

    /** @param array<int, mixed> $values @return list<string> */
    private function sortedStrings(array $values): array
    {
        $strings = array_values(array_map('strval', $values));
        sort($strings, SORT_STRING);

        return $strings;
    }

    /** @param Collection<int, object> $components */
    private function excludedEvidence(
        string $contractId,
        CarbonImmutable $target,
        Collection $components,
        string $reason,
        ?int $priceSnapshotId = null,
    ): AsOfAnnualCostEvidence {
        return new AsOfAnnualCostEvidence(
            contractId: $contractId,
            date: $target,
            companyName: null,
            segmentKey: 'unclassified',
            pricingModel: '',
            contractType: '',
            fixedTimeRange: null,
            metering: null,
            pricingBasis: ContractPriceBasis::ObservedSellerData,
            priceComponents: $this->normalizeComponents($components),
            consumptionAvailability: array_fill_keys(self::CONSUMPTIONS, false),
            canonicalData: null,
            sourceEvidenceIds: [
                'price_snapshot_id' => $priceSnapshotId,
                'price_component_ids' => $components->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                'observation_ids' => [],
                'source_snapshot_id' => null,
                'interpretation_id' => null,
                'historical_episode_id' => null,
                'historical_interpretation_id' => null,
                'historical_evidence_grade' => null,
            ],
            provenanceFlags: [$reason],
            exclusionReason: $reason,
        );
    }

    /** @param Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeComponents(Collection $rows): array
    {
        return $rows
            ->groupBy('price_component_type')
            ->map(function (Collection $group): object {
                return $group->first(fn (object $row): bool => (float) $row->price > 0) ?? $group->first();
            })
            ->values()
            ->map(fn (object $row): array => [
                'price_component_type' => $row->price_component_type,
                'payment_unit' => $row->payment_unit,
                'price' => (float) $row->price,
                'has_discount' => (bool) $row->has_discount,
                'discount_value' => $row->discount_value,
                'discount_is_percentage' => $row->discount_is_percentage === null ? null : (bool) $row->discount_is_percentage,
                'discount_type' => $row->discount_type,
                'discount_discount_n_first_kwh' => $row->discount_discount_n_first_kwh,
                'discount_discount_n_first_months' => $row->discount_discount_n_first_months,
                'discount_discount_until_date' => $row->discount_discount_until_date,
            ])
            ->all();
    }

    private function isEmptyJsonList(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && $decoded === [];
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    private function arrayValue(array $values, string $key): ?array
    {
        return isset($values[$key]) && is_array($values[$key]) ? $values[$key] : null;
    }

    private function date(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $date instanceof CarbonInterface ? $date->toDateString() : $date,
            self::TIMEZONE,
        )->startOfDay();
    }

    private function dateString(mixed $date): string
    {
        return CarbonImmutable::parse((string) $date, self::TIMEZONE)->toDateString();
    }
}
