<?php

namespace Tests\Unit;

use App\Services\ContractInterpretation\Enums\HistoricalEvidenceGrade;
use App\Services\ContractInterpretation\HistoricalInterpretationBackcastValidator;
use Tests\TestCase;

class HistoricalInterpretationBackcastValidatorTest extends TestCase
{
    public function test_backcast_can_recover_stable_recurring_mechanism_with_exact_typed_current_amount(): void
    {
        $output = $this->interpretationOutput([
            $this->phase([$this->outputComponent(0, 'energy_general', 7.2, 'cents_per_kwh')]),
        ]);
        $output['pricing']['recurring_schedule'] = [
            'present' => true,
            'cadence' => 'quarterly',
            'current_period_start' => null,
            'current_period_end' => null,
        ];

        $this->assertSame([], $this->validate(
            $output,
            [$this->sourceComponent('General', 7.2, 'CentPerKiwattHour')],
        ));
    }

    public function test_equal_monthly_fee_and_energy_rate_cannot_be_swapped_by_evidence_scope(): void
    {
        $phase = $this->phase([
            $this->outputComponent(1, 'monthly_fee', 7.2, 'eur_per_month'),
            $this->outputComponent(0, 'energy_general', 7.2, 'cents_per_kwh'),
        ]);

        $errors = $this->validate($this->interpretationOutput([$phase]), [
            $this->sourceComponent('Monthly', 7.2, 'EurPerMonth'),
            $this->sourceComponent('General', 7.2, 'CentPerKiwattHour'),
        ]);

        $this->assertStringContainsString('no exact structured source fact with canonical type monthly_fee', $errors[0]);
        $this->assertStringContainsString('no exact structured source fact with canonical type energy_general', $errors[1]);
    }

    public function test_wrong_output_unit_fails_even_when_type_and_amount_match(): void
    {
        $errors = $this->validate(
            $this->interpretationOutput([
                $this->phase([$this->outputComponent(0, 'energy_general', 7.2, 'eur_per_month')]),
            ]),
            [$this->sourceComponent('General', 7.2, 'CentPerKiwattHour')],
        );

        $this->assertStringContainsString('unit eur_per_month', $errors[0]);
    }

    public function test_one_components_discount_amount_cannot_support_another_component(): void
    {
        $general = $this->sourceComponent('General', 10.0, 'CentPerKiwattHour', [
            'has_discount' => true,
            'discount_value' => 2.0,
            'discount_is_percentage' => false,
            'discount_type' => 'UntilDate',
            'discount_until_date' => '2026-06-30',
        ]);
        $monthly = $this->sourceComponent('Monthly', 12.0, 'EurPerMonth', [
            'has_discount' => true,
            'discount_value' => 4.0,
            'discount_is_percentage' => false,
            'discount_type' => 'UntilDate',
            'discount_until_date' => '2026-06-30',
        ]);
        $phase = $this->phase([
            $this->outputComponent(1, 'energy_general', 8.0, 'cents_per_kwh', 'introductory', 10.0),
        ], 'introductory');
        $phase['ends'] = ['kind' => 'date', 'value' => '2026-06-30'];

        $errors = $this->validate($this->interpretationOutput([$phase]), [$general, $monthly]);

        $this->assertStringContainsString('no exact structured source fact with canonical type energy_general', $errors[0]);
    }

    public function test_one_components_discount_date_cannot_support_another_component(): void
    {
        $general = $this->sourceComponent('General', 10.0, 'CentPerKiwattHour', [
            'has_discount' => true,
            'discount_value' => 2.0,
            'discount_is_percentage' => false,
            'discount_type' => 'UntilDate',
            'discount_until_date' => '2026-06-30',
        ]);
        $monthly = $this->sourceComponent('Monthly', 10.0, 'EurPerMonth', [
            'has_discount' => true,
            'discount_value' => 2.0,
            'discount_is_percentage' => false,
            'discount_type' => 'UntilDate',
            'discount_until_date' => '2026-07-31',
        ]);
        $phase = $this->phase([
            $this->outputComponent(0, 'energy_general', 8.0, 'cents_per_kwh', 'introductory', 10.0),
            $this->outputComponent(1, 'monthly_fee', 8.0, 'eur_per_month', 'introductory', 10.0),
        ], 'introductory');
        $phase['ends'] = ['kind' => 'date', 'value' => '2026-07-31'];

        $errors = $this->validate($this->interpretationOutput([$phase]), [$general, $monthly]);

        $this->assertStringContainsString('components[0] has no exact structured source fact', $errors[0]);
    }

