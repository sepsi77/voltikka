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
        $this->parser = new CanonicalPricingParser();
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
