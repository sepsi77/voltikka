<?php

namespace App\Services\ContractInterpretation;

use App\Services\CanonicalPricing\CanonicalPricingParser;

class HistoricalInterpretationFingerprint
{
    public function evidence(array $analysisInput, array $manifest): string
    {
        unset($analysisInput['analysis_date']);
        if (is_array($analysisInput['components'] ?? null)) {
            foreach ($analysisInput['components'] as &$component) {
                unset($component['id']);
            }
            unset($component);
        }

        $provenance = $manifest['text_provenance'] ?? [];
        if (is_array($provenance)) {
            unset($provenance['source_snapshot_id']);
        }

        return $this->hash([
            'analysis_input' => $analysisInput,
            'text_provenance' => $provenance,
            'evidence_grade' => $manifest['evidence_grade'] ?? null,
        ]);
    }

    public function manifest(array $manifest): string
    {
        return $this->hash($manifest);
    }

    public function episode(
        string $builderVersion,
        string $contractId,
        string $episodeStart,
        string $episodeEnd,
        string $evidenceFingerprint,
    ): string {
        return $this->hash(compact(
            'builderVersion',
            'contractId',
            'episodeStart',
            'episodeEnd',
            'evidenceFingerprint',
        ));
    }

    public function analysis(string $episodeFingerprint): string
    {
        return $this->hash([
            'episode_fingerprint' => $episodeFingerprint,
            'schema_version' => config('contract_interpretation.schema_version'),
            'prompt_version' => config('contract_interpretation.prompt_version'),
            'historical_addendum_version' => config('contract_interpretation.historical.addendum_version'),
            'historical_backcast_validator_version' => HistoricalInterpretationBackcastValidator::VERSION,
            'validator_version' => config('contract_interpretation.validator_version'),
            'parser_version' => CanonicalPricingParser::VERSION,
            'provider' => config('contract_interpretation.provider'),
            'model' => config('contract_interpretation.model'),
            'reasoning_effort' => config('contract_interpretation.reasoning_effort'),
        ]);
    }

    public function hash(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_int($value)) {
            return ['__historical_number' => (string) $value];
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new \InvalidArgumentException('Historical fingerprints cannot contain non-finite numbers.');
            }

            $number = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');

            return ['__historical_number' => $number === '' || $number === '-0' ? '0' : $number];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