    public function test_one_components_first_month_duration_cannot_support_another_component(): void
    {
        $general = $this->sourceComponent('General', 10.0, 'CentPerKiwattHour', [
            'has_discount' => true,
            'discount_value' => 2.0,
            'discount_is_percentage' => false,
            'discount_type' => 'NFirstMonth',
            'discount_n_first_months' => '3',
        ]);
        $monthly = $this->sourceComponent('Monthly', 10.0, 'EurPerMonth', [
            'has_discount' => true,
            'discount_value' => 2.0,
            'discount_is_percentage' => false,
            'discount_type' => 'NFirstMonth',
            'discount_n_first_months' => '6',
        ]);
        $phase = $this->phase([
            $this->outputComponent(0, 'energy_general', 8.0, 'cents_per_kwh', 'introductory', 10.0),
            $this->outputComponent(1, 'monthly_fee', 8.0, 'eur_per_month', 'introductory', 10.0),
        ], 'introductory');
        $phase['ends'] = ['kind' => 'after_months', 'value' => '6'];

        $errors = $this->validate($this->interpretationOutput([$phase]), [$general, $monthly]);

        $this->assertStringContainsString('components[0] has no exact structured source fact', $errors[0]);
    }

    public function test_unmatched_duplicate_output_component_fails(): void
    {
        $component = $this->outputComponent(0, 'energy_general', 7.2, 'cents_per_kwh');
        $errors = $this->validate(
            $this->interpretationOutput([$this->phase([$component, $component])]),
            [$this->sourceComponent('General', 7.2, 'CentPerKiwattHour')],
        );

        $this->assertContains(
            'Historical backcast restriction: $.pricing.phases[0].components[1] is an unmatched extra billed component; one structured source component can be billed only once in a phase.',
            $errors,
        );
    }

    public function test_exact_typed_until_date_discount_and_normal_continuation_pass(): void
    {
        $source = $this->sourceComponent('General', 10.0, 'CentPerKiwattHour', [
            'has_discount' => true,
            'discount_value' => 2.0,
            'discount_is_percentage' => false,
            'discount_type' => 'UntilDate',
            'discount_until_date' => '2026-06-30',
        ]);
        $intro = $this->phase([
            $this->outputComponent(0, 'energy_general', 8.0, 'cents_per_kwh', 'introductory', 10.0),
        ], 'introductory');
        $intro['ends'] = ['kind' => 'date', 'value' => '2026-06-30'];
        $continuation = $this->phase([
            $this->outputComponent(0, 'energy_general', 10.0, 'cents_per_kwh', 'normal'),
        ], 'continuation');
        $continuation['starts'] = ['kind' => 'date', 'value' => '2026-07-01'];

        $this->assertSame([], $this->validate(
            $this->interpretationOutput([$intro, $continuation]),
            [$source],
        ));
    }

    public function test_exact_typed_first_month_discount_and_normal_continuation_pass(): void
    {
        $source = $this->sourceComponent('Monthly', 10.0, 'EurPerMonth', [
            'has_discount' => true,
            'discount_value' => 20.0,
            'discount_is_percentage' => true,
            'discount_type' => 'NFirstMonth',
            'discount_n_first_months' => '3',
        ]);
        $intro = $this->phase([
            $this->outputComponent(0, 'flat_fee', 8.0, 'eur_per_month', 'introductory', 10.0),
        ], 'introductory');
        $intro['ends'] = ['kind' => 'after_months', 'value' => '3'];
        $continuation = $this->phase([
            $this->outputComponent(0, 'flat_fee', 10.0, 'eur_per_month', 'normal'),
        ], 'continuation');
        $continuation['starts'] = ['kind' => 'after_months', 'value' => '3'];

        $this->assertSame([], $this->validate(
            $this->interpretationOutput([$intro, $continuation]),
            [$source],
        ));
    }

