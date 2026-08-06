<?php

namespace App\Services\ContractStatistics;

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

    public static function sameKey(?string $left, ?string $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }

    private static function normalize(?string $key): string
    {
        return $key ?? self::LEGACY_REGIME;
    }
}
