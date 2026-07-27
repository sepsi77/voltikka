<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompareCanonicalPricingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fail_on_parse_errors_detects_malformed_non_null_canonical_json(): void
    {
        Company::create(['name' => 'Test Energy']);
        $contract = ElectricityContract::create([
            'id' => 'malformed-canonical',
            'company_name' => 'Test Energy',
            'name' => 'Malformed canonical contract',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => ['phases' => 'not-an-array'],
            'canonical_calculation' => [
                'status' => 'exact',
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => [
                'structured_pricing_status' => 'complete',
                'misleading_first_12_months' => 'not_detected',
                'issue_codes' => [],
            ],
        ]);
        ActiveContract::create(['id' => $contract->id]);

        $this->artisan('contracts:compare-canonical-pricing', [
            '--fail-on-parse-errors' => true,
        ])->assertFailed();
    }
}
