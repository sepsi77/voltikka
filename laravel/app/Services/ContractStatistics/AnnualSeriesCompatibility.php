<?php

namespace App\Services\ContractStatistics;

use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;

/**
 * Keeps public annual-cost series inside one calculation regime.
 *
 * Feed periods in date order. A null key is the legacy regime, not missing
 * compatibility data. A mixed period or the first period after a regime
 * transition is a chart gap.
 */
final class AnnualSeriesCompatibility
{
    private const LEGACY_REGIME = "\0legacy";

    private bool $hasPreviousRegime = false;

    private ?string $previousRegime = null;

    /**
     * @param  iterable<int, ?string>  $compatibilityKeys
     * @return array{comparable:bool,compatibility_key:?string,mixed:bool,transition_gap:bool}
     */
    public function evaluatePeriod(iterable $compatibilityKeys): array
    {
        $keys = is_array($compatibilityKeys)
            ? array_values($compatibilityKeys)
            : array_values(iterator_to_array($compatibilityKeys, false));

        if ($keys === []) {
            return [
                'comparable' => false,
                'compatibility_key' => null,
                'mixed' => false,
                'transition_gap' => false,
            ];
        }

        $regimes = array_map(self::normalize(...), $keys);
        $uniqueRegimes = array_values(array_unique($regimes, SORT_STRING));
        $mixed = count($uniqueRegimes) !== 1;
        $transitionGap = $this->hasPreviousRegime
            && $regimes[0] !== $this->previousRegime;

        $this->hasPreviousRegime = true;
        $this->previousRegime = $regimes[array_key_last($regimes)];

        return [
            'comparable' => ! $mixed && ! $transitionGap,
            'compatibility_key' => $mixed ? null : $keys[0],
            'mixed' => $mixed,
            'transition_gap' => $transitionGap,
        ];
    }

    /**
     * Use the dominant estimate method as the aggregate chart regime.
     * Stored compatibility remains unchanged and strict for audit use.
     *
     * @param  array<string, mixed>|null  $basisCounts
     */
    public static function aggregateDisplayKey(
        ?string $storedCompatibilityKey,
        ?string $methodVersion,
        ?array $basisCounts,
    ): ?string {
        if ($methodVersion !== AnnualCostMethodVersion::AsOf->value) {
            return $storedCompatibilityKey;
        }

        $methodCounts = $basisCounts['estimate_method'] ?? null;
        if (! is_array($methodCounts) || $methodCounts === []) {
            return $storedCompatibilityKey;
        }

        foreach ($methodCounts as $method => $count) {
            if (! is_string($method) || $method === '' || ! is_int($count) || $count <= 0) {
                return $storedCompatibilityKey;
            }
        }

        $maximum = max($methodCounts);
        $dominantMethods = array_keys(array_filter(
            $methodCounts,
            static fn (int $count): bool => $count === $maximum,
        ));
        sort($dominantMethods, SORT_STRING);

        return 'annual-cost-display:'.hash('sha256', json_encode([
            'method_version' => AnnualCostMethodVersion::AsOf->value,
            'dominant_estimate_methods' => $dominantMethods,
        ], JSON_THROW_ON_ERROR));
    }

    public static function sameKey(?string $left, ?string $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }

    private static function normalize(?string $key): string
    {
        return $key ?? self::LEGACY_REGIME;
    }
}
