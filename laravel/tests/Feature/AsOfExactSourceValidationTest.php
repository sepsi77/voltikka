<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationProfile;
use App\Services\ContractStatistics\AsOfAnnualCostEvidenceResolver;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AsOfExactSourceValidationTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private ContractSourceSnapshot $source;

    private ContractSourceObservation $observation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = json_decode(file_get_contents(base_path('tests/Fixtures/surffari-active-until-date-discount.json')), true);
        $input = $this->fixture['input'];
        Company::create(['name' => $input['company_name'], 'name_slug' => 'exact-source']);
        ElectricityContract::factory()->forCompany($input['company_name'])->create(['id' => $input['contract_id']]);
        $payload = [
            'Id' => $input['api_id'], 'Name' => $input['contract_name'], 'Company' => ['Name' => $input['company_name']],
            'Details' => [
                'PricingModel' => $input['pricing_model'], 'ContractType' => $input['contract_type'],
                'FixedTimeRange' => $input['fixed_time_range'], 'Metering' => $input['metering'],
                'TargetGroup' => $input['target_group'], 'SpotPriceSelection' => $input['spot_price_selection'],
                'ShortDescription' => $input['short_description'], 'LongDescription' => $input['long_description'],
                'ExtraInformation' => ['FI' => $input['extra_information_fi'], 'Default' => $input['extra_information_default']],
                'TimePeriodDefinitions' => $input['time_period_definitions'], 'BillingFrequency' => $input['billing_frequency'],
                'ConsumptionLimitation' => $input['consumption_limitation'],
                'Pricing' => ['Name' => $input['pricing_name'], 'HasDiscount' => $input['pricing_has_discounts'],
                    'PriceComponents' => array_map(fn (array $c): array => [
                        'Id' => $c['id'], 'PriceComponentType' => $c['price_component_type'], 'FuseSize' => $c['fuse_size'],
                        'OriginalPayment' => ['Price' => $c['price'], 'PaymentUnit' => $c['payment_unit']],
                        'HasDiscount' => $c['has_discount'], 'Discount' => [
                            'DiscountValue' => $c['discount_value'], 'IsPercentage' => $c['discount_is_percentage'],
                            'DiscountType' => $c['discount_type'], 'NFirstKwh' => $c['discount_n_first_kwh'],
                            'NfirstMonths' => $c['discount_n_first_months'], 'UntilDate' => $c['discount_until_date'],
                        ],
                    ], $input['components']),
                ],
            ],
        ];
        $this->source = ContractSourceSnapshot::create([
            'contract_id' => $input['contract_id'], 'source_fingerprint' => hash('sha256', 'campaign'),
            'source_payload' => $payload, 'first_observed_at' => '2026-07-23 12:00:00', 'last_observed_at' => '2026-09-02 12:00:00',
        ]);
        $this->observation = ContractSourceObservation::create([
            'contract_id' => $input['contract_id'], 'source_snapshot_id' => $this->source->id,
            'first_observed_at' => '2026-07-23 12:00:00', 'last_observed_at' => '2026-09-02 12:00:00',
        ]);
        foreach (['2026-07-23', '2026-07-24', '2026-08-31', '2026-09-01'] as $date) {
            DB::table('contract_price_snapshots')->insert([
                'snapshot_date' => $date, 'contract_id' => $input['contract_id'], 'company_name' => $input['company_name'],
                'contract_name' => $input['contract_name'], 'pricing_model' => 'Spot', 'contract_type' => 'OpenEnded',
                'metering' => 'General', 'segment_key' => 'spot', 'pricing_basis' => 'observed_seller_data',
                'has_discount' => true, 'includes_spot_price' => true,
            ]);
        }
        $this->assertSame($input, app(ContractInterpretationInputBuilder::class)->build($this->source, '2026-07-23', ContractInterpretationProfile::historical()));
    }

    public function test_latest_valid_exact_source_is_stable_across_completion_day_and_preserves_provenance(): void
    {
        $early = $this->interpretation($this->fixture['corrected_output'], '2026-07-23 14:00:00');
        $latest = $this->interpretation($this->fixture['corrected_output'], '2026-07-24 14:00:00');
        foreach (['2026-07-23', '2026-07-24'] as $date) {
            $evidence = $this->resolve($date);
            $this->assertSame($latest->id, $evidence->sourceEvidenceIds['interpretation_id']);
            $this->assertSame($date, $evidence->sourceInterpretationProvenance->targetDate->toDateString());
            $this->assertSame($date === '2026-07-23', $evidence->sourceInterpretationProvenance->retrospective);
            $this->assertSame($this->observation->id, $evidence->sourceInterpretationProvenance->analysisObservationId);
        }
        $this->assertSame($early->id, $this->resolve('2026-07-23', AnnualCostMethodVersion::AsOfV2)->sourceEvidenceIds['interpretation_id']);
        $this->assertSame($early->id, $this->resolve('2026-07-23', AnnualCostMethodVersion::AsOf)->sourceEvidenceIds['interpretation_id']);
    }

    public function test_target_validation_rejects_removed_active_campaign_and_selects_next_valid_output(): void
    {
        $valid = $this->interpretation($this->fixture['corrected_output'], '2026-07-24 14:00:00');
        $removed = $this->interpretation($this->fixture['faulty_output'], '2026-09-02 14:00:00');
        foreach (['2026-07-23', '2026-07-24', '2026-08-31'] as $date) {
            $evidence = $this->resolve($date);
            $this->assertSame($valid->id, $evidence->sourceEvidenceIds['interpretation_id']);
            $this->assertSame([$removed->id], $evidence->sourceEvidenceIds['target_evidence_rejected_interpretation_ids']);
            $this->assertContains('canonical_interpretation_rejected_by_exact_target_validation', $evidence->provenanceFlags);
            $this->assertSame(0.2, $this->signupMargin($evidence->canonicalData, $date));
        }
        $expired = $this->resolve('2026-09-01');
        $this->assertSame($removed->id, $expired->sourceEvidenceIds['interpretation_id']);
        $this->assertSame([$valid->id], $expired->sourceEvidenceIds['target_evidence_rejected_interpretation_ids']);
        $this->assertSame(0.6, $this->signupMargin($expired->canonicalData, '2026-09-01'));
    }

    public function test_announced_future_rate_remains_future_and_unproved_calendar_assumption_is_rejected(): void
    {
        $valid = $this->interpretation($this->fixture['corrected_output'], '2026-07-24 14:00:00');
        $assumed = $this->fixture['faulty_output'];
        $assumed['pricing']['phases'][0]['starts']['value'] = '2026-09-02';
        $invalid = $this->interpretation($assumed, '2026-09-03 14:00:00');
        $evidence = $this->resolve('2026-09-01');
        $this->assertNull($evidence->canonicalData);
        $this->assertContains('historical_temporal_source_validation_unavailable', $evidence->provenanceFlags);
        $this->assertSame([$valid->id, $invalid->id], $evidence->sourceEvidenceIds['target_evidence_rejected_interpretation_ids']);
        $july = $this->resolve('2026-07-23');
        $this->assertSame(0.2, $this->signupMargin($july->canonicalData, '2026-07-23'));
        $segments = app(PhaseTimelineBuilder::class)->build($july->canonicalData->phases, $july->canonicalData->recurringSchedule, CarbonImmutable::parse('2026-07-23', 'Europe/Helsinki'));
        foreach ($segments as $segment) {
            if ($segment->phaseIndex === 1) {
                $this->assertGreaterThanOrEqual('2026-09-01', $segment->start->toDateString());
            }
        }
    }

    public function test_newest_tie_fails_closed_and_wrong_recurrent_episode_cannot_win(): void
    {
        $valid = $this->interpretation($this->fixture['corrected_output'], '2026-07-24 14:00:00');
        $tie = $this->interpretation($this->fixture['corrected_output'], '2026-07-24 14:00:00');
        $this->assertContains('canonical_omitted_ambiguous_interpretation_chronology', $this->resolve('2026-07-23')->provenanceFlags);
        $episode = ContractSourceObservation::create([
            'contract_id' => $this->source->contract_id, 'source_snapshot_id' => $this->source->id,
            'first_observed_at' => '2026-09-10 12:00:00', 'last_observed_at' => '2026-09-11 12:00:00',
        ]);
        $tie->update(['completed_at' => '2026-09-10 14:00:00', 'analysis_source_observation_id' => $episode->id]);
        $this->assertSame($valid->id, $this->resolve('2026-07-23')->sourceEvidenceIds['interpretation_id']);
        $valid->update(['analysis_source_observation_id' => null]);
        $this->assertContains('exact_source_legacy_null_observation_binding', $this->resolve('2026-07-23')->provenanceFlags);
    }

    public function test_registered_legacy_profile_uses_its_schema_and_full_target_discount_checks(): void
    {
        $output = $this->fixture['corrected_output'];
        $output['schema_version'] = '1.0';
        foreach ($output['pricing']['phases'] as &$phase) {
            unset($phase['package']);
        }
        unset($phase);
        $row = $this->interpretation($output, '2026-07-24 14:00:00');
        $row->update(['schema_version' => 'schema-v3', 'prompt_version' => 'prompt-v17', 'validator_version' => 'validator-v14']);
        $this->assertSame($row->id, $this->resolve('2026-07-23')->sourceEvidenceIds['interpretation_id']);
        $output['pricing']['phases'] = [$output['pricing']['phases'][1]];
        $row->update(['output' => $output]);
        $this->assertNull($this->resolve('2026-07-23')->canonicalData);
    }

    public function test_later_different_source_cannot_repair_missing_exact_source_output(): void
    {
        $laterSource = $this->source->replicate();
        $laterSource->fill(['source_fingerprint' => hash('sha256', 'different-source')])->save();
        $episode = ContractSourceObservation::create([
            'contract_id' => $this->source->contract_id, 'source_snapshot_id' => $laterSource->id,
            'first_observed_at' => '2026-09-10 12:00:00', 'last_observed_at' => '2026-09-11 12:00:00',
        ]);
        $row = $this->interpretation($this->fixture['corrected_output'], '2026-09-10 14:00:00');
        $row->update(['source_snapshot_id' => $laterSource->id, 'analysis_source_observation_id' => $episode->id]);
        $evidence = $this->resolve('2026-07-23');
        $this->assertNull($evidence->canonicalData);
        $this->assertSame($this->source->id, $evidence->sourceEvidenceIds['source_snapshot_id']);
        $this->assertContains('canonical_omitted_no_interpretation_as_of_date', $evidence->provenanceFlags);
    }

    public function test_unsupported_stored_profile_remains_explicitly_unresolved(): void
    {
        $row = $this->interpretation($this->fixture['corrected_output'], '2026-07-24 14:00:00');
        $row->update(['validator_version' => 'unregistered']);
        $evidence = $this->resolve('2026-07-23');
        $this->assertNull($evidence->canonicalData);
        $this->assertContains('historical_stored_interpretation_profile_unavailable', $evidence->provenanceFlags);
    }

    private function interpretation(array $output, string $completed): ContractInterpretation
    {
        return ContractInterpretation::create([
            'contract_id' => $this->source->contract_id, 'source_snapshot_id' => $this->source->id,
            'analysis_source_observation_id' => $this->observation->id, 'analysis_fingerprint' => hash('sha256', (string) ContractInterpretation::count()),
            'schema_version' => 'schema-v4', 'prompt_version' => 'prompt-v19', 'validator_version' => 'validator-v17',
            'provider' => 'test', 'model' => 'test', 'status' => 'superseded', 'output' => $output,
            'validation_errors' => [], 'completed_at' => $completed,
        ]);
    }

    private function resolve(string $date, AnnualCostMethodVersion $version = AnnualCostMethodVersion::AsOfV3)
    {
        return app(AsOfAnnualCostEvidenceResolver::class)->resolveDate($date, $version)[$this->source->contract_id];
    }

    private function signupMargin($data, string $date): float
    {
        $segments = app(PhaseTimelineBuilder::class)->build($data->phases, $data->recurringSchedule, CarbonImmutable::parse($date, 'Europe/Helsinki'));

        return collect($data->phases[$segments[0]->phaseIndex]->components)->first(fn ($component) => $component->type === ComponentType::SpotMargin)->amount;
    }
}
