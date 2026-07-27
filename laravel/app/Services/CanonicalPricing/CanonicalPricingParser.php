<?php

namespace App\Services\CanonicalPricing;

use App\Services\CanonicalPricing\DTO\CanonicalComponent;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\ConsumptionEffectData;
use App\Services\CanonicalPricing\DTO\IncludedEnergyPackageData;
use App\Services\CanonicalPricing\DTO\PhaseBoundary;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\DTO\RecurringScheduleData;
use App\Services\CanonicalPricing\Enums\AllowanceCadence;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\MisleadingState;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;

/**
 * Parses the three `electricity_contracts.canonical_*` JSON columns into typed pricing data.
 *
 * Fail closed: an unknown enum value that affects costing, a missing required object, or a
 * VAT-basis conflict throws CanonicalPricingParseException so the caller excludes the
 * contract instead of costing it on data the calculator does not understand. Unknown
 * canonical issue codes (which do not affect costing) are dropped, not fatal.
 */
class CanonicalPricingParser
{
    /**
     * Canonical issue codes this build understands. Unknown codes from a future schema
     * are ignored for label classification rather than excluding the contract.
     */
    private const KNOWN_ISSUE_CODES = [
        'structured_matches_description',
        'structured_matches_intro_only',
        'promotion_metadata_missing',
        'future_price_omitted',
        'future_price_unknown',
        'pricing_model_mismatch',
        'contract_type_mismatch',
        'metering_mismatch',
        'component_mismatch',
        'unsupported_consumption_effect',
        'recurring_reset_requires_estimate',
        'optional_fixing_not_in_base_price',
        'insufficient_evidence',
        'other',
    ];

    public function parse(
        ?array $canonicalPricing,
        ?array $canonicalCalculation,
        ?array $canonicalSourceConsistency,
    ): CanonicalContractData {
        if (! is_array($canonicalPricing) || ! is_array($canonicalCalculation)) {
            throw new CanonicalPricingParseException('Missing canonical pricing or calculation data.');
        }

        $phases = $this->parsePhases($canonicalPricing['phases'] ?? null);
        $recurring = $this->parseRecurringSchedule($canonicalPricing['recurring_schedule'] ?? []);
        $consumptionEffect = $this->parseConsumptionEffect($canonicalPricing['consumption_effect'] ?? []);

        $status = CalculationStatus::tryFrom((string) ($canonicalCalculation['status'] ?? ''));
        if ($status === null) {
            throw new CanonicalPricingParseException('Unknown calculation status: '.($canonicalCalculation['status'] ?? 'null'));
        }

        $sourceConsistency = is_array($canonicalSourceConsistency) ? $canonicalSourceConsistency : [];
        $misleading = MisleadingState::tryFrom((string) ($sourceConsistency['misleading_first_12_months'] ?? 'not_assessable'))
            ?? MisleadingState::NotAssessable;

        return new CanonicalContractData(
            phases: $phases,
            recurringSchedule: $recurring,
            consumptionEffect: $consumptionEffect,
            calculationStatus: $status,
            missingFacts: $this->stringList($canonicalCalculation['missing_facts'] ?? []),
            misleadingState: $misleading,
            structuredPricingStatus: (string) ($sourceConsistency['structured_pricing_status'] ?? 'not_assessable'),
            issueCodes: $this->knownIssueCodes($sourceConsistency['issue_codes'] ?? []),
        );
    }

    /**
     * @return list<PricingPhase>
     */
    private function parsePhases(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new CanonicalPricingParseException('Canonical pricing has no phases array.');
        }

        $phases = [];
        // component_type => vat_status seen, to detect an inconsistent VAT basis for one component.
        $vatBasis = [];

        foreach ($raw as $rawPhase) {
            if (! is_array($rawPhase)) {
                throw new CanonicalPricingParseException('Malformed pricing phase.');
            }

            $phaseKind = PhaseKind::tryFrom((string) ($rawPhase['phase_kind'] ?? ''));
            if ($phaseKind === null) {
                throw new CanonicalPricingParseException('Unknown phase_kind: '.($rawPhase['phase_kind'] ?? 'null'));
            }

            $rawComponents = $rawPhase['components'] ?? [];
            if (! is_array($rawComponents)) {
                throw new CanonicalPricingParseException('Malformed pricing phase components.');
            }

            $components = [];
            foreach ($rawComponents as $rawComponent) {
                $components[] = $this->parseComponent($rawComponent, $vatBasis);
            }

            $package = $this->parsePackage($rawPhase['package'] ?? null);
            if ($package !== null && $components !== []) {
                throw new CanonicalPricingParseException('A package phase must not duplicate its fee or excess rate as components.');
            }

            $hasMonthlyFee = false;
            $hasMonthlyFlatFee = false;
            foreach ($components as $component) {
                $hasMonthlyFee = $hasMonthlyFee || $component->type === ComponentType::MonthlyFee;
                $hasMonthlyFlatFee = $hasMonthlyFlatFee
                    || ($component->type === ComponentType::FlatFee && $component->unit === ComponentUnit::EurPerMonth);
            }
            if ($hasMonthlyFee && $hasMonthlyFlatFee) {
                throw new CanonicalPricingParseException('A phase has ambiguous duplicate monthly fees.');
            }

            $phases[] = new PricingPhase(
                label: (string) ($rawPhase['label'] ?? ''),
                phaseKind: $phaseKind,
                starts: $this->parseBoundary($rawPhase['starts'] ?? null),
                ends: $this->parseBoundary($rawPhase['ends'] ?? null),
                components: $components,
                package: $package,
            );
        }

