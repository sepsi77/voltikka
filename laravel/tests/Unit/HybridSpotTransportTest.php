<?php

namespace Tests\Unit;

use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\CanonicalPricing\Support\HoldFlatCanonicalCalculator;

class HybridSpotTransportTest extends TestCase
{
    public static function methods(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('methods')]
    public function test_sequential_calculator_output_round_trips(bool $forward): void
    {
        $payload = $this->payload($forward);
        $this->assertSame('base_only_hybrid', $payload['comparability']);
        $this->assertSame($forward ? 'forward_curve_spot' : 'rolling_365_spot', $payload['estimate_method']);
        $this->assertTrue($payload['is_estimate']);
        $this->assertNull($payload['consumption_effect']['expected_cents_per_kwh']);
        $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
    }

    public function test_rolling_method_rejects_valid_forward_provenance(): void
    {
        $payload = $this->payload(false);
        $payload['spot_estimate'] = $this->payload(true)['spot_estimate'];
        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    public static function invalidCases(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'wrong_basis', 'none', 'not_estimated', 'effect_absent', 'same_phase', 'overlap', 'only_spot', 'only_base']);
    }

    #[DataProvider('invalidCases')]
    public function test_unsafe_transport_is_rejected(string $case): void
    {
        $payload = $this->payload(true);
        switch ($case) {
            case 'missing': $payload['spot_estimate'] = null;
                break;
            case 'wrong_basis': $payload['spot_estimate'] = $this->payload(false)['spot_estimate'];
                break;
            case 'none': $payload['estimate_method'] = 'none';
                break;
            case 'not_estimated': $payload['is_estimate'] = false;
                break;
            case 'effect_absent': $payload['consumption_effect']['present'] = false;
                break;
            case 'same_phase': $payload['phase_breakdown'][1]['energy_cents'] = 12.8;
                break;
            case 'overlap': $payload['phase_breakdown'][1]['window_start'] = $payload['phase_breakdown'][0]['window_end'];
                break;
            case 'only_spot': $payload['phase_breakdown'] = [$payload['phase_breakdown'][1]];
                break;
            case 'only_base': $payload['phase_breakdown'] = [$payload['phase_breakdown'][0]];
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    private function payload(bool $forward): array
    {
        $phase = static fn ($type, $amount, $start, $end) => [
            'label' => 'Synthetic phase', 'phase_kind' => 'current_structured',
            'starts' => ['kind' => $start === 0 ? 'contract_start' : 'after_months', 'value' => $start === 0 ? null : (string) $start],
            'ends' => ['kind' => 'after_months', 'value' => (string) $end],
            'components' => [['component_type' => $type, 'amount' => $amount, 'normal_amount' => null, 'unit' => 'cents_per_kwh', 'price_role' => 'current', 'vat_status' => 'included']],
        ];
        $data = (new CanonicalPricingParser)->parse([
            'phases' => [$phase('energy_general', 10, 0, 6), $phase('spot_margin', 0.55, 6, 12)],
            'consumption_effect' => ['present' => true, 'applies_to' => 'base_contract', 'cadence' => 'monthly', 'expected_cents_per_kwh' => null],
        ], ['status' => 'estimate_required'], ['structured_pricing_status' => 'complete', 'misleading_first_12_months' => 'not_detected']);
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[sprintf('2026-%02d', $month)] = ['base_price' => 6.0, 'day_price' => 7.0, 'night_price' => 5.0, 'source_kind' => 'month', 'trade_date' => '2025-12-31'];
        }
        $estimate = new SpotEstimate(
            basis: $forward ? SpotEstimateBasis::ForwardCurve : SpotEstimateBasis::Rolling365Fallback,
            shapeOverallCentsPerKwh: 6, shapeDayCentsPerKwh: 7, shapeNightCentsPerKwh: 5,
            dayOffsetCentsPerKwh: 1, nightOffsetCentsPerKwh: -1,
            shapePeriodStart: '2025-01-01', shapePeriodEnd: '2025-12-31',
            currentCurveTradeDate: $forward ? '2025-12-31' : null,
            futureCurveTradeDate: $forward ? '2025-12-31' : null,
            months: $forward ? $months : [],
            annualEquivalentBaseCentsPerKwh: 6, annualEquivalentDayCentsPerKwh: 7, annualEquivalentNightCentsPerKwh: 5,
            confidence: $forward ? 'higher' : 'fallback',
        );

        return HoldFlatCanonicalCalculator::make()->calculate(
            $data, new ContractContext('Hybrid', 'OpenEnded', 'General', null, 'Household'),
            new EnergyUsage(total: 5000, basicLiving: 5000), new SpotAssumptions(7, 5),
            CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki'), spotEstimate: $estimate, policy: ComparisonPolicy::Current,
        )->toCalculatedCostArray();
    }
}
