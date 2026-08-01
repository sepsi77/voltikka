<?php

namespace Database\Factories\Support;

use App\Services\CanonicalPricing\Enums\AllowanceCadence;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\MisleadingState;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class CanonicalPricingFixture
{
    /** @return array{kind: string, value: string|null} */
    public static function boundary(BoundaryKind $kind, ?string $value = null): array
    {
        return [
            'kind' => $kind->value,
            'value' => $value,
        ];
    }

    /** @return array<string, mixed> */
    public static function component(
        ComponentType $type,
        ?float $amount,
        ComponentUnit $unit,
        PriceRole $priceRole = PriceRole::Current,
        ?float $normalAmount = null,
    ): array {
        return [
            'component_type' => $type->value,
            'amount' => $amount,
            'normal_amount' => $normalAmount,
            'unit' => $unit->value,
            'vat_status' => 'included',
            'price_role' => $priceRole->value,
            'source_kind' => 'both',
            'evidence' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public static function phase(
        string $label,
        PhaseKind $kind,
        array $starts,
        array $ends,
        array $components,
    ): array {
        return [
            'label' => $label,
            'phase_kind' => $kind->value,
            'starts' => $starts,
            'ends' => $ends,
            'components' => array_values($components),
            'package' => null,
            'evidence' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function packagePhase(
        string $label,
        PhaseKind $kind,
        array $starts,
        array $ends,
        float $monthlyFeeEur,
        float $includedKwh,
        AllowanceCadence $allowanceCadence,
        float $excessRateCentsPerKwh,
    ): array {
        return [
            'label' => $label,
            'phase_kind' => $kind->value,
            'starts' => $starts,
            'ends' => $ends,
            'components' => [],
            'package' => self::includedEnergyPackage(
                $monthlyFeeEur,
                $includedKwh,
                $allowanceCadence,
                $excessRateCentsPerKwh,
            ),
            'evidence' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function includedEnergyPackage(
        float $monthlyFeeEur,
        float $includedKwh,
        AllowanceCadence $allowanceCadence,
        float $excessRateCentsPerKwh,
    ): array {
        if ($monthlyFeeEur <= 0 || $includedKwh <= 0 || $excessRateCentsPerKwh <= 0) {
            throw new InvalidArgumentException('Included-energy package values must be positive.');
        }

        return [
            'monthly_fee_eur' => $monthlyFeeEur,
            'included_kwh' => $includedKwh,
            'allowance_cadence' => $allowanceCadence->value,
            'excess_rate_cents_per_kwh' => $excessRateCentsPerKwh,
            'evidence' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function recurringSchedule(
        string $cadence,
        ?string $currentPeriodStart,
        ?string $currentPeriodEnd,
        ?bool $futurePriceKnown,
    ): array {
        return [
            'present' => true,
            'cadence' => $cadence,
            'current_period_start' => $currentPeriodStart,
            'current_period_end' => $currentPeriodEnd,
            'future_price_known' => $futurePriceKnown,
            'description' => null,
            'evidence' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function consumptionEffect(
        string $appliesTo,
        string $cadence,
        ?float $expectedCentsPerKwh,
        ?float $typicalMinCentsPerKwh,
        ?float $typicalMaxCentsPerKwh,
        ?float $hardMinCentsPerKwh,
        ?float $hardMaxCentsPerKwh,
        ?bool $uncapped,
    ): array {
        return [
            'present' => true,
            'applies_to' => $appliesTo,
            'cadence' => $cadence,
            'expected_cents_per_kwh' => $expectedCentsPerKwh,
            'typical_min_cents_per_kwh' => $typicalMinCentsPerKwh,
            'typical_max_cents_per_kwh' => $typicalMaxCentsPerKwh,
            'hard_min_cents_per_kwh' => $hardMinCentsPerKwh,
            'hard_max_cents_per_kwh' => $hardMaxCentsPerKwh,
            'uncapped' => $uncapped,
            'description' => null,
            'evidence' => [],
        ];
    }

    /** @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} */
    public static function fixedAttributes(): array
    {
        return self::attributes(
            phases: [self::phase(
                'Nykyinen hinta',
                PhaseKind::CurrentStructured,
                self::boundary(BoundaryKind::ContractStart),
                self::boundary(BoundaryKind::None),
                [
                    self::component(ComponentType::EnergyGeneral, 8.45, ComponentUnit::CentsPerKwh),
                    self::component(ComponentType::MonthlyFee, 4.90, ComponentUnit::EurPerMonth),
                ],
            )],
            calculationStatus: CalculationStatus::Exact,
        );
    }

    /** @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} */
    public static function spotAttributes(): array
    {
        return self::attributes(
            phases: [self::phase(
                'Pörssihinta',
                PhaseKind::CurrentStructured,
                self::boundary(BoundaryKind::ContractStart),
                self::boundary(BoundaryKind::None),
                [
                    self::component(ComponentType::SpotMargin, 0.49, ComponentUnit::CentsPerKwh),
                    self::component(ComponentType::MonthlyFee, 4.50, ComponentUnit::EurPerMonth),
                ],
            )],
            calculationStatus: CalculationStatus::EstimateRequired,
        );
    }

    /** @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} */
    public static function hybridAttributes(): array
    {
        return self::attributes(
            phases: [self::phase(
                'Kiinteä perushinta',
                PhaseKind::CurrentStructured,
                self::boundary(BoundaryKind::ContractStart),
                self::boundary(BoundaryKind::None),
                [
                    self::component(ComponentType::EnergyGeneral, 7.95, ComponentUnit::CentsPerKwh),
                    self::component(ComponentType::MonthlyFee, 4.90, ComponentUnit::EurPerMonth),
                    self::component(ComponentType::ConsumptionEffect, null, ComponentUnit::Unknown, PriceRole::Unknown),
                ],
            )],
            calculationStatus: CalculationStatus::Unsupported,
            consumptionEffect: self::consumptionEffect(
                appliesTo: 'base_contract',
                cadence: 'monthly',
                expectedCentsPerKwh: null,
                typicalMinCentsPerKwh: -1.5,
                typicalMaxCentsPerKwh: 1.5,
                hardMinCentsPerKwh: null,
                hardMaxCentsPerKwh: null,
                uncapped: true,
            ),
            misleading: MisleadingState::Uncertain,
            structuredPricingStatus: 'incomplete',
            issueCodes: ['unsupported_consumption_effect'],
            missingFacts: ['consumption_effect_amount'],
        );
    }

    /** @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} */
    public static function resetAttributes(): array
    {
        $currentPeriodStart = CarbonImmutable::now('Europe/Helsinki')->startOfQuarter();
        $currentPeriodEnd = $currentPeriodStart->endOfQuarter();

        return self::attributes(
            phases: [self::phase(
                'Nykyinen vuosineljännes',
                PhaseKind::RecurringPeriod,
                self::boundary(BoundaryKind::PeriodBoundary),
                self::boundary(BoundaryKind::PeriodBoundary),
                [
                    self::component(ComponentType::EnergyGeneral, 7.25, ComponentUnit::CentsPerKwh),
                    self::component(ComponentType::MonthlyFee, 4.90, ComponentUnit::EurPerMonth),
                ],
            )],
            calculationStatus: CalculationStatus::EstimateRequired,
            recurringSchedule: self::recurringSchedule(
                cadence: 'quarterly',
                currentPeriodStart: $currentPeriodStart->toDateString(),
                currentPeriodEnd: $currentPeriodEnd->toDateString(),
                futurePriceKnown: false,
            ),
            issueCodes: ['recurring_reset_requires_estimate'],
        );
    }

    /** @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} */
    public static function packageAttributes(): array
    {
        return self::attributes(
            phases: [self::packagePhase(
                label: 'Kuukausipaketti',
                kind: PhaseKind::CurrentStructured,
                starts: self::boundary(BoundaryKind::ContractStart),
                ends: self::boundary(BoundaryKind::None),
                monthlyFeeEur: 25.0,
                includedKwh: 150.0,
                allowanceCadence: AllowanceCadence::Monthly,
                excessRateCentsPerKwh: 16.6,
            )],
            calculationStatus: CalculationStatus::Exact,
        );
    }

    /** @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>} */
    public static function introductoryToNormalAttributes(): array
    {
        return self::attributes(
            phases: [
                self::phase(
                    'Aloitushinta',
                    PhaseKind::Introductory,
                    self::boundary(BoundaryKind::ContractStart),
                    self::boundary(BoundaryKind::AfterMonths, '3'),
                    [
                        self::component(ComponentType::EnergyGeneral, 5.49, ComponentUnit::CentsPerKwh, PriceRole::Introductory, 9.49),
                        self::component(ComponentType::MonthlyFee, 2.99, ComponentUnit::EurPerMonth, PriceRole::Introductory, 5.99),
                    ],
                ),
                self::phase(
                    'Normaalihinta',
                    PhaseKind::Normal,
                    self::boundary(BoundaryKind::AfterMonths, '3'),
                    self::boundary(BoundaryKind::None),
                    [
                        self::component(ComponentType::EnergyGeneral, 9.49, ComponentUnit::CentsPerKwh, PriceRole::Normal),
                        self::component(ComponentType::MonthlyFee, 5.99, ComponentUnit::EurPerMonth, PriceRole::Normal),
                    ],
                ),
            ],
            calculationStatus: CalculationStatus::Exact,
            misleading: MisleadingState::Detected,
            structuredPricingStatus: 'incomplete',
            issueCodes: ['structured_matches_intro_only', 'future_price_omitted'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  array<string, mixed>|null  $recurringSchedule
     * @param  array<string, mixed>|null  $consumptionEffect
     * @param  list<string>  $issueCodes
     * @param  list<string>  $missingFacts
     * @param  list<string>  $requiredAssumptions
     * @return array{canonical_pricing: array<string, mixed>, canonical_source_consistency: array<string, mixed>, canonical_calculation: array<string, mixed>}
     */
    public static function attributes(
        array $phases,
        CalculationStatus $calculationStatus,
        ?array $recurringSchedule = null,
        ?array $consumptionEffect = null,
        MisleadingState $misleading = MisleadingState::NotDetected,
        string $structuredPricingStatus = 'complete',
        array $issueCodes = [],
        array $missingFacts = [],
        array $requiredAssumptions = [],
    ): array {
        return [
            'canonical_pricing' => [
                'phases' => array_values($phases),
                'recurring_schedule' => $recurringSchedule ?? [
                    'present' => false,
                    'cadence' => 'none',
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'future_price_known' => null,
                    'description' => null,
                    'evidence' => [],
                ],
                'consumption_effect' => $consumptionEffect ?? [
                    'present' => false,
                    'applies_to' => 'unknown',
                    'cadence' => 'none',
                    'expected_cents_per_kwh' => null,
                    'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null,
                    'uncapped' => null,
                    'description' => null,
                    'evidence' => [],
                ],
            ],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => $misleading->value,
                'structured_pricing_status' => $structuredPricingStatus,
                'issue_codes' => array_values($issueCodes),
            ],
            'canonical_calculation' => [
                'status' => $calculationStatus->value,
                'missing_facts' => array_values($missingFacts),
                'required_assumptions' => array_values($requiredAssumptions),
            ],
        ];
    }
}
