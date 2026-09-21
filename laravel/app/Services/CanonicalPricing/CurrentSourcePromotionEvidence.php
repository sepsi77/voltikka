<?php

namespace App\Services\CanonicalPricing;

use App\Models\ContractSourceSnapshot;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationProfile;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Source prose can exclude a price, but can never supply a billed amount. */
class CurrentSourcePromotionEvidence
{
    /** @return array<string, array{valid: bool, rates: list<float>, energy_rules_valid: bool, energy_rules_required: bool}> */
    public function forContracts(Collection $contracts, ?CarbonInterface $asOf = null): array
    {
        $pointed = $contracts->filter(fn ($contract) => $contract->published_interpretation_id !== null || $contract->current_source_observation_id !== null);
        if ($pointed->isEmpty()) {
            return [];
        }
        $asOf = CarbonImmutable::parse(($asOf ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(), 'Europe/Helsinki')->endOfDay()->utc();
        // Legacy proof needs source prose only, not the larger V5 validation documents.
        $energyRuleProfile = "interpretations.schema_version = 'schema-v5' AND interpretations.prompt_version = 'prompt-v20' AND interpretations.validator_version = 'validator-v18'";
        $rows = DB::table('electricity_contracts as contracts')
            ->leftJoin('contract_source_observations as observations', 'observations.id', '=', 'contracts.current_source_observation_id')
            ->leftJoin('contract_interpretations as interpretations', 'interpretations.id', '=', 'contracts.published_interpretation_id')
            ->leftJoin('contract_source_snapshots as sources', 'sources.id', '=', 'observations.source_snapshot_id')
            ->whereIn('contracts.id', $pointed->pluck('id'))
            ->get([
                'contracts.id', 'contracts.current_source_observation_id', 'contracts.published_interpretation_id',
                'observations.contract_id as observed_contract', 'observations.source_snapshot_id as observed_snapshot',
                'interpretations.contract_id as published_contract', 'interpretations.source_snapshot_id as published_snapshot',
                'interpretations.status', 'interpretations.validation_errors',
                'interpretations.schema_version', 'interpretations.prompt_version', 'interpretations.validator_version',
                DB::raw("CASE WHEN {$energyRuleProfile} THEN interpretations.output ELSE NULL END as output"),
                'interpretations.analysis_source_observation_id',
                'interpretations.completed_at', 'interpretations.published_at',
                'observations.first_observed_at', 'observations.last_observed_at',
                DB::raw("CASE WHEN {$energyRuleProfile} THEN contracts.canonical_pricing ELSE NULL END as canonical_pricing"),
                DB::raw("CASE WHEN {$energyRuleProfile} THEN contracts.canonical_calculation ELSE NULL END as canonical_calculation"),
                DB::raw("CASE WHEN {$energyRuleProfile} THEN contracts.canonical_source_consistency ELSE NULL END as canonical_source_consistency"),
                'sources.contract_id as source_contract', 'sources.source_payload',
            ])->keyBy('id');
        $result = [];
        $normalizer = new ContractInterpretationInputBuilder;
        foreach ($pointed as $contract) {
            $row = $rows->get($contract->id);
            $valid = $row !== null
                && (string) $row->current_source_observation_id === (string) $contract->current_source_observation_id
                && (string) $row->published_interpretation_id === (string) $contract->published_interpretation_id
                && $row->observed_contract === $contract->id && $row->published_contract === $contract->id
                && $row->source_contract === $contract->id
                && $row->observed_snapshot !== null && $row->observed_snapshot === $row->published_snapshot
                && $row->status === 'published'
                && empty(json_decode($row->validation_errors ?? 'null', true));
            $rates = [];
            if ($valid) {
                $source = json_decode($row->source_payload, true);
                $rates = self::campaignRatesFromPayload(is_array($source) ? $source : []);
            }
            $result[(string) $contract->id] = [
                'valid' => $valid,
                'rates' => array_values(array_unique($rates)),
                'energy_rules_valid' => $valid && $this->hasEnergyRuleProof($row, $contract, $asOf, $normalizer),
                'energy_rules_required' => $row?->schema_version === 'schema-v5' || self::requiresEnergyRuleProof($contract->canonical_pricing),
            ];
        }

        return $result;
    }

    /** Pure extraction from the caller's exact source, without current-pointer validation. */
    public static function campaignRatesFromPayload(array $source): array
    {
        $normalizer = new ContractInterpretationInputBuilder;
        $details = $source['Details'] ?? [];
        $rates = [];
        foreach ([$source['Name'] ?? null, $details['Pricing']['Name'] ?? null, $details['ShortDescription'] ?? null,
            $details['LongDescription'] ?? null, $details['ExtraInformation']['FI'] ?? null,
            $details['ExtraInformation']['Default'] ?? null] as $text) {
            $text = $normalizer->normalizeText($text) ?? '';
            // Explicit campaign energy or margin wording plus its numerical c/kWh price only.
            // "vain", "tarjous", and "superdiili" are not temporary-price evidence.
            preg_match_all('/(?:kampanjamarginaali|marginaalin\s+kampanjahinta|kampanjahinta|kampanjan\s+energiahinta|energian\s+kampanjahinta)\s*(?:on\s*)?[:]?\s*(\d+(?:[.,]\d+)?)\s*(?:snt|c)\s*\/\s*kwh/iu', $text, $matches);
            foreach ($matches[1] as $amount) {
                $rates[] = (float) str_replace(',', '.', $amount);
            }
        }

        return array_values(array_unique($rates));
    }

    /** An unpointed or malformed known-rule object must not acquire legacy fixed-price semantics. */
    public static function requiresEnergyRuleProof(mixed $pricing): bool
    {
        if (! is_array($pricing)) {
            return false;
        }
        foreach (is_array($pricing['phases'] ?? null) ? $pricing['phases'] : [] as $phase) {
            foreach (is_array($phase['components'] ?? null) ? $phase['components'] : [] as $component) {
                $rule = $component['energy_rule'] ?? null;
                if ($rule !== null && (! is_array($rule) || ($rule['kind'] ?? null) !== 'unknown')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** This stronger proof does not change the legacy campaign-exclusion contract. */
    private function hasEnergyRuleProof(object $row, object $contract, CarbonImmutable $asOf, ContractInterpretationInputBuilder $builder): bool
    {
        if ($row->schema_version !== 'schema-v5' || $row->prompt_version !== 'prompt-v20' || $row->validator_version !== 'validator-v18'
            || ($row->analysis_source_observation_id !== null && (string) $row->analysis_source_observation_id !== (string) $row->current_source_observation_id)) {
            return false;
        }
        try {
            $first = $this->timestamp($row->first_observed_at);
            $last = $this->timestamp($row->last_observed_at);
            $completed = $this->timestamp($row->completed_at);
            $published = $this->timestamp($row->published_at);
            if ($first === null || $last === null || $completed === null || $published === null
                || $first->gt($asOf) || $last->lt($first) || $completed->gt($asOf)
                || $published->gt($asOf) || $published->lt($first) || $published->lt($completed)) {
                return false;
            }
            $errors = json_decode($row->validation_errors ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            $output = json_decode($row->output ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            $source = json_decode($row->source_payload ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            if (! in_array($errors, [null, []], true) || ! is_array($output) || ! is_array($source)) {
                return false;
            }
            foreach (['canonical_pricing' => 'pricing', 'canonical_calculation' => 'calculation', 'canonical_source_consistency' => 'source_consistency'] as $column => $key) {
                $stored = json_decode($row->{$column} ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($output[$key] ?? null)
                    || ! $this->sameCanonicalValue($stored, $output[$key])
                    || ! $this->sameCanonicalValue($contract->{$column}, $output[$key])) {
                    return false;
                }
            }
            $snapshot = new ContractSourceSnapshot(['contract_id' => $row->source_contract, 'source_payload' => $source]);
            $profile = ContractInterpretationProfile::stored($row->schema_version, $row->prompt_version, $row->validator_version);
            $input = $builder->build($snapshot, $first, $profile);

            return (new ContractInterpretationValidator)->validate($output, $input, $profile) === [];
        } catch (\JsonException|InvalidFormatException|\TypeError $exception) {
            return false;
        }
    }

    /** Ignore object-key order and integer/float representation, but no other JSON types. */
    private function sameCanonicalValue(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual) && is_array($expected)) {
            if (count($actual) !== count($expected) || array_is_list($actual) !== array_is_list($expected)) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $actual) || ! $this->sameCanonicalValue($actual[$key], $value)) {
                    return false;
                }
            }

            return true;
        }
        if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
            return $actual == $expected;
        }

        return $actual === $expected;
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');

        return $date && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }
}
