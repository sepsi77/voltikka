<?php

namespace Tests\Unit;

use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use PHPUnit\Framework\TestCase;

class AnnualSeriesCompatibilityTest extends TestCase
{
    public function test_all_null_keys_form_one_legacy_regime_and_mix_with_a_named_key(): void
    {
        $compatibility = new AnnualSeriesCompatibility;

        $legacy = $compatibility->evaluatePeriod([null, null]);
        $mixed = $compatibility->evaluatePeriod([null, 'forward']);

        $this->assertTrue($legacy['comparable']);
        $this->assertNull($legacy['compatibility_key']);
        $this->assertFalse($mixed['comparable']);
        $this->assertTrue($mixed['mixed']);
    }

    public function test_first_daily_point_after_a_transition_is_a_gap(): void
    {
        $compatibility = new AnnualSeriesCompatibility;

        $this->assertTrue($compatibility->evaluatePeriod(['rolling'])['comparable']);
        $this->assertFalse($compatibility->evaluatePeriod(['forward'])['comparable']);
        $this->assertTrue($compatibility->evaluatePeriod(['forward'])['comparable']);
    }

    public function test_as_of_display_key_uses_the_dominant_method_not_the_minority_set(): void
    {
        $first = AnnualSeriesCompatibility::aggregateDisplayKey(
            'strict-a',
            AnnualCostMethodVersion::AsOf->value,
            ['estimate_method' => ['hold_current_supplier_price' => 40, 'seasonal_index' => 2]],
        );
        $second = AnnualSeriesCompatibility::aggregateDisplayKey(
            'strict-b',
            AnnualCostMethodVersion::AsOf->value,
            ['estimate_method' => ['forward_curve' => 1, 'hold_current_supplier_price' => 41]],
        );

        $this->assertSame($first, $second);
    }

    public function test_as_of_display_key_changes_with_the_dominant_method(): void
    {
        $hold = AnnualSeriesCompatibility::aggregateDisplayKey(
            'strict-a',
            AnnualCostMethodVersion::AsOf->value,
            ['estimate_method' => ['hold_current_supplier_price' => 40, 'seasonal_index' => 2]],
        );
        $seasonal = AnnualSeriesCompatibility::aggregateDisplayKey(
            'strict-b',
            AnnualCostMethodVersion::AsOf->value,
            ['estimate_method' => ['hold_current_supplier_price' => 2, 'seasonal_index' => 40]],
        );

        $this->assertNotSame($hold, $seasonal);
    }

    public function test_as_of_display_key_ties_are_sorted_and_deterministic(): void
    {
        $left = AnnualSeriesCompatibility::aggregateDisplayKey(
            'strict-a',
            AnnualCostMethodVersion::AsOf->value,
            ['estimate_method' => ['seasonal_index' => 4, 'forward_curve' => 4, 'none' => 1]],
        );
        $right = AnnualSeriesCompatibility::aggregateDisplayKey(
            'strict-b',
            AnnualCostMethodVersion::AsOf->value,
            ['estimate_method' => ['forward_curve' => 4, 'none' => 2, 'seasonal_index' => 4]],
        );

        $this->assertSame($left, $right);
    }

    public function test_supplier_forward_premium_and_shift_tie_stays_one_display_regime(): void
    {
        $premiumLead = $this->v3DisplayKey(['supplier_adjusted_forward_premium' => 19, 'supplier_adjusted_forward_curve_shift' => 16, 'none' => 4]);
        $tie = $this->v3DisplayKey(['supplier_adjusted_forward_premium' => 18, 'supplier_adjusted_forward_curve_shift' => 18, 'none' => 4]);
        $shiftLead = $this->v3DisplayKey(['supplier_adjusted_forward_premium' => 18, 'supplier_adjusted_forward_curve_shift' => 19, 'none' => 4]);

        $this->assertSame($premiumLead, $tie);
        $this->assertSame($premiumLead, $shiftLead);

        $compatibility = new AnnualSeriesCompatibility;
        foreach ([[$premiumLead, $premiumLead], [$premiumLead, $tie], [$shiftLead, $shiftLead]] as $week) {
            $this->assertTrue($compatibility->evaluatePeriod($week)['comparable']);
        }
    }