    public function test_recurring_period_dates_cannot_reuse_promotion_dates(): void
    {
        $source = $this->sourceComponent('General', 10.0, 'CentPerKiwattHour', [
            'has_discount' => true,
            'discount_value' => 2.0,
            'discount_is_percentage' => false,
            'discount_type' => 'UntilDate',
            'discount_until_date' => '2026-06-30',
        ]);
        $output = $this->interpretationOutput([]);
        $output['pricing']['recurring_schedule'] = [
            'present' => true,
            'cadence' => 'quarterly',
            'current_period_start' => '2026-01-01',
            'current_period_end' => '2026-06-30',
        ];

        $errors = $this->validate($output, [$source]);

        $this->assertContains(
            'Historical backcast restriction: $.pricing.recurring_schedule.current_period_start must be null because these historical episode inputs have no exact structured recurring-period date field.',
            $errors,
        );
        $this->assertContains(
            'Historical backcast restriction: $.pricing.recurring_schedule.current_period_end must be null because these historical episode inputs have no exact structured recurring-period date field.',
            $errors,
        );
    }

    public function test_any_backcast_detected_deception_fails_even_with_a_structured_discount(): void
    {
        $errors = $this->validate(
            $this->interpretationOutput([], 'detected'),
            [$this->sourceComponent('General', 7.2, 'CentPerKiwattHour')],
        );

        $this->assertContains(
            'Historical backcast restriction: $.source_consistency.misleading_first_12_months must not be detected for retrospective backcast evidence; use uncertain, not_detected, or not_assessable.',
            $errors,
        );
    }

    public function test_numeric_consumption_effect_fails_closed(): void
    {
        $output = $this->interpretationOutput([]);
        $output['pricing']['consumption_effect']['expected_cents_per_kwh'] = 1.5;

        $errors = $this->validate(
            $output,
            [$this->sourceComponent('General', 1.5, 'CentPerKiwattHour')],
        );

        $this->assertContains(
            'Historical backcast restriction: $.pricing.consumption_effect.expected_cents_per_kwh must be null because the historical structured components contain no typed consumption-effect value for that mechanism.',
            $errors,
        );
    }

    public function test_exact_scoped_monthly_package_facts_pass(): void
    {
        $phase = $this->phase([], 'current_structured');
        $phase['package'] = $this->package(0, 1, 10.0, 100.0, 10.0);

        $this->assertSame([], $this->validate($this->interpretationOutput([$phase]), [
            $this->sourceComponent('Monthly', 10.0, 'EurPerMonth'),
            $this->packageRateSource(10.0, 1200.0),
        ]));
    }

    public function test_package_monthly_fee_cannot_use_equal_unrelated_energy_value(): void
    {
        $phase = $this->phase([], 'current_structured');
        $phase['package'] = $this->package(1, 1, 10.0, 100.0, 10.0);

        $errors = $this->validate($this->interpretationOutput([$phase]), [
            $this->sourceComponent('Monthly', 10.0, 'EurPerMonth'),
            $this->packageRateSource(10.0, 1200.0),
        ]);

        $this->assertContains(
            'Historical backcast restriction: $.pricing.phases[0].package.monthly_fee_eur must match an exact Monthly/EurPerMonth structured source price.',
            $errors,
        );
    }

    public function test_package_allowance_and_excess_rate_must_share_the_exact_applicable_source(): void
    {
        $phase = $this->phase([], 'current_structured');
        $phase['package'] = $this->package(0, 1, 10.0, 100.0, 10.0, [2]);

        $errors = $this->validate($this->interpretationOutput([$phase]), [
            $this->sourceComponent('Monthly', 10.0, 'EurPerMonth'),
            $this->sourceComponent('General', 10.0, 'CentPerKiwattHour'),
            $this->packageRateSource(5.0, 1200.0),
        ]);

        $this->assertContains(
            'Historical backcast restriction: $.pricing.phases[0].package excess_rate_cents_per_kwh and included_kwh must match one exact applicable per-kWh source component and its NFirstKwh annual marker (12 times the monthly allowance).',
            $errors,
        );
    }