        return $phases;
    }

    /**
     * @param  array<string, string>  $vatBasis
     */
    private function parseComponent(mixed $raw, array &$vatBasis): CanonicalComponent
    {
        if (! is_array($raw)) {
            throw new CanonicalPricingParseException('Malformed pricing component.');
        }

        $type = ComponentType::tryFrom((string) ($raw['component_type'] ?? ''));
        if ($type === null) {
            throw new CanonicalPricingParseException('Unknown component_type: '.($raw['component_type'] ?? 'null'));
        }

        $unit = ComponentUnit::tryFrom((string) ($raw['unit'] ?? ''));
        if ($unit === null) {
            throw new CanonicalPricingParseException('Unknown component unit: '.($raw['unit'] ?? 'null'));
        }

        $priceRole = PriceRole::tryFrom((string) ($raw['price_role'] ?? ''));
        if ($priceRole === null) {
            throw new CanonicalPricingParseException('Unknown price_role: '.($raw['price_role'] ?? 'null'));
        }

        $vatStatus = (string) ($raw['vat_status'] ?? 'unknown');
        if ($vatStatus === 'included' || $vatStatus === 'excluded') {
            $key = $type->value;
            if (isset($vatBasis[$key]) && $vatBasis[$key] !== $vatStatus) {
                throw new CanonicalPricingParseException("Conflicting VAT basis for {$key}: {$vatBasis[$key]} vs {$vatStatus}.");
            }
            $vatBasis[$key] = $vatStatus;
        }

        return new CanonicalComponent(
            type: $type,
            amount: $this->nullableFloat($raw['amount'] ?? null),
            normalAmount: $this->nullableFloat($raw['normal_amount'] ?? null),
            unit: $unit,
            priceRole: $priceRole,
        );
    }

    private function parsePackage(mixed $raw): ?IncludedEnergyPackageData
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            throw new CanonicalPricingParseException('Malformed included-energy package.');
        }

        $monthlyFee = $this->nullableFloat($raw['monthly_fee_eur'] ?? null);
        $includedKwh = $this->nullableFloat($raw['included_kwh'] ?? null);
        $excessRate = $this->nullableFloat($raw['excess_rate_cents_per_kwh'] ?? null);
        if ($monthlyFee === null || $monthlyFee <= 0
            || $includedKwh === null || $includedKwh <= 0
            || $excessRate === null || $excessRate <= 0) {
            throw new CanonicalPricingParseException('Included-energy package values must be positive and complete.');
        }

        $cadence = AllowanceCadence::tryFrom((string) ($raw['allowance_cadence'] ?? ''));
        if ($cadence === null) {
            throw new CanonicalPricingParseException('Unsupported included-energy package allowance cadence.');
        }

        return new IncludedEnergyPackageData(
            monthlyFeeEur: $monthlyFee,
            includedKwh: $includedKwh,
            allowanceCadence: $cadence,
            excessRateCentsPerKwh: $excessRate,
        );
    }

    private function parseBoundary(mixed $raw): PhaseBoundary
    {
        if (! is_array($raw)) {
            // A missing boundary is treated as unknown coverage, never zero.
            return new PhaseBoundary(BoundaryKind::Unknown, null);
        }

        $kind = BoundaryKind::tryFrom((string) ($raw['kind'] ?? ''));
        if ($kind === null) {
            throw new CanonicalPricingParseException('Unknown boundary kind: '.($raw['kind'] ?? 'null'));
        }

        $value = $raw['value'] ?? null;

        return new PhaseBoundary($kind, $value === null ? null : (string) $value);
    }

    private function parseRecurringSchedule(mixed $raw): RecurringScheduleData
    {
        $raw = is_array($raw) ? $raw : [];

        return new RecurringScheduleData(
            present: (bool) ($raw['present'] ?? false),
            cadence: (string) ($raw['cadence'] ?? 'none'),
            currentPeriodStart: $this->nullableString($raw['current_period_start'] ?? null),
            currentPeriodEnd: $this->nullableString($raw['current_period_end'] ?? null),
            futurePriceKnown: array_key_exists('future_price_known', $raw) ? $this->nullableBool($raw['future_price_known']) : null,
        );
    }

    private function parseConsumptionEffect(mixed $raw): ConsumptionEffectData
    {
        $raw = is_array($raw) ? $raw : [];

        return new ConsumptionEffectData(
            present: (bool) ($raw['present'] ?? false),
            appliesTo: (string) ($raw['applies_to'] ?? 'unknown'),
            cadence: (string) ($raw['cadence'] ?? 'none'),
            expectedCentsPerKwh: $this->nullableFloat($raw['expected_cents_per_kwh'] ?? null),
            typicalMinCentsPerKwh: $this->nullableFloat($raw['typical_min_cents_per_kwh'] ?? null),
            typicalMaxCentsPerKwh: $this->nullableFloat($raw['typical_max_cents_per_kwh'] ?? null),
            hardMinCentsPerKwh: $this->nullableFloat($raw['hard_min_cents_per_kwh'] ?? null),
            hardMaxCentsPerKwh: $this->nullableFloat($raw['hard_max_cents_per_kwh'] ?? null),
            uncapped: array_key_exists('uncapped', $raw) ? $this->nullableBool($raw['uncapped']) : null,
        );
    }

    /**
     * @return list<string>
     */
    private function knownIssueCodes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $code) {
            $code = (string) $code;
            if (in_array($code, self::KNOWN_ISSUE_CODES, true)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $raw));
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }
}
