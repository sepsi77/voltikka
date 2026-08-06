<?php

namespace Tests\Unit;

use App\Services\ContractStatistics\AnnualSeriesCompatibility;
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
}
