<?php

namespace App\Services\ContractInterpretation;

use DateTimeImmutable;
use RuntimeException;

class ContractInterpretationValidator
{
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

        $isFlatPackageSource = $this->isFlatPackageSource($input);
        $expectedTypes = collect($input['components'] ?? [])
            ->map(function (array $component) use ($isFlatPackageSource, $output, $input): ?string {
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
            if (! in_array($expectedType, $interpretedTypes, true)) {
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
            || ($output['calculation']['status'] ?? null) !== 'exact'
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
            $errors[] = '$.source_consistency.misleading_first_12_months must be not_detected when pricing is complete and exact with no directional issue.';
        }
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

                return is_numeric($months)
                    && $sources->contains("components[{$index}].discount_n_first_months")
                    && ($phase['starts']['kind'] ?? null) === 'contract_start'
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
        return match ($sourceType) {
            'General' => $pricingModel === 'Spot' ? 'spot_margin' : 'energy_general',
            'DayTime' => 'energy_day',
            'NightTime' => 'energy_night',
            'SeasonalWinter' => 'energy_seasonal_winter',
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

        if ($hasMechanism('flat_fee_or_package') && ! $hasComponent('flat_fee')) {
            $errors[] = '$.classification.pricing_mechanisms contains flat_fee_or_package without a flat_fee component.';
        }
        if ($hasComponent('flat_fee') && ! $hasMechanism('flat_fee_or_package')) {
            $errors[] = '$.classification.pricing_mechanisms must contain flat_fee_or_package for a flat_fee component.';
        }
        if ($this->isFlatPackageSource($input)) {
            if (! $hasMechanism('flat_fee_or_package')) {
                $errors[] = '$.classification.pricing_mechanisms must contain flat_fee_or_package because the source explicitly describes a consumption package.';
            }
            if (! $hasComponent('flat_fee')) {
                $errors[] = '$.pricing.phases must map the package Monthly charge to a flat_fee component.';
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
     * @param  array<string, mixed>  $input
     */
    private function isFlatPackageSource(array $input): bool
    {
        $text = mb_strtolower($this->sourceText($input), 'UTF-8');
        $hasPackageWording = preg_match('/\b(?:paketti|package)\b/u', $text) === 1;
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

        return $hasPackageWording
            && is_numeric($maximumConsumption)
            && (float) $maximumConsumption > 0
            && $hasPositiveMonthlyFee
            && $hasZeroGeneralPrice;
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