    public function test_structured_only_evidence_is_unchanged(): void
    {
        $input = $this->input([$this->sourceComponent('General', 7.2, 'CentPerKiwattHour')]);
        $input['_historical_provenance'] = [
            'evidence_grade' => HistoricalEvidenceGrade::StructuredOnly->value,
            'text_is_backcast' => false,
        ];
        $phase = $this->phase([$this->outputComponent(0, 'monthly_fee', 99.0, 'eur_flat', 'future')]);
        $phase['ends'] = ['kind' => 'date', 'value' => '2030-01-01'];
        $output = $this->interpretationOutput([$phase], 'detected');
        $output['pricing']['recurring_schedule']['current_period_end'] = '2030-01-01';
        $output['pricing']['consumption_effect']['expected_cents_per_kwh'] = 99.0;

        $this->assertSame([], app(HistoricalInterpretationBackcastValidator::class)->validate($output, $input));
    }

    /** @param list<array<string, mixed>> $components */
    private function validate(array $output, array $components): array
    {
        return app(HistoricalInterpretationBackcastValidator::class)->validate(
            $output,
            $this->input($components),
        );
    }

    /** @param list<array<string, mixed>> $components */
    private function input(array $components): array
    {
        return [
            'analysis_date' => '2026-01-01',
            'pricing_model' => 'FixedPrice',
            'components' => $components,
            '_historical_provenance' => [
                'evidence_grade' => HistoricalEvidenceGrade::LastObservedTextBackcast->value,
                'text_is_backcast' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function sourceComponent(string $type, float $price, string $unit, array $overrides = []): array
    {
        return array_replace([
            'price_component_type' => $type,
            'payment_unit' => $unit,
            'price' => $price,
            'has_discount' => false,
            'discount_value' => null,
            'discount_is_percentage' => null,
            'discount_type' => null,
            'discount_n_first_kwh' => null,
            'discount_n_first_months' => null,
            'discount_until_date' => null,
        ], $overrides);
    }

    private function packageRateSource(float $price, float $annualAllowance): array
    {
        return $this->sourceComponent('General', $price, 'CentPerKiwattHour', [
            'has_discount' => true,
            'discount_value' => $price,
            'discount_is_percentage' => false,
            'discount_type' => 'NFirstKwh',
            'discount_n_first_kwh' => $annualAllowance,
        ]);
    }

    /** @return array<string, mixed> */
    private function outputComponent(
        int $sourceIndex,
        string $type,
        float $amount,
        string $unit,
        string $role = 'current',
        ?float $normalAmount = null,
    ): array {
        return [
            'component_type' => $type,
            'amount' => $amount,
            'normal_amount' => $normalAmount,
            'unit' => $unit,
            'price_role' => $role,
            'evidence' => [[
                'source' => "components[{$sourceIndex}].price",
                'quote' => "components[{$sourceIndex}].price={$amount}",
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function phase(array $components, string $kind = 'current_structured'): array
    {
        return [
            'phase_kind' => $kind,
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'components' => $components,
            'package' => null,
        ];
    }

    /**
     * @param  list<int>  $additionalSourceIndexes
     * @return array<string, mixed>
     */
    private function package(
        int $monthlySourceIndex,
        int $rateSourceIndex,
        float $monthlyFee,
        float $includedKwh,
        float $excessRate,
        array $additionalSourceIndexes = [],
    ): array {
        $indexes = array_values(array_unique([
            $monthlySourceIndex,
            $rateSourceIndex,
            ...$additionalSourceIndexes,
        ]));

        return [
            'monthly_fee_eur' => $monthlyFee,
            'included_kwh' => $includedKwh,
            'allowance_cadence' => 'monthly',
            'excess_rate_cents_per_kwh' => $excessRate,
            'evidence' => array_map(fn (int $index): array => [
                'source' => "components[{$index}].price",
                'quote' => "components[{$index}].price",
            ], $indexes),
        ];
    }

    /** @param list<array<string, mixed>> $phases @return array<string, mixed> */
    private function interpretationOutput(array $phases, string $misleading = 'not_detected'): array
    {
        return [
            'classification' => [
                'primary_pricing_model' => 'FixedPrice',
            ],
            'pricing' => [
                'phases' => $phases,
                'recurring_schedule' => [
                    'present' => false,
                    'cadence' => 'none',
                    'current_period_start' => null,
                    'current_period_end' => null,
                ],
                'consumption_effect' => [
                    'expected_cents_per_kwh' => null,
                    'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null,
                ],
            ],
            'source_consistency' => [
                'misleading_first_12_months' => $misleading,
            ],
        ];
    }
}
