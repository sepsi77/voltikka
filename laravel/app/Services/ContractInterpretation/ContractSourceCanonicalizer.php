<?php

namespace App\Services\ContractInterpretation;

use JsonException;

class ContractSourceCanonicalizer
{
    /**
     * Create a stable fingerprint for one upstream contract payload.
     *
     * Object-key order and harmless string whitespace do not affect the
     * fingerprint. List order is preserved because it can carry meaning.
     * Shared spot-futures market data is not part of contract semantics.
     *
     * @throws JsonException
     */
    public function fingerprint(array $payload): string
    {
        unset($payload['Details']['SpotFutures']);

        return hash('sha256', json_encode(
            $this->normalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
