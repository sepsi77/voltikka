<?php

namespace App\Services\ContractInterpretation;

use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\Enums\HistoricalEvidenceGrade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class HistoricalContractEpisodeBuilder
{
    public const VERSION = 'historical-episode-builder-v5';

    public const DISCOVERY_CONTRACT_CHUNK_SIZE = 25;

    private const SNAPSHOT_COLUMNS = [
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
    ];

    private const COMPONENT_COLUMNS = [
        'id',
        'price_date',
        'electricity_contract_id',
        'price_component_type',
        'fuse_size',
        'price',
        'payment_unit',
        'has_discount',
        'discount_value',
        'discount_is_percentage',
        'discount_type',
        'discount_discount_n_first_kwh',
        'discount_discount_n_first_months',
        'discount_discount_until_date',
    ];

    public function __construct(
        private readonly ContractInterpretationInputBuilder $inputBuilder,
        private readonly HistoricalInterpretationFingerprint $fingerprints,
        private readonly HistoricalEvidenceNormalizer $normalizer,
    ) {}

    /**
     * Discover the complete chronology through the cutoff. Date selection is a later planning step.
     *
     * This compatibility method retains all episodes. Memory-sensitive callers must consume
     * discoverChunks(), which releases each chunk before it reads the next one.
     *
     * @param  list<string>  $contractIds
     * @return array{scanned_contract_days: int, eligible_days: int, ineligible: array<string, int>, grades: array<string, int>, episodes: list<array<string, mixed>>}
     */
    public function discover(CarbonImmutable $cutoff, array $contractIds = []): array
    {
        $result = $this->emptyDiscovery();

        foreach ($this->discoverChunks($cutoff, $contractIds) as $chunkResult) {
            $result['scanned_contract_days'] += $chunkResult['scanned_contract_days'];
            $result['eligible_days'] += $chunkResult['eligible_days'];
            $result['ineligible'] = $this->mergeCounts($result['ineligible'], $chunkResult['ineligible']);
            $result['grades'] = $this->mergeCounts($result['grades'], $chunkResult['grades']);
            array_push($result['episodes'], ...$chunkResult['episodes']);
        }

        ksort($result['ineligible']);
        ksort($result['grades']);

        return $result;
    }

    /**
     * Yield one deterministic set of complete contract chronologies at a time.
     *
     * @param  list<string>  $contractIds
     * @return \Generator<int, array{scanned_contract_days: int, eligible_days: int, ineligible: array<string, int>, grades: array<string, int>, episodes: list<array<string, mixed>>}>
     */
    public function discoverChunks(CarbonImmutable $cutoff, array $contractIds = []): \Generator
    {
        $cutoffDate = $cutoff->toDateString();
        $contractIds = $this->discoveryContractIds($cutoffDate, $contractIds);

        foreach (array_chunk($contractIds, self::DISCOVERY_CONTRACT_CHUNK_SIZE) as $chunk) {
            $days = [];
            $snapshots = DB::table('contract_price_snapshots')
                ->select(self::SNAPSHOT_COLUMNS)
                ->whereDate('snapshot_date', '<=', $cutoffDate)
                ->whereIn('contract_id', $chunk)
                ->orderBy('contract_id')
                ->orderBy('snapshot_date')
                ->orderBy('id')
                ->get();
            foreach ($snapshots as $snapshot) {
                $date = CarbonImmutable::parse($snapshot->snapshot_date)->toDateString();
                $key = $snapshot->contract_id.'|'.$date;
                $days[$key] ??= ['contract_id' => $snapshot->contract_id, 'date' => $date, 'snapshots' => [], 'components' => []];
                $days[$key]['snapshots'][] = (array) $snapshot;
            }
            unset($snapshot, $snapshots);

            $components = DB::table('price_components')
                ->select(self::COMPONENT_COLUMNS)
                ->whereDate('price_date', '<=', $cutoffDate)
                ->whereIn('electricity_contract_id', $chunk)
                ->orderBy('electricity_contract_id')
                ->orderBy('price_date')
                ->orderBy('id')
                ->get();
            foreach ($components as $component) {
                $date = CarbonImmutable::parse($component->price_date)->toDateString();
                $key = $component->electricity_contract_id.'|'.$date;
                $days[$key] ??= ['contract_id' => $component->electricity_contract_id, 'date' => $date, 'snapshots' => [], 'components' => []];
                $days[$key]['components'][] = (array) $component;
            }
            unset($component, $components);

            $chunkResult = $this->buildFromDays(array_values($days), $this->textEvidence($chunk));
            unset($days);

            yield $chunkResult;
            unset($chunkResult);
        }
    }

    /** @param list<string> $suppliedIds */
    private function discoveryContractIds(string $cutoffDate, array $suppliedIds): array
    {
        if ($suppliedIds !== []) {
            $ids = array_values(array_unique(array_map('strval', $suppliedIds)));
            sort($ids, SORT_STRING);

            return $ids;
        }

        $snapshotIds = DB::table('contract_price_snapshots')
            ->select('contract_id')
            ->whereDate('snapshot_date', '<=', $cutoffDate);
        $componentIds = DB::table('price_components')
            ->selectRaw('electricity_contract_id as contract_id')
            ->whereDate('price_date', '<=', $cutoffDate);

        return DB::query()
            ->fromSub($snapshotIds->union($componentIds), 'historical_contract_ids')
            ->orderBy('contract_id')
            ->pluck('contract_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @return array{scanned_contract_days: int, eligible_days: int, ineligible: array<string, int>, grades: array<string, int>, episodes: list<array<string, mixed>>}
     */
    private function emptyDiscovery(): array
    {
        return [
            'scanned_contract_days' => 0,
            'eligible_days' => 0,
            'ineligible' => [],
            'grades' => [],
            'episodes' => [],
        ];
    }

    /** @param array<string, int> $left @param array<string, int> $right @return array<string, int> */
    private function mergeCounts(array $left, array $right): array
    {
        foreach ($right as $key => $count) {
            $left[$key] = ($left[$key] ?? 0) + $count;
        }

        return $left;
    }

    /**
     * Pure episode grouping boundary used by unit tests.
     *
     * @param  list<array{contract_id: string, date: string, snapshots: list<array<string, mixed>>, components: list<array<string, mixed>>}>  $days
     * @param  array<string, array{base_input: array<string, mixed>, grade: string, provenance: array<string, mixed>}>  $textEvidence
     * @return array{scanned_contract_days: int, eligible_days: int, ineligible: array<string, int>, grades: array<string, int>, episodes: list<array<string, mixed>>}
     */
    public function buildFromDays(array $days, array $textEvidence = []): array
    {
        usort($days, fn (array $a, array $b): int => [$a['contract_id'], $a['date']] <=> [$b['contract_id'], $b['date']]);
        $ineligible = [];
        $eligible = [];

        foreach ($days as $day) {
            $snapshotCount = count($day['snapshots']);
            $componentCount = count($day['components']);
            $reason = match (true) {
                $snapshotCount === 0 && $componentCount > 0 => 'component_only',
                $snapshotCount > 1 => 'ambiguous_snapshot_identity',
                $snapshotCount === 1 && $componentCount === 0 => 'missing_components',
                default => null,
            };
            if ($reason !== null) {
                $ineligible[$reason] = ($ineligible[$reason] ?? 0) + 1;

                continue;
            }
            if ($snapshotCount !== 1 || $componentCount === 0) {
                $ineligible['empty_day'] = ($ineligible['empty_day'] ?? 0) + 1;

                continue;
            }

            $identity = $this->normalizer->identity($day['snapshots'][0]);
            $components = $this->normalizer->components($day['components']);
            $day['identity'] = $identity;
            $day['normalized_components'] = $components;
            $day['economic_fingerprint'] = $this->normalizer->economicDigestFromNormalized($identity, $components);
            $eligible[] = $day;
        }

        $groups = [];
        foreach ($eligible as $day) {
            $lastIndex = array_key_last($groups);
            $last = $lastIndex === null ? null : $groups[$lastIndex];
            $consecutive = $last !== null
                && $last['contract_id'] === $day['contract_id']
                && CarbonImmutable::parse($last['end'])->addDay()->toDateString() === $day['date'];
            if (! $consecutive || $last['economic_fingerprint'] !== $day['economic_fingerprint']) {
                $groups[] = [
                    'contract_id' => $day['contract_id'],
                    'start' => $day['date'],
                    'end' => $day['date'],
                    'economic_fingerprint' => $day['economic_fingerprint'],
                    'days' => [$day],
                ];
            } else {
                $groups[$lastIndex]['end'] = $day['date'];
                $groups[$lastIndex]['days'][] = $day;
            }
        }

        $episodes = [];
        $grades = [];
        foreach ($groups as $group) {
            $first = $group['days'][0];
            $text = $textEvidence[$group['contract_id']] ?? $this->structuredOnlyEvidence();
            $input = $this->analysisInput($group, $first, $text);
            $manifest = [
                'evidence_grade' => $text['grade'],
                'text_provenance' => $text['provenance'],
                'target_days' => array_values(array_map(fn (array $day): array => [
                    'date' => $day['date'],
                    'snapshot_id' => (int) $day['snapshots'][0]['id'],
                    'component_ids' => $this->componentCompositeIdentities($day['normalized_components']),
                    'economic_digest' => $day['economic_fingerprint'],
                ], $group['days'])),
            ];
            $manifestFingerprint = $this->fingerprints->manifest($manifest);
            $evidenceFingerprint = $this->fingerprints->evidence($input, $manifest);
            $episodeFingerprint = $this->fingerprints->episode(
                self::VERSION,
                $group['contract_id'],
                $group['start'],
                $group['end'],
                $evidenceFingerprint,
            );
            $grade = $text['grade'];
            $grades[$grade] = ($grades[$grade] ?? 0) + 1;
            $episodes[] = [
                'contract_id' => $group['contract_id'],
                'episode_start' => $group['start'],
                'episode_end' => $group['end'],
                'builder_version' => self::VERSION,
                'episode_fingerprint' => $episodeFingerprint,
                'evidence_fingerprint' => $evidenceFingerprint,
                'manifest_fingerprint' => $manifestFingerprint,
                'evidence_grade' => $grade,
                'analysis_input' => $input,
                'evidence_manifest' => $manifest,
                'analysis_fingerprint' => $this->fingerprints->analysis($episodeFingerprint),
            ];
        }

        ksort($ineligible);
        ksort($grades);

        return [
            'scanned_contract_days' => count($days),
            'eligible_days' => count($eligible),
            'ineligible' => $ineligible,
            'grades' => $grades,
            'episodes' => $episodes,
        ];
    }

    /** @return array<string, mixed> */
    private function analysisInput(array $group, array $day, array $text): array
    {
        $identity = $day['identity'];
        $base = $text['base_input'];

        $input = array_replace($base, [
            'analysis_date' => $group['start'],
            'contract_id' => $group['contract_id'],
            'company_name' => $identity['company_name'],
            'contract_name' => $identity['contract_name'],
            'pricing_model' => $identity['pricing_model'],
            'contract_type' => $identity['contract_type'],
            'fixed_time_range' => $identity['fixed_time_range'],
            'metering' => $identity['metering'],
            'pricing_has_discounts' => $identity['has_discount'],
            'components' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'price_component_type' => $row['price_component_type'],
                'fuse_size' => $row['fuse_size'],
                'price' => $row['price'],
                'payment_unit' => $row['payment_unit'],
                'has_discount' => $row['has_discount'],
                'discount_value' => $row['discount_value'],
                'discount_is_percentage' => $row['discount_is_percentage'],
                'discount_type' => $row['discount_type'],
                'discount_n_first_kwh' => $row['discount_n_first_kwh'],
                'discount_n_first_months' => $row['discount_n_first_months'],
                'discount_until_date' => $row['discount_until_date'],
            ], $day['normalized_components']),
            '_historical_provenance' => [
                'evidence_grade' => $text['grade'],
                'text_is_backcast' => $text['grade'] !== HistoricalEvidenceGrade::StructuredOnly->value,
                'limitations' => $text['provenance']['limitations'],
                'text_source_kind' => $text['provenance']['source_kind'],
            ],
        ]);
        $expectedKeys = [...ContractInterpretationInputBuilder::TOP_LEVEL_KEYS, '_historical_provenance'];
        if (array_keys($input) !== $expectedKeys) {
            throw new \LogicException('Historical analysis input does not match the normal flat input shape plus provenance.');
        }

        return $input;
    }

    /** @param list<string> $contractIds */
    private function textEvidence(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $contracts = ElectricityContract::query()
            ->select([
                'id',
                'api_id',
                'target_group',
                'spot_price_selection',
                'pricing_name',
                'short_description',
                'long_description',
                'extra_information_fi',
                'extra_information_default',
                'time_period_definitions',
                'billing_frequency',
                'consumption_limitation_min_x_kwh_per_y',
                'consumption_limitation_max_x_kwh_per_y',
            ])
            ->whereIn('id', $contractIds)
            ->get()
            ->keyBy('id');
        $snapshots = ContractSourceSnapshot::query()
            ->select(['id', 'contract_id', 'source_payload', 'first_observed_at'])
            ->whereIn('contract_id', $contractIds)
            ->orderBy('contract_id')
            ->orderBy('first_observed_at')
            ->orderBy('id')
            ->get()
            ->groupBy('contract_id')
            ->map->first();
        $result = [];

        foreach ($contractIds as $contractId) {
            $snapshot = $snapshots->get($contractId);
            if ($snapshot instanceof ContractSourceSnapshot) {
                $result[$contractId] = [
                    'base_input' => $this->inputBuilder->build($snapshot),
                    'grade' => HistoricalEvidenceGrade::FirstImmutableTextBackcast->value,
                    'provenance' => [
                        'source_kind' => 'first_immutable_source_snapshot',
                        'source_snapshot_id' => $snapshot->id,
                        'source_observed_at' => $snapshot->first_observed_at?->toAtomString(),
                        'limitations' => 'The text is from the earliest immutable source snapshot and is not proven contemporaneous with the historical episode.',
                    ],
                ];

                continue;
            }

            $contract = $contracts->get($contractId);
            $consumptionLimitation = $contract === null ? null : array_filter([
                'MinXKWhPerY' => $contract->consumption_limitation_min_x_kwh_per_y,
                'MaxXKWhPerY' => $contract->consumption_limitation_max_x_kwh_per_y,
            ], fn (mixed $value): bool => $value !== null);
            $fields = $contract === null ? [] : array_filter([
                'api_id' => $contract->api_id,
                'target_group' => $contract->target_group,
                'spot_price_selection' => $contract->spot_price_selection,
                'pricing_name' => $contract->pricing_name,
                'short_description' => $this->inputBuilder->normalizeText($contract->short_description),
                'long_description' => $this->inputBuilder->normalizeText($contract->long_description),
                'extra_information_fi' => $this->inputBuilder->normalizeText($contract->extra_information_fi),
                'extra_information_default' => $this->inputBuilder->normalizeText($contract->extra_information_default),
                'time_period_definitions' => $contract->time_period_definitions,
                'billing_frequency' => $contract->billing_frequency,
                'consumption_limitation' => $consumptionLimitation === [] ? null : $consumptionLimitation,
            ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
            if ($fields !== []) {
                $result[$contractId] = [
                    'base_input' => array_replace($this->emptyInput($contractId), $fields),
                    'grade' => HistoricalEvidenceGrade::LastObservedTextBackcast->value,
                    'provenance' => [
                        'source_kind' => 'electricity_contract_last_observed_fields',
                        'limitations' => 'The retained descriptive fields and metadata are last-observed values and are not proven contemporaneous with the historical episode.',
                    ],
                ];
            }
        }

        return $result;
    }

    private function structuredOnlyEvidence(): array
    {
        return [
            'base_input' => $this->emptyInput(''),
            'grade' => HistoricalEvidenceGrade::StructuredOnly->value,
            'provenance' => [
                'source_kind' => 'structured_only',
                'limitations' => 'No immutable or retained descriptive text is available. Prose evidence is null.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyInput(string $contractId): array
    {
        return [
            'analysis_date' => null,
            'contract_id' => $contractId,
            'api_id' => null,
            'company_name' => null,
            'contract_name' => null,
            'pricing_model' => null,
            'contract_type' => null,
            'fixed_time_range' => null,
            'metering' => null,
            'target_group' => null,
            'spot_price_selection' => null,
            'pricing_name' => null,
            'pricing_has_discounts' => false,
            'short_description' => null,
            'long_description' => null,
            'extra_information_fi' => null,
            'extra_information_default' => null,
            'time_period_definitions' => null,
            'billing_frequency' => null,
            'consumption_limitation' => null,
            'components' => [],
        ];
    }

    /** @param list<array<string, mixed>> $components @return list<string> */
    private function componentCompositeIdentities(array $components): array
    {
        $identities = array_map(
            fn (array $component): string => (string) $component['id'].'|'.(string) $component['price_date'],
            $components,
        );
        sort($identities, SORT_STRING);

        return array_values($identities);
    }
}
