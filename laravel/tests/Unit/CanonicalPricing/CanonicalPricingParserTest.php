<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\MisleadingState;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use PHPUnit\Framework\TestCase;

class CanonicalPricingParserTest extends TestCase
{
    private CanonicalPricingParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalPricingParser;
    }

    private function phase(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Aloitushinta',
            'phase_kind' => 'introductory',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'date', 'value' => '2026-07-31'],
            'components' => [
                [
                    'component_type' => 'energy_general',
                    'amount' => 5.49,
                    'normal_amount' => null,
                    'unit' => 'cents_per_kwh',
                    'vat_status' => 'included',
                    'price_role' => 'introductory',
                    'source_kind' => 'both',
                    'evidence' => [],
                ],
            ],
            'package' => null,
            'evidence' => [],
        ], $overrides);
    }

    private function pricing(array $phases): array
    {
        return [
            'phases' => $phases,
            'recurring_schedule' => [
                'present' => false,
                'cadence' => 'none',
                'current_period_start' => null,
                'current_period_end' => null,
                'future_price_known' => null,
                'description' => null,
                'evidence' => [],
            ],
            'consumption_effect' => [
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
        ];
    }

    public function test_parses_valid_promo_contract(): void
    {
        $data = $this->parser->parse(
            $this->pricing([$this->phase()]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [
                'misleading_first_12_months' => 'detected',
                'structured_pricing_status' => 'incomplete',
                'issue_codes' => ['structured_matches_intro_only', 'future_price_omitted'],
            ],
        );

        $this->assertCount(1, $data->phases);
        $this->assertSame(CalculationStatus::Exact, $data->calculationStatus);
        $this->assertSame(MisleadingState::Detected, $data->misleadingState);
        $this->assertTrue($data->hasIssueCode('future_price_omitted'));
        $this->assertSame(ComponentType::EnergyGeneral, $data->phases[0]->components[0]->type);
        $this->assertTrue($data->phases[0]->hasKnownPricing());
    }

    public function test_empty_components_phase_is_unknown_coverage_not_zero(): void
    {
        $data = $this->parser->parse(
            $this->pricing([$this->phase(['components' => []])]),
            ['status' => 'incomplete', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );

        $this->assertFalse($data->phases[0]->hasKnownPricing());
    }

    public function test_unknown_component_type_throws(): void
    {
        $this->expectException(CanonicalPricingParseException::class);

        $this->parser->parse(
            $this->pricing([$this->phase([
                'components' => [[
                    'component_type' => 'mystery_v4_type',
                    'amount' => 1.0,
                    'normal_amount' => null,
                    'unit' => 'cents_per_kwh',
                    'vat_status' => 'included',
                    'price_role' => 'normal',
                    'source_kind' => 'both',
                    'evidence' => [],
                ]],
            ])]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );
    }

    public function test_unknown_boundary_kind_throws(): void
    {
        $this->expectException(CanonicalPricingParseException::class);

        $this->parser->parse(
            $this->pricing([$this->phase(['starts' => ['kind' => 'lunar_cycle', 'value' => null]])]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );
    }

    public function test_unknown_calculation_status_throws(): void
    {
        $this->expectException(CanonicalPricingParseException::class);

        $this->parser->parse(
            $this->pricing([$this->phase()]),
            ['status' => 'maybe', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );
    }

    public function test_missing_pricing_throws(): void
    {
        $this->expectException(CanonicalPricingParseException::class);

        $this->parser->parse(null, ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []], []);
    }

    public function test_conflicting_vat_basis_for_same_component_throws(): void
    {
        $this->expectException(CanonicalPricingParseException::class);

        $this->parser->parse(
            $this->pricing([
                $this->phase(),
                $this->phase([
                    'phase_kind' => 'normal',
                    'components' => [[
                        'component_type' => 'energy_general',
                        'amount' => 13.65,
                        'normal_amount' => null,
                        'unit' => 'cents_per_kwh',
                        'vat_status' => 'excluded',
                        'price_role' => 'normal',
                        'source_kind' => 'description',
                        'evidence' => [],
                    ]],
                ]),
            ]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );
    }

    public function test_parses_complete_monthly_included_energy_package(): void
    {
        $data = $this->parser->parse(
            $this->pricing([$this->phase([
                'components' => [],
                'package' => [
                    'monthly_fee_eur' => 25.0,
                    'included_kwh' => 150.0,
                    'allowance_cadence' => 'monthly',
                    'excess_rate_cents_per_kwh' => 16.6,
                    'evidence' => [],
                ],
            ])]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );

        $this->assertSame(25.0, $data->phases[0]->package?->monthlyFeeEur);
        $this->assertSame(150.0, $data->phases[0]->package?->includedKwh);
        $this->assertSame(16.6, $data->phases[0]->package?->excessRateCentsPerKwh);
        $this->assertTrue($data->phases[0]->hasKnownPricing());
    }

    public function test_package_with_missing_or_invalid_values_fails_closed(): void
    {
        foreach ([
            ['monthly_fee_eur' => 25.0, 'included_kwh' => null, 'allowance_cadence' => 'monthly', 'excess_rate_cents_per_kwh' => 16.6],
            ['monthly_fee_eur' => 25.0, 'included_kwh' => 150.0, 'allowance_cadence' => 'monthly', 'excess_rate_cents_per_kwh' => null],
            ['monthly_fee_eur' => 25.0, 'included_kwh' => 150.0, 'allowance_cadence' => 'annual', 'excess_rate_cents_per_kwh' => 16.6],
        ] as $package) {
            try {
                $this->parser->parse(
                    $this->pricing([$this->phase(['components' => [], 'package' => $package])]),
                    ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
                    [],
                );
                $this->fail('Invalid package data did not fail closed.');
            } catch (CanonicalPricingParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_package_rejects_duplicate_component_charges(): void
    {
        $this->expectException(CanonicalPricingParseException::class);

        $this->parser->parse(
            $this->pricing([$this->phase([
                'package' => [
                    'monthly_fee_eur' => 49.0,
                    'included_kwh' => 350.0,
                    'allowance_cadence' => 'monthly',
                    'excess_rate_cents_per_kwh' => 16.6,
                ],
            ])]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );
    }

    public function test_flat_and_monthly_fee_duplicate_fails_closed(): void
    {
        $this->expectException(CanonicalPricingParseException::class);

        $monthly = $this->phase()['components'][0];
        $monthly['component_type'] = 'monthly_fee';
        $monthly['unit'] = 'eur_per_month';
        $flat = $monthly;
        $flat['component_type'] = 'flat_fee';

        $this->parser->parse(
            $this->pricing([$this->phase(['components' => [$monthly, $flat]])]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [],
        );
    }

    public function test_unknown_issue_codes_are_dropped_not_fatal(): void
    {
        $data = $this->parser->parse(
            $this->pricing([$this->phase()]),
            ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            [
                'misleading_first_12_months' => 'detected',
                'issue_codes' => ['future_price_omitted', 'v4_new_code'],
            ],
        );

        $this->assertSame(['future_price_omitted'], $data->issueCodes);
    }
}
