<?php

namespace App\Services\ContractInterpretation;

use DateTimeImmutable;
use RuntimeException;

class ContractInterpretationValidator
{
    /**
     * Per-kWh ceiling (c/kWh) separating a Spot supplier margin from a genuine all-in energy
     * price. Mirrors CanonicalContractPriceCalculator::SPOT_MARGIN_CEILING_CENTS.
     */
    private const SPOT_MARGIN_CEILING_CENTS = 2.0;

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function validate(array $output, array $input): array
    {
        $schema = $this->schema();
        $errors = [];
        $this->validateValue($output, $schema, '$', $schema, $errors);

        if (($output['contract_id'] ?? null) !== ($input['contract_id'] ?? null)) {
            $errors[] = '$.contract_id must match the source contract ID.';
        }

        $this->validateEvidence($output, $input, '$', $errors);
        $this->validateClassificationConsistency($output, $input, $errors);
        $this->validatePricing($output, $input, $errors);
        $this->validateStructuredOnlyConsistency($output, $input, $errors);
        $this->validateTemporalConsistency($output, $input, $errors);
        $this->validateMechanismConsistency($output, $input, $errors);
        $this->validateWarningConsistency($output, $errors);

        return array_values(array_unique($errors));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $contents = file_get_contents((string) config('contract_interpretation.schema_path'));
        if ($contents === false) {
            throw new RuntimeException('Cannot read the contract interpretation schema.');
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $rootSchema
     * @param  list<string>  $errors
     */
    private function validateValue(mixed $value, array $schema, string $path, array $rootSchema, array &$errors): void
    {
        if (isset($schema['$ref'])) {
            $schema = $this->resolveReference((string) $schema['$ref'], $rootSchema);
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path} must equal the schema constant.";
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path} has an unsupported value.";
        }

        $types = isset($schema['type'])
            ? (is_array($schema['type']) ? $schema['type'] : [$schema['type']])
            : [];
        if ($types !== [] && ! $this->matchesAnyType($value, $types)) {
            $errors[] = "{$path} has the wrong type.";

            return;
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path} is below the minimum.";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path} is above the maximum.";
            }
        }

        if (is_string($value) && ($schema['format'] ?? null) === 'date' && ! $this->isDate($value)) {
            $errors[] = "{$path} must be an ISO date.";
        }
        if (is_string($value) && isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            $errors[] = "{$path} is shorter than allowed.";
        }
        if (is_string($value) && isset($schema['pattern'])
            && preg_match('~'.$schema['pattern'].'~u', $value) !== 1) {
            $errors[] = "{$path} has an invalid format.";
        }

        if (in_array('object', $types, true) && is_array($value)) {
            $required = $schema['required'] ?? [];
            foreach ($required as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key} is required.";
                }
            }

            $properties = $schema['properties'] ?? [];
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $key) {
                    if (! array_key_exists($key, $properties)) {
                        $errors[] = "{$path}.{$key} is not allowed.";
                    }
                }
            }

            foreach ($properties as $key => $propertySchema) {
                if (array_key_exists($key, $value)) {
                    $this->validateValue($value[$key], $propertySchema, "{$path}.{$key}", $rootSchema, $errors);
                }
            }
        }

        if (in_array('array', $types, true) && is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $this->validateValue($item, $schema['items'], "{$path}[{$index}]", $rootSchema, $errors);
            }
        }
    }

    /**
     * @param  list<string>  $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && (array_is_list($value) || $value === []),
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $rootSchema
     * @return array<string, mixed>
     */
    private function resolveReference(string $reference, array $rootSchema): array
    {
        if (! str_starts_with($reference, '#/')) {
            throw new RuntimeException("Unsupported schema reference: {$reference}");
        }

        $resolved = $rootSchema;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($resolved) || ! array_key_exists($segment, $resolved)) {
                throw new RuntimeException("Invalid schema reference: {$reference}");
            }
            $resolved = $resolved[$segment];
        }

        return $resolved;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validatePricing(array $output, array $input, array &$errors): void
    {
        $phases = $output['pricing']['phases'] ?? [];
        $interpretedTypes = [];

        foreach ($phases as $phaseIndex => $phase) {
            $this->validateBoundaryOrder($phase, $phaseIndex, $errors);
            $this->validateActiveDiscountEvidence($phase, $phaseIndex, $input, $errors);

            $package = $phase['package'] ?? null;
            if (is_array($package)) {
                $packageEvidence = $this->evidenceText($package['evidence'] ?? []);
                foreach (['monthly_fee_eur', 'included_kwh', 'excess_rate_cents_per_kwh'] as $field) {
                    $number = $package[$field] ?? null;
                    if ((is_int($number) || is_float($number))
                        && ! $this->textContainsNumber($packageEvidence, $number)) {
                        $errors[] = "$.pricing.phases[{$phaseIndex}].package.{$field} lacks numeric evidence.";
                    }
                }

                if (($phase['components'] ?? []) !== []) {
                    $errors[] = "$.pricing.phases[{$phaseIndex}] must not duplicate package charges as components.";
                }
            }

            $phaseComponents = collect($phase['components'] ?? []);
            $hasMonthlyFee = $phaseComponents->contains(
                fn (array $component): bool => ($component['component_type'] ?? null) === 'monthly_fee',
            );
            $hasMonthlyFlatFee = $phaseComponents->contains(
                fn (array $component): bool => ($component['component_type'] ?? null) === 'flat_fee'
                    && ($component['unit'] ?? null) === 'eur_per_month',
            );
            if ($hasMonthlyFee && $hasMonthlyFlatFee) {
                $errors[] = "$.pricing.phases[{$phaseIndex}] has ambiguous duplicate monthly fees as flat_fee and monthly_fee.";
            }

            foreach ($phase['components'] ?? [] as $componentIndex => $component) {
                $interpretedTypes[] = $component['component_type'] ?? null;
                $evidenceText = $this->evidenceText($component['evidence'] ?? []);
                foreach (['amount', 'normal_amount'] as $field) {
                    $number = $component[$field] ?? null;
                    if (is_int($number) || is_float($number)) {
                        $hasLiteralEvidence = $this->textContainsNumber($evidenceText, $number);
                        $hasDerivedDiscountEvidence = $field === 'amount'
                            && $this->hasDerivedDiscountEvidence($component, $phase, $number, $input);
                        if (! $hasLiteralEvidence && ! $hasDerivedDiscountEvidence) {
                            $errors[] = "$.pricing.phases[{$phaseIndex}].components[{$componentIndex}].{$field} lacks numeric evidence.";
                        }
                    }
                }
            }
        }

        $this->validateStructuredDiscountCoverage($output, $input, $errors);

        $monthlyPackageFacts = $this->monthlyExcessPackageFacts($input);
        $isFlatPackageSource = $this->isFlatPackageSource($input);
        $expectedTypes = collect($input['components'] ?? [])
            ->map(function (array $component) use ($monthlyPackageFacts, $isFlatPackageSource, $output, $input): ?string {
                if ($monthlyPackageFacts !== null
                    && in_array($component['price_component_type'] ?? null, ['Monthly', 'General'], true)) {
                    return null;
                }
                if ($isFlatPackageSource && ($component['price_component_type'] ?? null) === 'Monthly') {
                    return 'flat_fee';
                }
                if ($isFlatPackageSource
                    && ($component['price_component_type'] ?? null) === 'General'
                    && (float) ($component['price'] ?? -1) === 0.0) {
                    return null;
                }

                return $this->interpretedComponentType(
                    $component['price_component_type'] ?? null,
                    $output['classification']['primary_pricing_model'] ?? $input['pricing_model'] ?? null,
                );
            })
            ->filter()
            ->unique();
        foreach ($expectedTypes as $expectedType) {
            $satisfied = in_array($expectedType, $interpretedTypes, true);
            // A source Monthly charge legitimately maps to either monthly_fee or flat_fee (both
            // represent the monthly charge and cost the same). Accept flat_fee for an expected
            // monthly_fee so a package-named product (e.g. Kuukausipaketti) that the model reads as
            // a flat_fee package is not rejected purely over the label.
            if (! $satisfied && $expectedType === 'monthly_fee' && in_array('flat_fee', $interpretedTypes, true)) {
                $satisfied = true;
            }
            if (! $satisfied) {
                $errors[] = "$.pricing.phases is missing structured component type {$expectedType}.";
            }
        }

        $consumptionEffect = $output['pricing']['consumption_effect'] ?? [];
        $consumptionEvidence = $this->evidenceText($consumptionEffect['evidence'] ?? []);
        foreach ([
            'expected_cents_per_kwh',
            'typical_min_cents_per_kwh',
            'typical_max_cents_per_kwh',
            'hard_min_cents_per_kwh',
            'hard_max_cents_per_kwh',
        ] as $field) {
            $number = $consumptionEffect[$field] ?? null;
            if ((is_int($number) || is_float($number))
                && ! $this->textContainsNumber($consumptionEvidence, $number)) {
                $errors[] = "$.pricing.consumption_effect.{$field} lacks numeric evidence.";
            }
        }
    }

    /**
     * Description silence does not make complete structured prices unassessable.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validateStructuredOnlyConsistency(array $output, array $input, array &$errors): void
    {
        $hasDescription = collect([
            $input['short_description'] ?? null,
            $input['long_description'] ?? null,
            $input['extra_information_fi'] ?? null,
            $input['extra_information_default'] ?? null,
        ])->contains(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $sourceModel = $input['pricing_model'] ?? null;
        $components = collect($input['components'] ?? []);

        if ($hasDescription
            || ! in_array($sourceModel, ['FixedPrice', 'Spot'], true)
            || $components->isEmpty()
            || $components->contains(fn (array $component): bool => ($component['has_discount'] ?? false) === true)
            || $this->isFlatPackageSource($input)
            || $this->detectSourceRecurringResetCadence($input) !== null
            || ($output['classification']['primary_pricing_model'] ?? null) !== $sourceModel) {
            return;
        }

        $expectedTypes = $components->map(
            fn (array $component): ?string => $this->interpretedComponentType(
                $component['price_component_type'] ?? null,
                $sourceModel,
            ),
        );
        if ($expectedTypes->contains(null)) {
            return;
        }

        $actualTypes = collect($output['pricing']['phases'] ?? [])
            ->flatMap(fn (array $phase): array => $phase['components'] ?? [])
            ->pluck('component_type');
        if ($expectedTypes->unique()->diff($actualTypes)->isNotEmpty()) {
            return;
        }

        $consistency = $output['source_consistency'] ?? [];
        if (($consistency['pricing_model_status'] ?? null) !== 'match') {
            $errors[] = '$.source_consistency.pricing_model_status must be match when complete structured-only pricing preserves the source model.';
        }
        if (($consistency['structured_pricing_status'] ?? null) !== 'complete') {
            $errors[] = '$.source_consistency.structured_pricing_status must be complete when recognized non-discounted structured components contain all available pricing facts.';
        }
        if (($consistency['misleading_first_12_months'] ?? null) !== 'not_detected') {
            $errors[] = '$.source_consistency.misleading_first_12_months must be not_detected for complete structured-only pricing.';
        }
        if (in_array('insufficient_evidence', $consistency['issue_codes'] ?? [], true)) {
            $errors[] = '$.source_consistency.issue_codes must not contain insufficient_evidence only because descriptive pricing text is absent.';
        }

        $expectedCalculation = $sourceModel === 'Spot' ? 'estimate_required' : 'exact';
        if (($output['calculation']['status'] ?? null) !== $expectedCalculation) {
            $errors[] = "$.calculation.status must be {$expectedCalculation} for complete structured-only {$sourceModel} pricing.";
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validateTemporalConsistency(array $output, array $input, array &$errors): void
    {
        $analysisDateValue = $input['analysis_date'] ?? null;
        if (! is_string($analysisDateValue) || ! $this->isDate($analysisDateValue)) {
            return;
        }

        $analysisDate = new DateTimeImmutable($analysisDateValue);
        foreach ($output['pricing']['phases'] ?? [] as $phaseIndex => $phase) {
            $end = $phase['ends'] ?? [];
            $endValue = $end['value'] ?? null;
            if (($end['kind'] ?? null) !== 'date'
                || ! is_string($endValue)
                || ! $this->isDate($endValue)) {
                continue;
            }

            if (new DateTimeImmutable($endValue) < $analysisDate) {
                $errors[] = "$.pricing.phases[{$phaseIndex}] ends before analysis_date and must not affect the current interpretation.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  list<string>  $errors
     */
    private function validateWarningConsistency(array $output, array &$errors): void
    {
        $consistency = $output['source_consistency'] ?? [];
        if (($consistency['structured_pricing_status'] ?? null) !== 'complete'
            || ($consistency['misleading_first_12_months'] ?? null) !== 'uncertain') {
            return;
        }

        $directionalIssues = [
            'promotion_metadata_missing',
            'structured_matches_intro_only',
            'future_price_omitted',
            'future_price_unknown',
            'pricing_model_mismatch',
            'component_mismatch',
            'unsupported_consumption_effect',
            'recurring_reset_requires_estimate',
        ];
        if (array_intersect($consistency['issue_codes'] ?? [], $directionalIssues) === []) {
            $errors[] = '$.source_consistency.misleading_first_12_months must be not_detected when pricing is complete with no directional issue.';
        }
    }

    /**
     * @param  array<string, mixed>  $phase
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validateActiveDiscountEvidence(
        array $phase,
        int $phaseIndex,
        array $input,
        array &$errors,
    ): void {
        $evidence = collect($phase['evidence'] ?? [])
            ->merge(collect($phase['components'] ?? [])->flatMap(
                fn (array $component): array => $component['evidence'] ?? [],
            ));
        $componentIndexes = $evidence
            ->pluck('source')
            ->filter(fn (mixed $source): bool => is_string($source))
            ->map(fn (string $source): ?int => preg_match(
                '/^components\[(\d+)]\.(?:discount_n_first_months|discount_n_first_kwh|discount_until_date)$/',
                $source,
                $matches,
            ) === 1 ? (int) $matches[1] : null)
            ->filter(fn (?int $index): bool => $index !== null)
            ->unique();

        foreach ($componentIndexes as $componentIndex) {
            if (($input['components'][$componentIndex]['has_discount'] ?? false) !== true) {
                $errors[] = "$.pricing.phases[{$phaseIndex}] must not use inactive discount timing from components[{$componentIndex}] when has_discount is false.";
            }
        }
    }

    /**
     * Require every current structured discount to survive as an exact canonical timeline.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validateStructuredDiscountCoverage(array $output, array $input, array &$errors): void
    {
        $analysisDateValue = $input['analysis_date'] ?? null;
        $analysisDate = is_string($analysisDateValue) && $this->isDate($analysisDateValue)
            ? new DateTimeImmutable($analysisDateValue)
            : null;
        $phases = $output['pricing']['phases'] ?? [];
        $pricingModel = $output['classification']['primary_pricing_model']
            ?? $input['pricing_model']
            ?? null;
        $packageFacts = $this->monthlyExcessPackageFacts($input);

        foreach ($input['components'] ?? [] as $componentIndex => $sourceComponent) {
            if (($sourceComponent['has_discount'] ?? false) !== true) {
                continue;
            }

            $discountType = $sourceComponent['discount_type'] ?? null;
            if ($this->isMonthlyPackageAllowanceDiscount($sourceComponent, $packageFacts)) {
                continue;
            }

            // Expired absolute discounts are historical evidence. Ignore them before
            // validating amount fields, because stale metadata can be incomplete and must
            // not create a current-phase requirement.
            if ($discountType === 'UntilDate') {
                $untilDateValue = $sourceComponent['discount_until_date'] ?? null;
                $untilDate = is_string($untilDateValue) ? substr($untilDateValue, 0, 10) : null;
                if ($analysisDate !== null && is_string($untilDate) && $this->isDate($untilDate)
                    && new DateTimeImmutable($untilDate) < $analysisDate) {
                    continue;
                }
            }

            $componentTypes = $this->discountComponentTypes(
                $sourceComponent['price_component_type'] ?? null,
                $pricingModel,
            );
            if ($componentTypes === []) {
                $errors[] = "components[{$componentIndex}] has an active structured discount whose component scope cannot be represented safely.";

                continue;
            }

            $normalAmount = $sourceComponent['price'] ?? null;
            $discountValue = $sourceComponent['discount_value'] ?? null;
            $isPercentage = $sourceComponent['discount_is_percentage'] ?? null;
            if (! is_numeric($normalAmount)
                || ! is_numeric($discountValue)
                || ! is_bool($isPercentage)
                || (float) $normalAmount < 0
                || (float) $discountValue <= 0) {
                $errors[] = "components[{$componentIndex}] has an active structured discount whose amount cannot be represented safely.";

                continue;
            }

            $discount = $isPercentage
                ? (float) $normalAmount * ((float) $discountValue / 100)
                : (float) $discountValue;
            $discountedAmount = max(0.0, (float) $normalAmount - min((float) $normalAmount, $discount));

            $discountBoundary = null;
            $continuationBoundary = null;
            $periodDescription = null;

            if ($discountType === 'UntilDate') {
                $untilDateValue = $sourceComponent['discount_until_date'] ?? null;
                $untilDate = is_string($untilDateValue) ? substr($untilDateValue, 0, 10) : null;
                if ($analysisDate === null || ! is_string($untilDate) || ! $this->isDate($untilDate)) {
                    $errors[] = "components[{$componentIndex}] has an active UntilDate discount whose timing cannot be represented safely.";

                    continue;
                }

                $endDate = new DateTimeImmutable($untilDate);
                if ($endDate < $analysisDate) {
                    continue;
                }

                $discountBoundary = fn (array $phase): bool => $this->phaseCoversActiveUntilDate(
                    $phase,
                    $analysisDate,
                    $untilDate,
                );
                $continuationDate = $endDate->modify('+1 day')->format('Y-m-d');
                $continuationBoundary = fn (array $phase): bool => ($phase['starts']['kind'] ?? null) === 'date'
                    && ($phase['starts']['value'] ?? null) === $continuationDate;
                $periodDescription = "through {$untilDate}";
            } elseif ($discountType === 'NFirstMonth') {
                $months = $sourceComponent['discount_n_first_months'] ?? null;
                if (! is_numeric($months) || (int) $months <= 0 || (float) $months !== (float) (int) $months) {
                    $errors[] = "components[{$componentIndex}] has an active first-N-month discount whose timing cannot be represented safely.";

                    continue;
                }

                $months = (int) $months;
                $discountBoundary = fn (array $phase): bool => $this->phaseCoversFirstMonths($phase, $months);
                $continuationBoundary = fn (array $phase): bool => ($phase['starts']['kind'] ?? null) === 'after_months'
                    && (int) ($phase['starts']['value'] ?? -1) === $months;
                $periodDescription = "for the first {$months} months";
            } else {
                $type = is_string($discountType) && $discountType !== '' ? $discountType : 'missing type';
                $errors[] = "components[{$componentIndex}] has an active structured discount with {$type} timing that canonical phases cannot represent safely.";

                continue;
            }

            $discountPhases = collect($phases)->filter($discountBoundary);
            if ($discountPhases->isEmpty()) {
                $errors[] = "$.pricing.phases must represent the active structured discount from components[{$componentIndex}] {$periodDescription}.";

                continue;
            }

            $scopedDiscountComponents = $discountPhases->flatMap(
                fn (array $phase): array => $this->componentsWithTypes($phase, $componentTypes),
            );
            if ($scopedDiscountComponents->isEmpty()) {
                $errors[] = "$.pricing.phases must represent the active structured discount from components[{$componentIndex}] on {$this->componentTypeDescription($componentTypes)}; another component scope cannot satisfy it.";
            } elseif ($scopedDiscountComponents->count() !== 1
                || ! $scopedDiscountComponents->contains(
                    fn (array $component): bool => $this->amountEquals($component['amount'] ?? null, $discountedAmount)
                        && $this->amountEquals($component['normal_amount'] ?? null, (float) $normalAmount),
                )) {
                $errors[] = "$.pricing.phases must represent the active structured discount from components[{$componentIndex}] with amount {$this->formatAmount($discountedAmount)} and normal_amount {$this->formatAmount((float) $normalAmount)}.";
            }

            $continuationPhases = collect($phases)->filter($continuationBoundary);
            $continuationComponents = $continuationPhases->flatMap(
                fn (array $phase): array => $this->componentsWithTypes($phase, $componentTypes),
            );
            if ($continuationComponents->count() !== 1
                || ! $continuationComponents->contains(
                    fn (array $component): bool => $this->amountEquals($component['amount'] ?? null, (float) $normalAmount),
                )) {
                $errors[] = "$.pricing.phases must continue components[{$componentIndex}] as {$this->componentTypeDescription($componentTypes)} at the known normal amount {$this->formatAmount((float) $normalAmount)} after the structured discount ends.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sourceComponent
     * @param  array{monthly_fee_eur: float, included_kwh: float, excess_rate_cents_per_kwh: float}|null  $packageFacts
     */
    private function isMonthlyPackageAllowanceDiscount(array $sourceComponent, ?array $packageFacts): bool
    {
        if ($packageFacts === null
            || ($sourceComponent['price_component_type'] ?? null) !== 'General'
            || ($sourceComponent['has_discount'] ?? null) !== true
            || ($sourceComponent['discount_type'] ?? null) !== 'NFirstKwh'
            || ($sourceComponent['discount_is_percentage'] ?? null) !== false
            || ! is_numeric($sourceComponent['price'] ?? null)
            || ! is_numeric($sourceComponent['discount_value'] ?? null)
            || ! is_numeric($sourceComponent['discount_n_first_kwh'] ?? null)) {
            return false;
        }

        return abs((float) $sourceComponent['discount_n_first_kwh'] - ($packageFacts['included_kwh'] * 12)) < 0.000001
            && abs((float) $sourceComponent['discount_value'] - (float) $sourceComponent['price']) < 0.000001
            && abs((float) $sourceComponent['price'] - $packageFacts['excess_rate_cents_per_kwh']) < 0.000001;
    }

    /**
     * @return list<string>
     */
    private function discountComponentTypes(mixed $sourceType, mixed $pricingModel): array
    {
        if ($sourceType === 'Monthly') {
            return ['monthly_fee', 'flat_fee'];
        }

        $type = $this->interpretedComponentType($sourceType, $pricingModel);

        return $type === null ? [] : [$type];
    }

    /**
     * @param  array<string, mixed>  $phase
     */
    private function phaseCoversActiveUntilDate(
        array $phase,
        DateTimeImmutable $analysisDate,
        string $untilDate,
    ): bool {
        if (($phase['ends']['kind'] ?? null) !== 'date'
            || ($phase['ends']['value'] ?? null) !== $untilDate) {
            return false;
        }

        $start = $phase['starts'] ?? [];
        if (in_array($start['kind'] ?? null, ['contract_start', 'unknown'], true)) {
            return true;
        }

        $startDate = $start['value'] ?? null;

        return ($start['kind'] ?? null) === 'date'
            && is_string($startDate)
            && $this->isDate($startDate)
            && new DateTimeImmutable($startDate) <= $analysisDate;
    }

    /**
     * @param  array<string, mixed>  $phase
     */
    private function phaseCoversFirstMonths(array $phase, int $months): bool
    {
        $startsAtContractStart = ($phase['starts']['kind'] ?? null) === 'contract_start'
            || (($phase['starts']['kind'] ?? null) === 'after_months'
                && (int) ($phase['starts']['value'] ?? -1) === 0);

        return $startsAtContractStart
            && ($phase['ends']['kind'] ?? null) === 'after_months'
            && (int) ($phase['ends']['value'] ?? -1) === $months;
    }

    /**
     * @param  array<string, mixed>  $phase
     * @param  list<string>  $componentTypes
     * @return list<array<string, mixed>>
     */
    private function componentsWithTypes(array $phase, array $componentTypes): array
    {
        return array_values(array_filter(
            $phase['components'] ?? [],
            fn (array $component): bool => in_array($component['component_type'] ?? null, $componentTypes, true),
        ));
    }

    private function amountEquals(mixed $actual, float $expected): bool
    {
        return is_numeric($actual) && abs((float) $actual - $expected) < 0.000001;
    }

    /**
     * @param  list<string>  $componentTypes
     */
    private function componentTypeDescription(array $componentTypes): string
    {
        return implode(' or ', $componentTypes);
    }

    private function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, mixed>  $phase
     * @param  list<string>  $errors
     */
    private function validateBoundaryOrder(array $phase, int $phaseIndex, array &$errors): void
    {
        $start = $phase['starts'] ?? [];
        $end = $phase['ends'] ?? [];
        if (($start['kind'] ?? null) !== ($end['kind'] ?? null)
            || ! in_array($start['kind'] ?? null, ['date', 'after_months'], true)) {
            return;
        }

        $startValue = $start['value'] ?? null;
        $endValue = $end['value'] ?? null;
        if (($start['kind'] ?? null) === 'after_months') {
            $startValue = is_numeric($startValue) ? (int) $startValue : $startValue;
            $endValue = is_numeric($endValue) ? (int) $endValue : $endValue;
        }
        if ($startValue !== null && $endValue !== null && $startValue >= $endValue) {
            $errors[] = "$.pricing.phases[{$phaseIndex}] has a backwards or empty boundary range.";
        }
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     */
    private function evidenceText(array $evidence): string
    {
        return implode(' ', array_map(
            fn (array $item): string => (string) ($item['quote'] ?? ''),
            $evidence,
        ));
    }

    private function textContainsNumber(string $text, int|float $number): bool
    {
        preg_match_all('/(?<![\d.,])[-+]?\d+(?:[.,]\d+)?(?![\d.,])/u', $text, $matches);
        $hasExactNumber = collect($matches[0] ?? [])->contains(
            fn (string $candidate): bool => abs((float) str_replace(',', '.', $candidate) - $number) < 0.000001,
        );
        if ($hasExactNumber) {
            return true;
        }

        preg_match_all(
            '/(?:±|\+\s*\/\s*[-−–—]|\+\s*[-−–—])\s*(\d+(?:[.,]\d+)?)(?![\d.,])/u',
            $text,
            $symmetricMatches,
        );

        return collect($symmetricMatches[1] ?? [])->contains(
            fn (string $candidate): bool => abs(
                (float) str_replace(',', '.', $candidate) - abs((float) $number),
            ) < 0.000001,
        );
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $phase
     * @param  array<string, mixed>  $input
     */
    private function hasDerivedDiscountEvidence(
        array $component,
        array $phase,
        int|float $amount,
        array $input,
    ): bool {
        $sources = collect($component['evidence'] ?? [])
            ->pluck('source')
            ->filter(fn (mixed $source): bool => is_string($source));
        $componentIndexes = $sources
            ->map(fn (string $source): ?int => preg_match('/^components\[(\d+)]\./', $source, $matches) === 1
                ? (int) $matches[1]
                : null)
            ->filter(fn (?int $index): bool => $index !== null)
            ->unique();

        if ($componentIndexes->count() !== 1) {
            return false;
        }

        $index = $componentIndexes->first();
        $sourceComponent = $input['components'][$index] ?? null;
        if (! is_array($sourceComponent) || ($sourceComponent['has_discount'] ?? false) !== true) {
            return false;
        }

        $requiredFields = ['price', 'has_discount', 'discount_value', 'discount_is_percentage'];
        foreach ($requiredFields as $field) {
            if (! $sources->contains("components[{$index}].{$field}")) {
                return false;
            }
        }

        $price = $sourceComponent['price'] ?? null;
        $discountValue = $sourceComponent['discount_value'] ?? null;
        if (! is_numeric($price) || ! is_numeric($discountValue)) {
            return false;
        }

        $normalAmount = $component['normal_amount'] ?? null;
        if (! is_numeric($normalAmount) || abs((float) $normalAmount - (float) $price) >= 0.000001) {
            return false;
        }

        $discount = ($sourceComponent['discount_is_percentage'] ?? false)
            ? (float) $price * ((float) $discountValue / 100)
            : (float) $discountValue;
        $expectedAmount = max(0.0, (float) $price - min((float) $price, $discount));
        if (abs($expectedAmount - (float) $amount) >= 0.000001) {
            return false;
        }

        $discountType = $sourceComponent['discount_type'] ?? null;
        if (is_string($discountType) && $discountType !== '') {
            if (! $sources->contains("components[{$index}].discount_type")) {
                return false;
            }

            if ($discountType === 'NFirstKwh') {
                return false;
            }
            if ($discountType === 'NFirstMonth') {
                $months = $sourceComponent['discount_n_first_months'] ?? null;

                $startsAtContractStart = ($phase['starts']['kind'] ?? null) === 'contract_start'
                    || (($phase['starts']['kind'] ?? null) === 'after_months'
                        && (int) ($phase['starts']['value'] ?? -1) === 0);

                return is_numeric($months)
                    && $sources->contains("components[{$index}].discount_n_first_months")
                    && $startsAtContractStart
                    && ($phase['ends']['kind'] ?? null) === 'after_months'
                    && (int) ($phase['ends']['value'] ?? -1) === (int) $months;
            }
            if ($discountType === 'UntilDate') {
                $untilDate = $sourceComponent['discount_until_date'] ?? null;

                return is_string($untilDate)
                    && $sources->contains("components[{$index}].discount_until_date")
                    && ($phase['ends']['kind'] ?? null) === 'date'
                    && ($phase['ends']['value'] ?? null) === substr($untilDate, 0, 10);
            }
        }

        return true;
    }

    private function interpretedComponentType(mixed $sourceType, mixed $pricingModel): ?string
    {
        // On a Spot contract every supplier c/kWh energy adder is a margin over the market price,
        // whichever tariff slot the source entered it in (General/DayTime/NightTime/Seasonal).
        if ($pricingModel === 'Spot'
            && in_array($sourceType, ['General', 'DayTime', 'NightTime', 'SeasonalWinter', 'SeasonalWinterDay', 'SeasonalOther'], true)) {
            return 'spot_margin';
        }

        return match ($sourceType) {
            'General' => 'energy_general',
            'DayTime' => 'energy_day',
            'NightTime' => 'energy_night',
            'SeasonalWinter', 'SeasonalWinterDay' => 'energy_seasonal_winter',
            'SeasonalOther' => 'energy_seasonal_other',
            'Monthly' => 'monthly_fee',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validateMechanismConsistency(array $output, array $input, array &$errors): void
    {
        $classification = $output['classification'] ?? [];
        $mechanisms = $classification['pricing_mechanisms'] ?? [];
        $interpretedComponents = collect($output['pricing']['phases'] ?? [])
            ->flatMap(fn (array $phase): array => $phase['components'] ?? []);
        $componentTypes = $interpretedComponents
            ->pluck('component_type')
            ->filter()
            ->unique();
        $hasMechanism = fn (string $mechanism): bool => in_array($mechanism, $mechanisms, true);
        $hasComponent = fn (string $component): bool => $componentTypes->contains($component);
        $hasAnyComponent = fn (array $components): bool => $componentTypes->intersect($components)->isNotEmpty();

        $fixedEnergyComponents = [
            'energy_general',
            'energy_day',
            'energy_night',
            'energy_seasonal_winter',
            'energy_seasonal_other',
        ];
        if (($classification['primary_pricing_model'] ?? null) === 'Spot'
            && $hasMechanism('fixed')
            && ! $hasAnyComponent($fixedEnergyComponents)) {
            $errors[] = '$.classification.pricing_mechanisms contains fixed without a fixed energy-price component.';
        }

        // On a Spot contract a small per-kWh energy adder is the supplier margin, not a fixed
        // energy price, whichever tariff slot it was entered in. Anything at or below the margin
        // ceiling must be spot_margin; a larger value is a genuine all-in market/intro price and
        // is left alone. Ceiling mirrors CanonicalContractPriceCalculator::SPOT_MARGIN_CEILING_CENTS.
        if (($classification['primary_pricing_model'] ?? null) === 'Spot') {
            $hasSmallFixedEnergy = $interpretedComponents->contains(
                fn (array $component): bool => in_array($component['component_type'] ?? null, $fixedEnergyComponents, true)
                    && (is_int($component['amount'] ?? null) || is_float($component['amount'] ?? null))
                    && (float) $component['amount'] > 0.0
                    && (float) $component['amount'] <= self::SPOT_MARGIN_CEILING_CENTS,
            );
            if ($hasSmallFixedEnergy) {
                $errors[] = '$.pricing.phases: on a Spot contract a per-kWh energy adder at or below the margin ceiling must be spot_margin, not a fixed energy component.';
            }
        }

        $packages = collect($output['pricing']['phases'] ?? [])
            ->pluck('package')
            ->filter(fn (mixed $package): bool => is_array($package));
        $hasPackage = $packages->isNotEmpty();
        if ($hasMechanism('flat_fee_or_package') && ! $hasComponent('flat_fee') && ! $hasPackage) {
            $errors[] = '$.classification.pricing_mechanisms contains flat_fee_or_package without a flat_fee component or package.';
        }
        if (($hasComponent('flat_fee') || $hasPackage) && ! $hasMechanism('flat_fee_or_package')) {
            $errors[] = '$.classification.pricing_mechanisms must contain flat_fee_or_package for a flat_fee component or package.';
        }

        $monthlyPackageFacts = $this->monthlyExcessPackageFacts($input);
        if ($monthlyPackageFacts !== null) {
            if (! $hasMechanism('flat_fee_or_package') || ! $hasMechanism('fixed')) {
                $errors[] = '$.classification.pricing_mechanisms must contain flat_fee_or_package and fixed for a monthly included-energy package.';
            }
            if ($packages->count() !== 1) {
                $errors[] = '$.pricing.phases must contain exactly one package for a monthly included-energy package.';
            } else {
                $package = $packages->first();
                foreach (['monthly_fee_eur', 'included_kwh', 'excess_rate_cents_per_kwh'] as $field) {
                    if (! is_numeric($package[$field] ?? null)
                        || abs((float) $package[$field] - $monthlyPackageFacts[$field]) >= 0.000001) {
                        $errors[] = "$.pricing.phases package {$field} must match the disclosed package value.";
                    }
                }
                if (($package['allowance_cadence'] ?? null) !== 'monthly') {
                    $errors[] = '$.pricing.phases package allowance_cadence must be monthly.';
                }
            }
            if ($hasComponent('flat_fee') || $hasComponent('monthly_fee') || $hasAnyComponent($fixedEnergyComponents)) {
                $errors[] = '$.pricing.phases must not duplicate a monthly package fee or excess rate as components.';
            }
            if (($output['calculation']['status'] ?? null) !== 'exact') {
                $errors[] = '$.calculation.status must be exact when monthly package fee, allowance, and excess rate are complete.';
            }
            if (($output['source_consistency']['misleading_first_12_months'] ?? null) !== 'not_detected') {
                $errors[] = '$.source_consistency.misleading_first_12_months must be not_detected for package pricing without a separate promotion.';
            }
        } elseif ($this->isFlatPackageSource($input)) {
            if (! $hasMechanism('flat_fee_or_package')) {
                $errors[] = '$.classification.pricing_mechanisms must contain flat_fee_or_package because the source explicitly describes a consumption package.';
            }
            if (! $hasComponent('flat_fee')) {
                $errors[] = '$.pricing.phases must map the package Monthly charge to a flat_fee component.';
            }
            $hasUnknownFlatFee = $interpretedComponents->contains(
                fn (array $component): bool => ($component['component_type'] ?? null) === 'flat_fee'
                    && ! is_int($component['amount'] ?? null)
                    && ! is_float($component['amount'] ?? null),
            );
            if ($hasUnknownFlatFee) {
                $errors[] = '$.pricing.phases must not create an unknown flat_fee placeholder from included package energy.';
            }
            $hasZeroUnitEnergy = $interpretedComponents->contains(
                fn (array $component): bool => ($component['component_type'] ?? null) === 'energy_general'
                    && (float) ($component['amount'] ?? -1) === 0.0,
            );
            if ($hasZeroUnitEnergy) {
                $errors[] = '$.pricing.phases must not represent zero-price included package energy as energy_general.';
            }
            $hasPositiveFixedEnergy = $interpretedComponents->contains(
                fn (array $component): bool => in_array(
                    $component['component_type'] ?? null,
                    $fixedEnergyComponents,
                    true,
                ) && (float) ($component['amount'] ?? 0) > 0,
            );
            if ($hasMechanism('fixed') && ! $hasPositiveFixedEnergy) {
                $errors[] = '$.classification.pricing_mechanisms must not contain fixed for a package without a positive fixed energy-price component.';
            }
        }

        $hasSeasonalComponents = $hasAnyComponent(['energy_seasonal_winter', 'energy_seasonal_other']);
        if ($hasMechanism('seasonal') !== $hasSeasonalComponents) {
            $errors[] = '$.classification.pricing_mechanisms seasonal must match seasonal energy components.';
        }

        $hasTimeComponents = $hasAnyComponent(['energy_day', 'energy_night']);
        if ($hasMechanism('time_of_use') !== $hasTimeComponents) {
            $errors[] = '$.classification.pricing_mechanisms time_of_use must match day/night energy components.';
        }

        $hasConsumptionEffect = ($output['pricing']['consumption_effect']['present'] ?? false) === true;
        if ($hasMechanism('consumption_effect') !== $hasConsumptionEffect) {
            $errors[] = '$.classification.pricing_mechanisms consumption_effect must match pricing.consumption_effect.present.';
        }

        $recurringSchedule = $output['pricing']['recurring_schedule'] ?? [];
        $hasRecurringSchedule = ($recurringSchedule['present'] ?? false) === true;
        $classificationCadence = $classification['periodic_reset_cadence'] ?? 'none';
        $scheduleCadence = $recurringSchedule['cadence'] ?? 'none';

        if ($hasMechanism('periodic_market_reset') !== $hasRecurringSchedule) {
            $errors[] = '$.classification.pricing_mechanisms periodic_market_reset must match pricing.recurring_schedule.present.';
        }
        if ($hasRecurringSchedule && ($classificationCadence === 'none' || $scheduleCadence === 'none')) {
            $errors[] = 'A present recurring schedule must have a non-none cadence in classification and pricing.';
        }
        if ($hasRecurringSchedule && $classificationCadence !== $scheduleCadence) {
            $errors[] = '$.classification.periodic_reset_cadence must match $.pricing.recurring_schedule.cadence.';
        }
        if (! $hasRecurringSchedule && ($classificationCadence !== 'none' || $scheduleCadence !== 'none')) {
            $errors[] = 'An absent recurring schedule must use none cadence in classification and pricing.';
        }
        if ($hasRecurringSchedule
            && ($recurringSchedule['future_price_known'] ?? null) === false
            && ($output['calculation']['status'] ?? null) === 'exact') {
            $errors[] = '$.calculation.status cannot be exact when a recurring future price is unknown.';
        }

        $issueCodes = $output['source_consistency']['issue_codes'] ?? [];
        $isRecurringEstimateOnly = $hasRecurringSchedule
            && ($recurringSchedule['future_price_known'] ?? null) === false
            && in_array('recurring_reset_requires_estimate', $issueCodes, true)
            && array_diff($issueCodes, ['recurring_reset_requires_estimate', 'structured_matches_description']) === [];
        if ($isRecurringEstimateOnly) {
            if (($output['source_consistency']['structured_pricing_status'] ?? null) === 'incomplete') {
                $errors[] = '$.source_consistency.structured_pricing_status cannot be incomplete solely because recurring future market prices are unknown.';
            }
            if (($output['source_consistency']['misleading_first_12_months'] ?? null) !== 'uncertain') {
                $errors[] = '$.source_consistency.misleading_first_12_months must be uncertain when only unknown recurring future market prices require estimation.';
            }
            if (($output['calculation']['status'] ?? null) !== 'estimate_required') {
                $errors[] = '$.calculation.status must be estimate_required when only unknown recurring future market prices prevent an exact calculation.';
            }
        }

        // A periodic market-reset product (e.g. Kvartaalisähkö) is not deceptive for its own price
        // path: the price varying between periods is the disclosed nature of the product, and whether
        // the next period's rate is disclosed is not a signal in either direction. So detected on a
        // reset product requires a genuine deception independent of the reset — expressed by a code
        // other than the reset/intro/future ones below. When only those reset-path codes are present,
        // the flag must be uncertain, not detected.
        $resetPathCodes = [
            'recurring_reset_requires_estimate',
            'promotion_metadata_missing',
            'structured_matches_intro_only',
            'future_price_omitted',
            'future_price_unknown',
            'structured_matches_description',
        ];
        if ($hasRecurringSchedule
            && ($output['source_consistency']['misleading_first_12_months'] ?? null) === 'detected'
            && array_diff($issueCodes, $resetPathCodes) === []) {
            $errors[] = '$.source_consistency.misleading_first_12_months must not be detected for a periodic market-reset product whose only issues describe the reset/intro price path; use uncertain.';
        }

        $sourceCadence = $this->detectSourceRecurringResetCadence($input);
        if ($sourceCadence !== null) {
            if (! $hasMechanism('periodic_market_reset')) {
                $errors[] = '$.classification.pricing_mechanisms must contain periodic_market_reset because the source explicitly describes recurring price resets.';
            }
            if (! $hasRecurringSchedule || $scheduleCadence !== $sourceCadence) {
                $errors[] = "$.pricing.recurring_schedule must be present with {$sourceCadence} cadence because the source explicitly describes that reset schedule.";
            }
            if ($classificationCadence !== $sourceCadence) {
                $errors[] = "$.classification.periodic_reset_cadence must be {$sourceCadence} because the source explicitly describes that reset schedule.";
            }
        }
    }

    /**
     * Return only cadences supported by strong, explicit source language.
     *
     * @param  array<string, mixed>  $input
     */
    private function detectSourceRecurringResetCadence(array $input): ?string
    {
        $text = collect([
            $input['contract_name'] ?? null,
            $input['pricing_name'] ?? null,
            $input['short_description'] ?? null,
            $input['long_description'] ?? null,
            $input['extra_information_fi'] ?? null,
            $input['extra_information_default'] ?? null,
        ])->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->implode(' ');
        $text = mb_strtolower($text, 'UTF-8');

        if (preg_match('/kvartaali(?:sähkö|sahko)/u', $text) === 1
            || preg_match('/(?:hinta|hinnoitellaan)[^.]{0,100}(?:neljästi|neljä kertaa) vuodessa/u', $text) === 1
            || preg_match('/(?:hinta|hinnoitellaan)[^.]{0,100}kolmen kuukauden (?:jaksoissa|välein)/u', $text) === 1) {
            return 'quarterly';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validateClassificationConsistency(array $output, array $input, array &$errors): void
    {
        $classification = $output['classification'] ?? [];
        $consistency = $output['source_consistency'] ?? [];
        $checks = [
            'pricing_model' => [
                'actual' => $classification['primary_pricing_model'] ?? null,
                'recommended' => $consistency['recommended_pricing_model'] ?? null,
                'status' => $consistency['pricing_model_status'] ?? null,
                'source' => $input['pricing_model'] ?? null,
            ],
            'contract_type' => [
                'actual' => $classification['term_type'] ?? null,
                'recommended' => $consistency['recommended_contract_type'] ?? null,
                'status' => $consistency['contract_type_status'] ?? null,
                'source' => ($input['contract_type'] ?? null) === 'Fixed'
                    ? 'FixedTerm'
                    : ($input['contract_type'] ?? null),
            ],
            'metering' => [
                'actual' => $classification['metering'] ?? null,
                'recommended' => $consistency['recommended_metering'] ?? null,
                'status' => $consistency['metering_status'] ?? null,
                'source' => $input['metering'] ?? null,
            ],
        ];

        foreach ($checks as $name => $check) {
            if (! in_array($check['actual'], ['Unknown', null], true)
                && ! in_array($check['recommended'], ['Unknown', null], true)
                && $check['actual'] !== $check['recommended']) {
                $errors[] = "$.classification.{$name} conflicts with the recommended value.";
            }

            if ($check['status'] === 'match'
                && ! in_array($check['actual'], ['Unknown', null], true)
                && $check['source'] !== null
                && $check['actual'] !== $check['source']) {
                $errors[] = "$.source_consistency.{$name}_status says match but values differ.";
            }

            if ($check['status'] === 'mismatch'
                && $check['actual'] === $check['source']) {
                $errors[] = "$.source_consistency.{$name}_status says mismatch but values match.";
            }
        }

        $hasMismatch = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'mismatch');
        $hasTextEvidence = collect($consistency['evidence'] ?? [])->contains(
            fn (mixed $evidence): bool => is_array($evidence)
                && isset($evidence['source'])
                && preg_match('/contract_name|description|extra_information/i', (string) $evidence['source']) === 1
        );
        if ($hasMismatch && ! $hasTextEvidence) {
            $errors[] = '$.source_consistency.evidence must cite source text for a classification correction.';
        }

        $sourcePricingModel = $input['pricing_model'] ?? null;
        $interpretedPricingModel = $classification['primary_pricing_model'] ?? null;
        if ($sourcePricingModel === 'Hybrid'
            && $interpretedPricingModel !== 'Hybrid'
            && ! $this->hasExplicitHybridContradiction($input, $interpretedPricingModel)) {
            $errors[] = '$.classification.primary_pricing_model must retain source Hybrid when no explicit contrary evidence exists.';
        }
    }

    /**
     * Return deterministic source values for a monthly included-energy package.
     *
     * @param  array<string, mixed>  $input
     * @return array{monthly_fee_eur: float, included_kwh: float, excess_rate_cents_per_kwh: float}|null
     */
    private function monthlyExcessPackageFacts(array $input): ?array
    {
        $text = mb_strtolower($this->sourceText($input), 'UTF-8');
        if (preg_match('/(?<!\p{L})(?:\p{L}*paket\p{L}*|package)(?!\p{L})/u', $text) !== 1
            || ! $this->hasExplicitMonthlyExcessUse($text)
            || preg_match('/(?:sisältää|sisaltaa)[^.!?]{0,80}?(\d+(?:[,.]\d+)?)\s*kwh[^.!?]{0,80}(?:kuukaudessa|\/\s*kk|per month)/u', $text, $allowanceMatch) !== 1) {
            return null;
        }

        $monthlyComponents = collect($input['components'] ?? [])
            ->filter(fn (array $component): bool => ($component['price_component_type'] ?? null) === 'Monthly'
                && is_numeric($component['price'] ?? null)
                && (float) $component['price'] > 0)
            ->values();
        $generalComponents = collect($input['components'] ?? [])
            ->filter(fn (array $component): bool => ($component['price_component_type'] ?? null) === 'General'
                && is_numeric($component['price'] ?? null)
                && (float) $component['price'] > 0)
            ->values();
        $includedKwh = (float) str_replace(',', '.', $allowanceMatch[1]);

        if ($monthlyComponents->count() !== 1
            || $generalComponents->count() !== 1
            || $includedKwh <= 0) {
            return null;
        }

        $facts = [
            'monthly_fee_eur' => (float) $monthlyComponents->first()['price'],
            'included_kwh' => $includedKwh,
            'excess_rate_cents_per_kwh' => (float) $generalComponents->first()['price'],
        ];
        $nFirstKwhComponents = $generalComponents
            ->filter(fn (array $component): bool => ($component['discount_type'] ?? null) === 'NFirstKwh'
                || (is_numeric($component['discount_n_first_kwh'] ?? null)
                    && (float) $component['discount_n_first_kwh'] > 0));

        if ($nFirstKwhComponents->isNotEmpty()
            && ($nFirstKwhComponents->count() !== 1
                || ! $this->isMonthlyPackageAllowanceDiscount($nFirstKwhComponents->first(), $facts))) {
            return null;
        }

        return $facts;
    }

    private function hasExplicitMonthlyExcessUse(string $text): bool
    {
        return preg_match('/(?:ylittävästä|ylittavasta|ylimenevästä|ylimenevasta)[^.!?]{0,160}(?:energiasta|kulutuksesta)[^.!?]{0,160}(?:laskut|hinn)/u', $text) === 1
            || preg_match('/lisäenergian\s+hinta\p{L}*\s+sovelletaan[^.!?]{0,240}kalenterikuukauden[^.!?]{0,160}ylittää[^.!?]{0,160}energiamäär/u', $text) === 1;
    }

    private function isFlatPackageSource(array $input): bool
    {
        if ($this->monthlyExcessPackageFacts($input) !== null) {
            return true;
        }

        $text = mb_strtolower($this->sourceText($input), 'UTF-8');
        $hasPackageWording = preg_match('/(?<!\p{L})(?:\p{L}*paket\p{L}*|package)(?!\p{L})/u', $text) === 1;
        $maximumConsumption = data_get($input, 'consumption_limitation.MaxXKWhPerY');
        $components = collect($input['components'] ?? []);
        $hasPositiveMonthlyFee = $components->contains(
            fn (array $component): bool => ($component['price_component_type'] ?? null) === 'Monthly'
                && (float) ($component['price'] ?? 0) > 0,
        );
        $hasZeroGeneralPrice = $components->contains(
            fn (array $component): bool => ($component['price_component_type'] ?? null) === 'General'
                && (float) ($component['price'] ?? -1) === 0.0,
        );
        $hasPositiveGeneralPrice = $components->contains(
            fn (array $component): bool => ($component['price_component_type'] ?? null) === 'General'
                && (float) ($component['price'] ?? 0) > 0,
        );
        $hasExplicitIncludedUse = preg_match(
            '/(?:sisältää|sisaltaa)[^.!?]{0,160}(?:energiaa|sähköä|sahkoa)/u',
            $text,
        ) === 1;
        $hasExplicitExcessUse = $this->hasExplicitMonthlyExcessUse($text);
        $hasIncludedEnergyPackage = is_numeric($maximumConsumption)
            && (float) $maximumConsumption > 0
            && $hasZeroGeneralPrice;
        $hasExcessUsePackage = $hasPositiveGeneralPrice
            && $hasExplicitIncludedUse
            && $hasExplicitExcessUse;

        return $hasPackageWording
            && $hasPositiveMonthlyFee
            && ($hasIncludedEnergyPackage || $hasExcessUsePackage);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasExplicitHybridContradiction(array $input, mixed $interpretedPricingModel): bool
    {
        $text = mb_strtolower($this->sourceText($input), 'UTF-8');

        if ($interpretedPricingModel === 'Spot') {
            return preg_match('/nord\s*pool|spot-hinta|pörssisähkö|porssisahko/u', $text) === 1;
        }

        if ($interpretedPricingModel === 'FixedPrice') {
            return preg_match('/(?:ei|ilman)\s+kulutusvaikut/u', $text) === 1
                || str_contains($text, 'kulutusvaikutukseton');
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function sourceText(array $input): string
    {
        return collect([
            $input['contract_name'] ?? null,
            $input['pricing_name'] ?? null,
            $input['short_description'] ?? null,
            $input['long_description'] ?? null,
            $input['extra_information_fi'] ?? null,
            $input['extra_information_default'] ?? null,
        ])->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $errors
     */
    private function validateEvidence(
        mixed $value,
        array $input,
        string $path,
        array &$errors,
    ): void {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['source'], $value['quote'])
            && is_string($value['source'])
            && is_string($value['quote'])) {
            $quote = preg_replace('/\s+/u', ' ', trim($value['quote'])) ?? trim($value['quote']);
            $sourcePath = preg_replace('/\[(\d+)\]/', '.$1', $value['source']) ?? $value['source'];
            $sourceValue = data_get($input, $sourcePath, new \stdClass);

            if (preg_match('/contract_name|description|extra_information/i', $value['source']) === 1) {
                $normalizedSource = is_string($sourceValue)
                    ? (preg_replace('/\s+/u', ' ', trim($sourceValue)) ?? trim($sourceValue))
                    : null;
                if ($quote === '' || $normalizedSource === null || ! str_contains($normalizedSource, $quote)) {
                    $errors[] = "{$path}.quote is not present in the cited source text.";
                }
            } elseif ($sourceValue instanceof \stdClass || is_array($sourceValue) || $sourceValue === null) {
                $errors[] = "{$path}.source does not identify one source value.";
            } elseif (! $this->quoteContainsValue($quote, $sourceValue)) {
                $errors[] = "{$path}.quote does not contain the cited source value.";
            }
        }

        foreach ($value as $key => $child) {
            $childPath = is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}";
            $this->validateEvidence($child, $input, $childPath, $errors);
        }
    }

    private function quoteContainsValue(string $quote, mixed $sourceValue): bool
    {
        if (is_bool($sourceValue)) {
            return str_contains(strtolower($quote), $sourceValue ? 'true' : 'false');
        }

        $value = str_replace(',', '.', (string) $sourceValue);
        $normalizedQuote = str_replace(',', '.', $quote);

        return $value !== '' && str_contains($normalizedQuote, $value);
    }
}
