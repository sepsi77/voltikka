<?php

namespace App\Services\ContractInterpretation;

use App\Services\ContractInterpretation\Enums\HistoricalEvidenceGrade;
use Carbon\CarbonImmutable;

class HistoricalInterpretationBackcastValidator
{
    public const VERSION = 'historical-backcast-validator-v2';

    public function __construct(
        private readonly ContractInterpretationValidator $normalValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function validate(array $output, array $input): array
    {
        if (! $this->usesBackcastText($input)) {
            return [];
        }

        $pricingModel = $output['classification']['primary_pricing_model']
            ?? $input['pricing_model']
            ?? null;
        $facts = $this->structuredFacts($input, $pricingModel);
        $errors = [];

        foreach ($output['pricing']['phases'] ?? [] as $phaseIndex => $phase) {
            if (! is_array($phase)) {
                continue;
            }

            [$matches, $componentErrors] = $this->matchPhaseComponents($phase, $phaseIndex, $facts);
            array_push($errors, ...$componentErrors);

            foreach (['starts', 'ends'] as $side) {
                $boundary = $phase[$side] ?? null;
                if (! is_array($boundary)) {
                    continue;
                }

                $error = $this->boundaryError($boundary, $side, $matches);
                if ($error !== null) {
                    $errors[] = "Historical backcast restriction: $.pricing.phases[{$phaseIndex}].{$side} {$error}";
                }
            }

            $package = $phase['package'] ?? null;
            if (is_array($package)) {
                array_push($errors, ...$this->packageErrors($package, $phaseIndex, $facts));
            }
        }

        foreach ($output['pricing']['consumption_effect'] ?? [] as $field => $amount) {
            if (in_array($field, [
                'expected_cents_per_kwh',
                'typical_min_cents_per_kwh',
                'typical_max_cents_per_kwh',
                'hard_min_cents_per_kwh',
                'hard_max_cents_per_kwh',
            ], true) && is_numeric($amount)) {
                $errors[] = "Historical backcast restriction: $.pricing.consumption_effect.{$field} must be null because the historical structured components contain no typed consumption-effect value for that mechanism.";
            }
        }

        $schedule = $output['pricing']['recurring_schedule'] ?? [];
        if (is_array($schedule)) {
            foreach (['current_period_start', 'current_period_end'] as $field) {
                if (($schedule[$field] ?? null) !== null) {
                    $errors[] = "Historical backcast restriction: $.pricing.recurring_schedule.{$field} must be null because these historical episode inputs have no exact structured recurring-period date field.";
                }
            }
        }

        if (($output['source_consistency']['misleading_first_12_months'] ?? null) === 'detected') {
            $errors[] = 'Historical backcast restriction: $.source_consistency.misleading_first_12_months must not be detected for retrospective backcast evidence; use uncertain, not_detected, or not_assessable.';
        }

        return array_values(array_unique($errors));
    }

    private function usesBackcastText(array $input): bool
    {
        $grade = $input['_historical_provenance']['evidence_grade'] ?? null;

        return ($input['_historical_provenance']['text_is_backcast'] ?? false) === true
            && in_array($grade, [
                HistoricalEvidenceGrade::FirstImmutableTextBackcast->value,
                HistoricalEvidenceGrade::LastObservedTextBackcast->value,
            ], true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{
     *     index: int,
     *     source_type: mixed,
     *     canonical_type: ?string,
     *     canonical_unit: ?string,
     *     original_amount: ?float,
     *     discount: array{type: string, amount: float, until_date?: string, continuation_date?: string, months?: int}|null,
     *     component: array<string, mixed>
     * }>
     */
    private function structuredFacts(array $input, mixed $pricingModel): array
    {
        $analysisDate = is_string($input['analysis_date'] ?? null) && $this->isDate($input['analysis_date'])
            ? CarbonImmutable::parse($input['analysis_date'])
            : null;
        $facts = [];

        foreach ($input['components'] ?? [] as $index => $component) {
            if (! is_array($component)) {
                continue;
            }

            $originalAmount = is_numeric($component['price'] ?? null)
                ? (float) $component['price']
                : null;
            $discount = $this->activeDiscount($component, $originalAmount, $analysisDate);
            $facts[] = [
                'index' => (int) $index,
                'source_type' => $component['price_component_type'] ?? null,
                'canonical_type' => $this->normalValidator->canonicalComponentTypeForSource(
                    $component['price_component_type'] ?? null,
                    $pricingModel,
                ),
                'canonical_unit' => $this->canonicalSourceUnit($component['payment_unit'] ?? null),
                'original_amount' => $originalAmount,
                'discount' => $discount,
                'component' => $component,
            ];
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array{type: string, amount: float, until_date?: string, continuation_date?: string, months?: int}|null
     */
    private function activeDiscount(
        array $component,
        ?float $originalAmount,
        ?CarbonImmutable $analysisDate,
    ): ?array {
        $discountValue = $component['discount_value'] ?? null;
        $isPercentage = $component['discount_is_percentage'] ?? null;
        if (($component['has_discount'] ?? null) !== true
            || $originalAmount === null
            || $originalAmount < 0
            || ! is_numeric($discountValue)
            || (float) $discountValue <= 0
            || ! is_bool($isPercentage)) {
            return null;
        }

        $type = $component['discount_type'] ?? null;
        $scope = null;
        if ($type === 'UntilDate') {
            $untilDate = $component['discount_until_date'] ?? null;
            if (! is_string($untilDate) || ! $this->isDate($untilDate)) {
                return null;
            }
            if ($analysisDate !== null && CarbonImmutable::parse($untilDate)->isBefore($analysisDate)) {
                return null;
            }
            $scope = [
                'type' => 'UntilDate',
                'until_date' => $untilDate,
                'continuation_date' => CarbonImmutable::parse($untilDate)->addDay()->toDateString(),
            ];
        } elseif ($type === 'NFirstMonth') {
            $months = $component['discount_n_first_months'] ?? null;
            if (! is_numeric($months)
                || (float) $months !== (float) (int) $months
                || (int) $months <= 0) {
                return null;
            }
            $scope = [
                'type' => 'NFirstMonth',
                'months' => (int) $months,
            ];
        }

        if ($scope === null) {
            return null;
        }

        $discountAmount = $isPercentage
            ? $originalAmount * ((float) $discountValue / 100)
            : (float) $discountValue;

        return [
            ...$scope,
            'amount' => max(0.0, $originalAmount - min($originalAmount, $discountAmount)),
        ];
    }

    /**
     * @param  array<string, mixed>  $phase
     * @param  list<array<string, mixed>>  $facts
     * @return array{0: list<array{fact: array<string, mixed>, amount_kind: string}>, 1: list<string>}
     */
    private function matchPhaseComponents(array $phase, int $phaseIndex, array $facts): array
    {
        $candidateSets = [];
        $errors = [];

        foreach ($phase['components'] ?? [] as $componentIndex => $component) {
            if (! is_array($component)
                || (! is_numeric($component['amount'] ?? null)
                    && ! is_numeric($component['normal_amount'] ?? null))) {
                continue;
            }

            $citedSourceIndexes = $this->evidenceComponentIndexes($component['evidence'] ?? []);
            $candidates = [];
            foreach ($facts as $fact) {
                if (! in_array((int) $fact['index'], $citedSourceIndexes, true)) {
                    continue;
                }

                $amountKind = $this->matchingAmountKind($component, $phase, $fact);
                if ($amountKind !== null) {
                    $candidates[(int) $fact['index']] = [
                        'fact' => $fact,
                        'amount_kind' => $amountKind,
                    ];
                }
            }

            if ($candidates === []) {
                $type = (string) ($component['component_type'] ?? 'missing');
                $unit = (string) ($component['unit'] ?? 'missing');
                $role = (string) ($component['price_role'] ?? 'missing');
                $errors[] = "Historical backcast restriction: $.pricing.phases[{$phaseIndex}].components[{$componentIndex}] has no exact structured source fact with canonical type {$type}, unit {$unit}, amount role {$role}, amount/normal_amount, and this phase's discount scope.";

                continue;
            }

            $candidateSets[(int) $componentIndex] = $candidates;
        }

        $sourceAssignments = [];
        $outputAssignments = [];
        $indexes = array_keys($candidateSets);
        usort($indexes, fn (int $left, int $right): int => count($candidateSets[$left]) <=> count($candidateSets[$right]));

        foreach ($indexes as $componentIndex) {
            if (! $this->assignSourceFact(
                $componentIndex,
                $candidateSets,
                $sourceAssignments,
                $outputAssignments,
                [],
            )) {
                $errors[] = "Historical backcast restriction: $.pricing.phases[{$phaseIndex}].components[{$componentIndex}] is an unmatched extra billed component; one structured source component can be billed only once in a phase.";
            }
        }

        ksort($outputAssignments);
        $matches = array_values(array_map(
            fn (int $sourceIndex, int $componentIndex): array => $candidateSets[$componentIndex][$sourceIndex],
            $outputAssignments,
            array_keys($outputAssignments),
        ));

        return [$matches, $errors];
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $phase
     * @param  array<string, mixed>  $fact
     */
    private function matchingAmountKind(array $component, array $phase, array $fact): ?string
    {
        if (! $this->componentTypeMatches(
            $component['component_type'] ?? null,
            $fact['canonical_type'] ?? null,
            $component['unit'] ?? null,
            $fact['canonical_unit'] ?? null,
        ) || ($component['unit'] ?? null) !== ($fact['canonical_unit'] ?? null)
            || ! is_numeric($fact['original_amount'] ?? null)) {
            return null;
        }

        $amount = $component['amount'] ?? null;
        $normalAmount = $component['normal_amount'] ?? null;
        $role = $component['price_role'] ?? null;
        $originalAmount = (float) $fact['original_amount'];
        $discount = $fact['discount'] ?? null;

        if (is_numeric($normalAmount) && ! $this->amountEquals($normalAmount, $originalAmount)) {
            return null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        if (is_array($discount)
            && $role === 'introductory'
            && $this->amountEquals($amount, $discount['amount'])
            && $this->phaseMatchesDiscount($phase, $discount)
            && is_numeric($normalAmount)) {
            return 'discount';
        }

        if (! $this->amountEquals($amount, $originalAmount) || is_numeric($normalAmount)) {
            return null;
        }

        if (is_array($discount)) {
            return $role === 'normal' && $this->phaseMatchesContinuation($phase, $discount)
                ? 'continuation'
                : null;
        }

        return $role === 'current' ? 'current' : null;
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $candidateSets
     * @param  array<int, int>  $sourceAssignments
     * @param  array<int, int>  $outputAssignments
     * @param  array<int, bool>  $visitedSources
     */
    private function assignSourceFact(
        int $componentIndex,
        array $candidateSets,
        array &$sourceAssignments,
        array &$outputAssignments,
        array $visitedSources,
    ): bool {
        foreach (array_keys($candidateSets[$componentIndex]) as $sourceIndex) {
            if (isset($visitedSources[$sourceIndex])) {
                continue;
            }
            $visitedSources[$sourceIndex] = true;
            $previousComponent = $sourceAssignments[$sourceIndex] ?? null;
            if ($previousComponent === null || $this->assignSourceFact(
                $previousComponent,
                $candidateSets,
                $sourceAssignments,
                $outputAssignments,
                $visitedSources,
            )) {
                $sourceAssignments[$sourceIndex] = $componentIndex;
                $outputAssignments[$componentIndex] = $sourceIndex;

                return true;
            }
        }

        return false;
    }

    private function componentTypeMatches(
        mixed $outputType,
        mixed $sourceType,
        mixed $outputUnit,
        mixed $sourceUnit,
    ): bool {
        if ($outputType === $sourceType) {
            return true;
        }

        return $sourceType === 'monthly_fee'
            && $outputType === 'flat_fee'
            && $sourceUnit === 'eur_per_month'
            && $outputUnit === 'eur_per_month';
    }

    /**
     * @return list<int>
     */
    private function evidenceComponentIndexes(mixed $evidence): array
    {
        if (! is_array($evidence)) {
            return [];
        }

        return collect($evidence)
            ->pluck('source')
            ->filter(fn (mixed $source): bool => is_string($source))
            ->map(fn (string $source): ?int => preg_match('/^components\[(\d+)]\./', $source, $matches) === 1
                ? (int) $matches[1]
                : null)
            ->filter(fn (?int $index): bool => $index !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $phase
     * @param  array<string, mixed>  $discount
     */
    private function phaseMatchesDiscount(array $phase, array $discount): bool
    {
        if (($discount['type'] ?? null) === 'UntilDate') {
            return ($phase['ends']['kind'] ?? null) === 'date'
                && ($phase['ends']['value'] ?? null) === ($discount['until_date'] ?? null)
                && in_array($phase['starts']['kind'] ?? null, ['contract_start', 'unknown'], true);
        }

        return ($discount['type'] ?? null) === 'NFirstMonth'
            && ($phase['starts']['kind'] ?? null) === 'contract_start'
            && ($phase['ends']['kind'] ?? null) === 'after_months'
            && $this->integerEquals($phase['ends']['value'] ?? null, $discount['months'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $phase
     * @param  array<string, mixed>  $discount
     */
    private function phaseMatchesContinuation(array $phase, array $discount): bool
    {
        if (($discount['type'] ?? null) === 'UntilDate') {
            return ($phase['starts']['kind'] ?? null) === 'date'
                && ($phase['starts']['value'] ?? null) === ($discount['continuation_date'] ?? null);
        }

        return ($discount['type'] ?? null) === 'NFirstMonth'
            && ($phase['starts']['kind'] ?? null) === 'after_months'
            && $this->integerEquals($phase['starts']['value'] ?? null, $discount['months'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $boundary
     * @param  list<array{fact: array<string, mixed>, amount_kind: string}>  $matches
     */
    private function boundaryError(array $boundary, string $side, array $matches): ?string
    {
        $kind = $boundary['kind'] ?? null;
        if (! in_array($kind, ['date', 'after_months'], true)) {
            return null;
        }

        $supported = collect($matches)->contains(function (array $match) use ($boundary, $kind, $side): bool {
            $discount = $match['fact']['discount'] ?? null;
            if (! is_array($discount)) {
                return false;
            }

            if ($side === 'ends' && $match['amount_kind'] === 'discount') {
                return $kind === 'date'
                    ? ($discount['type'] ?? null) === 'UntilDate'
                        && ($boundary['value'] ?? null) === ($discount['until_date'] ?? null)
                    : ($discount['type'] ?? null) === 'NFirstMonth'
                        && $this->integerEquals($boundary['value'] ?? null, $discount['months'] ?? null);
            }

            if ($side === 'starts' && $match['amount_kind'] === 'continuation') {
                return $kind === 'date'
                    ? ($discount['type'] ?? null) === 'UntilDate'
                        && ($boundary['value'] ?? null) === ($discount['continuation_date'] ?? null)
                    : ($discount['type'] ?? null) === 'NFirstMonth'
                        && $this->integerEquals($boundary['value'] ?? null, $discount['months'] ?? null);
            }

            return false;
        });

        return $supported
            ? null
            : 'does not match the exact structured discount timing of a component billed in this phase.';
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  list<array<string, mixed>>  $facts
     * @return list<string>
     */
    private function packageErrors(array $package, int $phaseIndex, array $facts): array
    {
        $errors = [];
        $citedSourceIndexes = $this->evidenceComponentIndexes($package['evidence'] ?? []);
        $citedFacts = collect($facts)->filter(
            fn (array $fact): bool => in_array((int) $fact['index'], $citedSourceIndexes, true),
        );
        $monthlyFee = $package['monthly_fee_eur'] ?? null;
        if (is_numeric($monthlyFee) && ! $citedFacts->contains(
            fn (array $fact): bool => ($fact['source_type'] ?? null) === 'Monthly'
                && ($fact['canonical_unit'] ?? null) === 'eur_per_month'
                && $this->amountEquals($fact['original_amount'] ?? null, $monthlyFee),
        )) {
            $errors[] = "Historical backcast restriction: $.pricing.phases[{$phaseIndex}].package.monthly_fee_eur must match an exact Monthly/EurPerMonth structured source price.";
        }

        $includedKwh = $package['included_kwh'] ?? null;
        $excessRate = $package['excess_rate_cents_per_kwh'] ?? null;
        if (is_numeric($includedKwh) && is_numeric($excessRate)) {
            $packageRateMatches = $citedFacts->contains(function (array $fact) use ($includedKwh, $excessRate): bool {
                $component = $fact['component'] ?? [];

                return ($fact['canonical_type'] ?? null) === 'energy_general'
                    && ($fact['canonical_unit'] ?? null) === 'cents_per_kwh'
                    && $this->amountEquals($fact['original_amount'] ?? null, $excessRate)
                    && ($component['has_discount'] ?? null) === true
                    && ($component['discount_type'] ?? null) === 'NFirstKwh'
                    && ($component['discount_is_percentage'] ?? null) === false
                    && $this->amountEquals($component['discount_value'] ?? null, $excessRate)
                    && is_numeric($component['discount_n_first_kwh'] ?? null)
                    && $this->amountEquals($component['discount_n_first_kwh'], (float) $includedKwh * 12);
            });
            if (! $packageRateMatches) {
                $errors[] = "Historical backcast restriction: $.pricing.phases[{$phaseIndex}].package excess_rate_cents_per_kwh and included_kwh must match one exact applicable per-kWh source component and its NFirstKwh annual marker (12 times the monthly allowance).";
            }
        }

        return $errors;
    }

    private function canonicalSourceUnit(mixed $sourceUnit): ?string
    {
        return match ($sourceUnit) {
            'EurPerMonth' => 'eur_per_month',
            'CentPerKiwattHour' => 'cents_per_kwh',
            default => null,
        };
    }

    private function amountEquals(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual)
            && is_numeric($expected)
            && abs((float) $actual - (float) $expected) < 0.000001;
    }

    private function integerEquals(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual)
            && is_numeric($expected)
            && (float) $actual === (float) (int) $actual
            && (int) $actual === (int) $expected;
    }

    private function isDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString() === $value;
        } catch (\Throwable) {
            return false;
        }
    }
}
