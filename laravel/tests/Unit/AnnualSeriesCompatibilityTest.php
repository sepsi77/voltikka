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
}