    public function test_supplier_forward_family_sums_variants_before_choosing_the_dominant_method(): void
    {
        // Each variant alone is smaller than `none`, but the family together is larger.
        $family = $this->v3DisplayKey(['supplier_adjusted_forward_premium' => 15, 'supplier_adjusted_forward_curve_shift' => 15, 'none' => 20]);
        $shiftOnly = $this->v3DisplayKey(['supplier_adjusted_forward_curve_shift' => 30, 'none' => 20]);
        $none = $this->v3DisplayKey(['none' => 20]);

        $this->assertSame($shiftOnly, $family);
        $this->assertNotSame($none, $family);
    }

    public function test_supplier_seasonal_index_fallback_still_splits_from_the_forward_family(): void
    {
        $forward = $this->v3DisplayKey(['supplier_adjusted_forward_premium' => 18, 'supplier_adjusted_forward_curve_shift' => 18]);
        $seasonal = $this->v3DisplayKey(['supplier_adjusted_spot_seasonal_index' => 37, 'supplier_adjusted_forward_premium' => 1]);
        $hold = $this->v3DisplayKey(['hold_current_supplier_price' => 36]);

        $this->assertNotSame($forward, $seasonal);
        $this->assertNotSame($forward, $hold);

        $compatibility = new AnnualSeriesCompatibility;
        $this->assertTrue($compatibility->evaluatePeriod([$forward])['comparable']);
        $this->assertFalse($compatibility->evaluatePeriod([$forward, $seasonal])['comparable']);
    }

    public function test_recurring_forward_variants_form_one_family_separate_from_supplier_and_fallbacks(): void
    {
        $recurringShift = $this->v3DisplayKey(['recurring_forward_curve_shift' => 10, 'recurring_forward_premium' => 6]);
        $recurringPremium = $this->v3DisplayKey(['recurring_forward_curve_shift' => 6, 'recurring_forward_premium' => 10]);
        $supplier = $this->v3DisplayKey(['supplier_adjusted_forward_curve_shift' => 10, 'supplier_adjusted_forward_premium' => 6]);
        $recurringSeasonal = $this->v3DisplayKey(['recurring_spot_seasonal_index' => 16]);
        $spotFallback = $this->v3DisplayKey(['rolling_365_spot' => 16]);
        $spotForward = $this->v3DisplayKey(['forward_curve_spot' => 16]);

        $this->assertSame($recurringShift, $recurringPremium);
        $this->assertNotSame($recurringShift, $supplier);
        $this->assertNotSame($recurringShift, $recurringSeasonal);
        $this->assertNotSame($spotForward, $spotFallback);
    }

    public function test_corrected_method_has_a_separate_display_regime(): void
    {
        $counts = ['estimate_method' => ['forward_curve' => 10]];
        $v1 = AnnualSeriesCompatibility::aggregateDisplayKey('strict', AnnualCostMethodVersion::AsOf->value, $counts);
        $v2 = AnnualSeriesCompatibility::aggregateDisplayKey('strict', AnnualCostMethodVersion::AsOfV2->value, $counts);
        $this->assertNotSame($v1, $v2);
        $this->assertSame($v2, AnnualSeriesCompatibility::aggregateDisplayKey('other', AnnualCostMethodVersion::AsOfV2->value, $counts));
        $compatibility = new AnnualSeriesCompatibility;
        $this->assertTrue($compatibility->evaluatePeriod([$v1])['comparable']);
        $this->assertFalse($compatibility->evaluatePeriod([$v2])['comparable']);
        $this->assertTrue($compatibility->evaluatePeriod([$v2])['comparable']);
    }

    public function test_display_key_falls_back_for_legacy_and_malformed_counts(): void
    {
        $this->assertSame('legacy-stored', AnnualSeriesCompatibility::aggregateDisplayKey(
            'legacy-stored',
            AnnualCostMethodVersion::Legacy->value,
            ['estimate_method' => ['none' => 10]],
        ));

        foreach ([null, [], ['estimate_method' => []], ['estimate_method' => ['none' => 0]], ['estimate_method' => ['none' => '3']]] as $counts) {
            $this->assertSame('as-of-stored', AnnualSeriesCompatibility::aggregateDisplayKey(
                'as-of-stored',
                AnnualCostMethodVersion::AsOf->value,
                $counts,
            ));
        }
    }

    /**
     * @param  array<string, int>  $methodCounts
     */
    private function v3DisplayKey(array $methodCounts): ?string
    {
        return AnnualSeriesCompatibility::aggregateDisplayKey(
            'strict-'.md5(json_encode($methodCounts)),
            AnnualCostMethodVersion::AsOfV3->value,
            ['estimate_method' => $methodCounts],
        );
    }
}
