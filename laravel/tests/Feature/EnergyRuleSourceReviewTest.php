<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CurrentSourcePromotionEvidence;
use App\Services\ContractInterpretation\ContractAnalysisFingerprint;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;

class EnergyRuleSourceReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-15 10:00:00');
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_unsafe_name_claims_cannot_write_publication_rows(): void
    {
        [$contract, $snapshot, $analysis] = $this->fixture();
        $source = $snapshot->source_payload;
        foreach (['Name', 'Details.Pricing.Name'] as $field) {
            foreach (['General energy price is fixed at 6 cents/kWh for the first 3 months', 'No price guarantee', 'Hinta voi muuttua', 'Vain uusille asiakkaille'] as $claim) {
                $changed = $source;
                data_set($changed, $field, $claim);
                $snapshot->update(['source_payload' => $changed]);
                $before = $this->rows();
                $this->assertFalse(app(ContractInterpretationPublisher::class)->publish($analysis), $field.': '.$claim);
                $this->assertSame($before, $this->rows());
            }
        }
        $snapshot->update(['source_payload' => $source]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        Http::assertNothingSent();
    }

    public function test_cross_day_a_b_a_reuses_output_and_current_batch_proof_without_model_work(): void
    {
        [$contract, $snapshot, $analysis] = $this->fixture();
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        $original = $analysis->fresh()->output;
        $this->travelTo('2026-09-16 10:00:00');
        $other = $snapshot->replicate();
        $other->source_fingerprint = str_repeat('b', 64);
        $source = $other->source_payload;
        $source['Name'] = 'Tuuli';
        $other->source_payload = $source;
        $other->save();
        $this->point($contract, $other, '2026-09-16');
        $this->travelTo('2026-09-17 10:00:00');
        $next = $this->point($contract, $snapshot, '2026-09-17');
        $analysis->update(['status' => 'superseded']);
        $this->selectV5();
        config()->set('services.openrouter.api_key', null);
        $selected = app(ContractInterpretationDispatcher::class)->dispatch($next, true);
        $this->assertSame($analysis->id, $selected->id);
        $this->assertSame('published', $selected->status);
        $this->assertSame($original, $selected->output);
        $this->assertDatabaseCount('contract_interpretations', 1);
        $contract->refresh();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $proof = (new CurrentSourcePromotionEvidence)->forContracts(collect([$contract]), CarbonImmutable::parse('2026-09-17', 'Europe/Helsinki'));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($proof[$contract->id]['energy_rules_valid']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_expired_absolute_discount_still_needs_dated_fallback(): void
    {
        [$contract, $snapshot, $analysis] = $this->fixture();
        $source = $snapshot->source_payload;
        $source['Details']['ShortDescription'] = str_replace('for the first 3 months', 'from 2026-09-15 through 2026-09-16', $source['Details']['ShortDescription']);
        $discount = &$source['Details']['Pricing']['PriceComponents'][0]['Discount'];
        $discount['DiscountType'] = 'UntilDate';
        $discount['UntilDate'] = '2026-09-16';
        $snapshot->update(['source_payload' => $source]);
        $output = $analysis->output;
        $first = &$output['pricing']['phases'][0];
        $first['starts'] = $first['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-09-15'];
        $first['ends'] = $first['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-09-16'];
        $first['components'][0]['energy_rule']['evidence'][2]['quote'] = explode(';', $source['Details']['ShortDescription'])[0];
        foreach ($first['components'][0]['evidence'] as &$citation) {
            if ($citation['source'] === 'components[0].discount_type') {
                $citation['quote'] = 'UntilDate';
            } elseif ($citation['source'] === 'components[0].discount_n_first_months') {
                $citation = F::citation('components[0].discount_until_date', '2026-09-16');
            }
        }
        unset($citation);
        $output['pricing']['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-09-17'];
        $analysis->update(['output' => $output]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        $this->travelTo('2026-09-17 10:00:00');
        $next = $this->point($contract, $snapshot, '2026-09-17');
        $analysis->update(['status' => 'superseded']);
        $this->selectV5();
        config()->set('services.openrouter.api_key', 'test-key');
        $selected = app(ContractInterpretationDispatcher::class)->dispatch($next, true);
        $this->assertNotSame($analysis->id, $selected->id);
        $this->assertSame($next->id, $selected->analysis_source_observation_id);
        $this->assertSame($output, $analysis->fresh()->output);
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 1);
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        [, $output, $source] = F::example('absolute_discount');
        Company::create(['name' => 'Energy test company', 'name_slug' => 'energy-test-company']);
        $contract = ElectricityContract::factory()->forCompany('Energy test company')->create(['id' => 'energy-test', 'name' => 'Energy test', 'pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', 'metering' => 'General', 'target_group' => 'Household']);
        $snapshot = ContractSourceSnapshot::create(['contract_id' => $contract->id, 'source_fingerprint' => str_repeat('a', 64), 'source_payload' => $source, 'first_observed_at' => '2026-09-15', 'last_observed_at' => '2026-09-15']);
        $this->point($contract, $snapshot, '2026-09-15');
        $analysis = ContractInterpretation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => (new ContractAnalysisFingerprint)->forSnapshot($snapshot, F::profile()), 'status' => 'pending', 'completed_at' => now(),
            'schema_version' => 'schema-v5', 'prompt_version' => 'prompt-v20', 'validator_version' => 'validator-v18', 'provider' => config('contract_interpretation.provider'), 'model' => config('contract_interpretation.model'), 'output' => $output, 'validation_errors' => []]);

        return [$contract, $snapshot, $analysis];
    }

    private function point(ElectricityContract $contract, ContractSourceSnapshot $snapshot, string $date): ContractSourceObservation
    {
        $observation = ContractSourceObservation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id, 'first_observed_at' => $date, 'last_observed_at' => $date]);
        $contract->update(['current_source_observation_id' => $observation->id, 'published_interpretation_id' => null]);

        return $observation;
    }

    private function selectV5(): void
    {
        $profile = F::profile();
        config()->set(['contract_interpretation.enabled' => true, 'contract_interpretation.schema_version' => $profile->schemaVersion, 'contract_interpretation.prompt_version' => $profile->promptVersion, 'contract_interpretation.validator_version' => $profile->validatorVersion, 'contract_interpretation.schema_path' => $profile->schemaPath, 'contract_interpretation.prompt_path' => $profile->promptPath]);
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['electricity_contracts', 'contract_interpretations', 'price_components', 'active_contracts'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }
}
