<?php

namespace Tests\Feature;

use App\Enums\MeteringType;
use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentNormalCandidateExtractor;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedEligibility;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;

class CurrentNormalEpisodeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Company::create(['name' => 'Normal Energy', 'name_slug' => 'normal-energy']);
    }

    public function test_persisted_empty_validation_results_keep_actual_and_normal_anchors(): void
    {
        foreach ([false, true] as $normal) {
            $contract = $this->contract($normal ? 'empty-normal' : 'empty-actual');
            [$row] = $this->episode($contract, '2026-09-15', '2026-09-20');
            if (! $normal) {
                $output = $row->output;
                $phase = $output['pricing']['phases'][1];
                $phase['starts'] = ['kind' => 'contract_start', 'value' => null];
                $phase['phase_kind'] = 'current_structured';
                $phase['components'][0]['price_role'] = 'current';
                unset($phase['components'][0]['energy_rule']);
                $output['pricing']['phases'] = [$phase];
                $row->update(['schema_version' => 'schema-v4', 'prompt_version' => 'prompt-v19', 'validator_version' => 'validator-v17', 'output' => $output]);
            }
            $candidate = new SupplierAdjustedCandidate($contract->id, 9, 0, normalTariffEvidence: $normal);
            $resolve = fn () => (new CurrentPriceEpisodeResolver)->resolve([$contract->id => $candidate], CarbonImmutable::parse('2026-09-20'))[$contract->id];
            $expected = $resolve();
            $this->assertSame('2026-09-15', $expected->startedAt?->toDateString());
            foreach ([null, 'null', '[]', ' null ', '[ ]'] as $errors) {
                DB::table('contract_interpretations')->where('id', $row->id)->update(['validation_errors' => $errors]);
                $this->assertEquals($expected, $resolve());
            }
            foreach (['{', '', 'null broken', '["invalid"]', '[null]', 'false', 'true', '0', '1', '""', '"null"', '"[]"', '{}', '{"error":"invalid"}'] as $errors) {
                DB::table('contract_interpretations')->where('id', $row->id)->update(['validation_errors' => $errors]);
                $this->assertNull($resolve()->startedAt, $errors);
            }
            $row->update(['validation_errors' => null]);
            $this->assertEquals($expected, $resolve());
            $contract->update(['published_interpretation_id' => null]);
            $this->assertNull($resolve()->startedAt);
            $contract->update(['published_interpretation_id' => $row->id]);
            $other = $this->contract($normal ? 'owner-normal' : 'owner-actual');
            [, , $otherObservation] = $this->episode($other, '2026-09-15', '2026-09-20');
            $row->update(['analysis_source_observation_id' => $otherObservation->id]);
            $this->assertNull($resolve()->startedAt);
            $row->update(['analysis_source_observation_id' => null, 'contract_id' => $other->id]);
            $this->assertNull($resolve()->startedAt);
            $row->update(['contract_id' => $contract->id, 'status' => 'failed']);
            $this->assertNull($resolve()->startedAt);
            if ($normal) {
                $row->update(['status' => 'published', 'validator_version' => 'validator-v17']);
                $this->assertNull($resolve()->startedAt);
            }
        }
    }

    public function test_successful_job_publication_stores_null_and_supplies_normal_anchor(): void
    {
        $this->travelTo('2026-09-15 10:00:00');
        Queue::fake();
        $contract = $this->contract('job-null');
        [$row] = $this->episode($contract, '2026-09-15', '2026-09-20');
        $row->update(['status' => 'pending', 'published_at' => null, 'completed_at' => null]);
        $contract->update(['published_interpretation_id' => null]);
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($row->output)]]]])]);

        app()->call([new AnalyzeContractSourceSnapshot($row->id), 'handle']);

        $this->assertSame('published', $row->fresh()->status);
        $this->assertSame($row->id, $contract->fresh()->published_interpretation_id);
        $this->assertNull(DB::table('contract_interpretations')->where('id', $row->id)->value('validation_errors'));
        $this->assertSame('2026-09-15', $this->resolve($contract)->startedAt?->toDateString());
        Http::assertSentCount(1);
    }

    public function test_actual_four_dates_the_independent_normal_nine_observation(): void
    {
        $contract = $this->contract('normal');
        $this->episode($contract, '2026-09-15', '2026-09-20');
        $anchor = $this->resolve($contract);
        $this->assertSame('2026-09-15', $anchor->startedAt?->toDateString());
        $this->assertNull($this->resolve($contract, 4)->startedAt);
        $candidate = $this->extract(F::example()[1]);
        $this->assertSame(9.0, $candidate->currentEnergyPriceCentsPerKwh);
        $this->assertSame(0.0, $candidate->monthlyFeeEur);
        $this->assertTrue($candidate->normalTariffEvidence);
        $this->assertTrue($candidate->hasSameEnergySignature(new SupplierAdjustedCandidate('other', 9, 99)));
    }

    public function test_unchanged_pointed_normal_anchor_survives_midnight_without_new_observations(): void
    {
        $contract = $this->contract('midnight');
        [, , $observation] = $this->episode($contract, '2026-09-15', '2026-09-19', status: 'estimate_required');
        $anchor = $this->resolve($contract);
        $this->assertSame('2026-09-15', $anchor->startedAt?->toDateString());
        $this->assertContains('price_episode_right_observation_gap', $anchor->flags);
        $this->assertSame('2026-09-19', $observation->fresh()->last_observed_at->toDateString());
        $this->assertSame($observation->id, $contract->fresh()->current_source_observation_id);

        // No still-current pointer means ended evidence, not an evergreen reference.
        $contract->update(['current_source_observation_id' => null]);
        $this->assertNull($this->resolve($contract)->startedAt);
    }

    public function test_source_proved_estimate_required_normal_remains_episode_evidence(): void
    {
        $contract = $this->contract('estimate-normal');
        [$row] = $this->episode($contract, '2026-09-15', '2026-09-20', status: 'estimate_required');
        $this->assertSame('estimate_required', $row->output['calculation']['status']);
        $this->assertSame('2026-09-15', $this->resolve($contract)->startedAt?->toDateString());
        $this->assertSame(9.0, $this->extract($row->output)->currentEnergyPriceCentsPerKwh);
        foreach (['incomplete', 'unsupported'] as $status) {
            $output = $row->output;
            $output['calculation']['status'] = $status;
            $this->assertNull($this->extract($output));
        }

        // The shared primitive keeps its default and Historical status gate.
        $output = $row->output;
        $phase = $output['pricing']['phases'][1];
        $phase['phase_kind'] = 'current_structured';
        $phase['starts'] = ['kind' => 'contract_start', 'value' => null];
        $phase['components'][0]['price_role'] = 'current';
        $output['pricing']['phases'] = [$phase];
        $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency']);
        $eligibility = new SupplierAdjustedEligibility;
        $context = new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household');
        $this->assertNull($eligibility->candidate('test', $data, $context));
        $this->assertNotNull((new SupplierAdjustedEligibility(currentNormalEvidence: true))->candidate('test', $data, $context));
        $this->assertSame(CalculationStatus::EstimateRequired, $data->calculationStatus);
    }

    public function test_future_fixed_normal_span_is_not_an_ordinary_adjustable_donor(): void
    {
        $output = F::example()[1];
        // Fixed actual promotion is supported when its normal tariff stays adjustable.
        $this->assertNotNull($this->extract($output));
        $future = &$output['pricing']['phases'][1]['components'][0];
        $future['normal_amount'] = 9;
        $future['energy_rule'] = [
            'kind' => 'fixed_price',
            'starts' => ['kind' => 'after_months', 'value' => '3'],
            'ends' => ['kind' => 'after_months', 'value' => '6'],
            'discount_value' => null, 'floor_amount' => null,
            'evidence' => [F::citation('short_description', 'Normal energy is fixed at 9 cents/kWh for months 4 to 6')],
            'normal_basis' => [
                'kind' => 'fixed_price',
                'starts' => ['kind' => 'after_months', 'value' => '3'],
                'ends' => ['kind' => 'after_months', 'value' => '6'],
                'evidence' => [F::citation('short_description', 'Normal energy is fixed at 9 cents/kWh for months 4 to 6')],
            ],
        ];
        unset($future);
        $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);
        $start = CarbonImmutable::parse('2026-09-15', 'Europe/Helsinki');
        $plan = EnergyRulePlan::build($data, MeteringType::General, $start, $start->addMonthsNoOverflow(12), new PhaseTimelineBuilder, null);
        $this->assertNotNull($plan);
        $this->assertSame(9.0, $plan->baseline['energy_general']);
        $this->assertNull($this->extract($output));
    }

    public function test_wrong_tuple_tampered_normal_and_source_mismatch_fail_closed(): void
    {
        foreach (['tuple', 'normal', 'source', 'expired'] as $case) {
            $contract = $this->contract($case);
            [$row, $snapshot] = $this->episode($contract, '2026-09-15', '2026-09-20');
            if ($case === 'tuple') {
                $row->update(['validator_version' => 'validator-v17']);
            } elseif ($case === 'source') {
                $source = $snapshot->source_payload;
                $source['Details']['ShortDescription'] = 'No independently stated normal price.';
                $snapshot->update(['source_payload' => $source]);
            } else {
                $output = $row->output;
                if ($case === 'normal') {
                    $output['pricing']['phases'][0]['components'][0]['normal_amount'] = 15;
                } else {
                    $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['ends'] = ['kind' => 'date', 'value' => '2026-09-14'];
                }
                $row->update(['output' => $output]);
            }
            $this->assertNull($this->resolve($contract)->startedAt, $case);
        }
    }

    public function test_fee_changes_and_trusted_replacement_keep_normal_anchor(): void
    {
        $old = $this->contract('old');
        $new = $this->contract('new');
        $old->update(['is_active' => false, 'replaced_by_contract_id' => $new->id]);
        $this->episode($old, '2026-09-15', '2026-09-17');
        $this->episode($new, '2026-09-18', '2026-09-20', fee: 12);
        $this->assertSame('2026-09-15', $this->resolve($new)->startedAt?->toDateString());
    }

    public function test_different_unknown_and_missing_observations_break_normal_continuity(): void
    {
        foreach (['different', 'unknown', 'gap'] as $case) {
            $contract = $this->contract($case);
            $this->episode($contract, '2026-09-15', '2026-09-16');
            if ($case !== 'gap') {
                [$row] = $this->episode($contract, '2026-09-17', '2026-09-18', normal: 15);
                if ($case === 'unknown') {
                    $row->update(['output' => []]);
                }
            }
            $this->episode($contract, '2026-09-19', '2026-09-20');
            $this->assertSame('2026-09-19', $this->resolve($contract)->startedAt?->toDateString(), $case);
        }
    }

    public function test_legacy_ordinary_can_continue_but_legacy_promotion_cannot(): void
    {
        foreach ([false, true] as $promo) {
            $contract = $this->contract($promo ? 'promo' : 'ordinary');
            [$row] = $this->episode($contract, '2026-09-15', '2026-09-18');
            $output = $row->output;
            foreach ($output['pricing']['phases'] as &$phase) {
                foreach ($phase['components'] as &$component) {
                    unset($component['energy_rule']);
                }
                unset($component);
            }
            unset($phase);
            if (! $promo) {
                $phase = $output['pricing']['phases'][1];
                $phase['starts'] = ['kind' => 'contract_start', 'value' => null];
                $phase['phase_kind'] = 'current_structured';
                $phase['components'][0]['price_role'] = 'current';
                $output['pricing']['phases'] = [$phase];
            } else {
                $output['pricing']['phases'][0]['components'][0]['amount'] = 9;
                $output['pricing']['phases'][0]['components'][0]['normal_amount'] = 15;
                $output['pricing']['phases'][1]['components'][0]['amount'] = 15;
            }
            $row->update(['schema_version' => 'schema-v4', 'prompt_version' => 'prompt-v19', 'validator_version' => 'validator-v17', 'output' => $output]);
            $this->episode($contract, '2026-09-19', '2026-09-20');
            $this->assertSame($promo ? '2026-09-19' : '2026-09-15', $this->resolve($contract)->startedAt?->toDateString());
        }
    }

    public function test_fixed_only_and_unknown_normal_maps_are_not_donors(): void
    {
        $output = F::example()[1];
        $output['pricing']['phases'] = [$output['pricing']['phases'][0]];
        $output['pricing']['phases'][0]['components'][0]['amount'] = 9;
        $output['pricing']['phases'][0]['components'][0]['normal_amount'] = null;
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis'] = null;
        $this->assertNull($this->extract($output));
        $output = F::example()[1];
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['kind'] = 'fixed_price';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['ends'] = ['kind' => 'after_months', 'value' => '3'];
        $this->assertNull($this->extract($output));
    }

    public function test_unknown_rules_resets_spot_and_unknown_billed_charges_are_not_normal_evidence(): void
    {
        $output = F::example()[1];
        $output['pricing']['phases'][0]['components'][0]['energy_rule'] = F::unknown();
        $this->assertNull($this->extract($output));
        $output = F::example()[1];
        $this->assertNull($this->extract($output, model: 'Spot'));
        $output['pricing']['recurring_schedule']['present'] = true;
        $output['pricing']['recurring_schedule']['cadence'] = 'monthly';
        $this->assertNull($this->extract($output));
        $output = F::example()[1];
        $output['pricing']['phases'][0]['components'][] = [
            'component_type' => 'other', 'amount' => 2, 'normal_amount' => null,
            'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'current',
            'source_kind' => 'structured', 'evidence' => [], 'energy_rule' => null,
        ];
        $this->assertNull($this->extract($output));
    }

    public function test_full_time_season_vat_and_hybrid_identity_are_preserved(): void
    {
        foreach (['Time' => ['energy_day', 'energy_night'], 'Season' => ['energy_seasonal_winter', 'energy_seasonal_other']] as $metering => $types) {
            $output = F::example()[1];
            foreach ($output['pricing']['phases'] as &$phase) {
                $base = $phase['components'][0];
                $phase['components'] = [];
                foreach ($types as $type) {
                    $base['component_type'] = $type;
                    $phase['components'][] = $base;
                }
            }
            unset($phase);
            $candidate = $this->extract($output, metering: $metering);
            $this->assertCount(2, $candidate->energyRates);
            $company = $this->extract($output, metering: $metering, target: 'Company');
            $this->assertEqualsWithDelta(9 / 1.255, $company->currentEnergyPriceCentsPerKwh, 0.00001);
            $this->assertFalse($candidate->hasSameEnergySignature($company));
            $output['pricing']['consumption_effect']['present'] = true;
            $output['pricing']['consumption_effect']['applies_to'] = 'base_contract';
            $hybrid = $this->extract($output, metering: $metering, model: 'Hybrid');
            $this->assertSame('Hybrid', $hybrid->pricingMechanism);
            $this->assertFalse($candidate->hasSameEnergySignature($hybrid));
            array_pop($output['pricing']['phases'][0]['components']);
            $this->assertNull($this->extract($output, metering: $metering, model: 'Hybrid'));
        }
    }

    public function test_raw_history_cannot_supply_normal_semantics_and_default_mode_stays_unchanged(): void
    {
        $contract = $this->contract('raw');
        DB::table('contract_price_snapshots')->insert([
            'contract_id' => $contract->id, 'snapshot_date' => '2026-09-20',
            'company_name' => 'Normal Energy', 'contract_name' => 'Normal tariff',
            'pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', 'metering' => 'General',
            'segment_key' => 'fixed', 'pricing_basis' => 'observed_seller_data',
            'energy_price_cents_per_kwh' => 9, 'monthly_fee_eur' => 3,
            'has_discount' => false, 'includes_spot_price' => false,
        ]);
        $this->assertNull($this->resolve($contract)->startedAt);
        $default = (new CurrentPriceEpisodeResolver)->resolve([$contract->id => new SupplierAdjustedCandidate($contract->id, 9, 0)], CarbonImmutable::parse('2026-09-20'));
        $this->assertSame('2026-09-20', $default[$contract->id]->startedAt?->toDateString());
        [$row] = $this->episode($contract, '2026-09-15', '2026-09-18');
        $row->update(['output' => []]);
        $this->assertNull($this->resolve($contract)->startedAt);
    }

    public function test_conflicting_eligible_outputs_do_not_select_a_favourable_normal_map(): void
    {
        $contract = $this->contract('conflict');
        [$row, , $observation] = $this->episode($contract, '2026-09-15', '2026-09-18');
        $conflict = $row->replicate();
        $output = $row->output;
        $output['pricing']['phases'][0]['components'][0]['normal_amount'] = 15;
        $conflict->fill(['analysis_fingerprint' => hash('sha256', 'conflict'), 'status' => 'superseded', 'output' => $output])->save();
        $this->episode($contract, '2026-09-19', '2026-09-20');
        $this->assertSame('2026-09-19', $this->resolve($contract)->startedAt?->toDateString());
        $this->assertNotSame($observation->id, $contract->current_source_observation_id);
    }

    public function test_one_eight_and_thirty_two_candidates_use_four_batch_queries(): void
    {
        $candidates = [];
        for ($i = 0; $i < 32; $i++) {
            $contract = $this->contract('batch-'.$i);
            $this->episode($contract, '2026-09-15', '2026-09-20');
            $candidates[$contract->id] = new SupplierAdjustedCandidate($contract->id, 9, 0, normalTariffEvidence: true);
        }
        foreach ([1, 8, 32] as $count) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $anchors = (new CurrentPriceEpisodeResolver)->resolve(array_slice($candidates, 0, $count, true), CarbonImmutable::parse('2026-09-20'));
            $this->assertCount(4, DB::getQueryLog());
            DB::disableQueryLog();
            foreach ($anchors as $anchor) {
                $this->assertSame('2026-09-15', $anchor->startedAt?->toDateString());
            }
        }
    }

    private function extract(array $output, string $metering = 'General', string $target = 'Household', string $model = 'FixedPrice'): ?SupplierAdjustedCandidate
    {
        $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);

        return (new CurrentNormalCandidateExtractor)->candidate('test', $data, new ContractContext($model, 'OpenEnded', $metering, null, $target), CarbonImmutable::parse('2026-09-15'));
    }

    private function resolve(ElectricityContract $contract, float $normal = 9): mixed
    {
        return (new CurrentPriceEpisodeResolver)->resolve([$contract->id => new SupplierAdjustedCandidate($contract->id, $normal, 0, normalTariffEvidence: true)], CarbonImmutable::parse('2026-09-20'))[$contract->id];
    }

    private function contract(string $id): ElectricityContract
    {
        return ElectricityContract::factory()->forCompany('Normal Energy')->create(['id' => $id, 'name' => 'Normal tariff', 'pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', 'metering' => 'General', 'target_group' => 'Household']);
    }

    private function episode(ElectricityContract $contract, string $first, string $last, float $normal = 9, ?float $fee = null, string $status = 'exact'): array
    {
        [, $output, $source] = F::example(normalAmount: $normal, operand: $normal - 4);
        $output['contract_id'] = $contract->id;
        $output['calculation']['status'] = $status;
        if ($fee !== null) {
            $source['Details']['Pricing']['PriceComponents'][] = ['Id' => 'fee', 'PriceComponentType' => 'Monthly', 'HasDiscount' => false, 'OriginalPayment' => ['Price' => $fee, 'PaymentUnit' => 'EuroPerMonth']];
            foreach ($output['pricing']['phases'] as &$phase) {
                $phase['components'][] = ['component_type' => 'monthly_fee', 'amount' => $fee, 'normal_amount' => null, 'unit' => 'eur_per_month', 'vat_status' => 'included', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [F::citation('components[1].price', $fee)], 'energy_rule' => null];
            }
            unset($phase);
        }
        $snapshot = ContractSourceSnapshot::create(['contract_id' => $contract->id, 'source_fingerprint' => hash('sha256', $contract->id.$first), 'source_payload' => $source, 'first_observed_at' => $first.' 06:00:00', 'last_observed_at' => $last.' 06:00:00']);
        $input = (new ContractInterpretationInputBuilder)->build($snapshot, $first, F::profile());
        $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
        $observation = ContractSourceObservation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id, 'first_observed_at' => $first.' 06:00:00', 'last_observed_at' => $last.' 06:00:00']);
        $row = ContractInterpretation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id, 'analysis_fingerprint' => hash('sha256', 'analysis'.$contract->id.$first), 'status' => 'published', 'schema_version' => 'schema-v5', 'prompt_version' => 'prompt-v20', 'validator_version' => 'validator-v18', 'provider' => 'test', 'model' => 'test', 'output' => $output, 'validation_errors' => [], 'completed_at' => $first.' 07:00:00', 'published_at' => $first.' 07:00:00']);
        $contract->update(['current_source_observation_id' => $observation->id, 'published_interpretation_id' => $row->id]);

        return [$row, $snapshot, $observation];
    }
}
