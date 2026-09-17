<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\EnergyRuleSourceProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\EnergyRulesFixture as F;
use Tests\Support\FullSourceEnergyFixture as Full;
use Tests\TestCase;

class EnergyRuleFullSourceLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_cheap_and_unconditional_term_sources_validate_and_publish(): void
    {
        Http::preventStrayRequests();
        foreach ([Full::cheap(), Full::wholeTerm()] as [$input, $output, $source]) {
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
            $this->travelTo($input['analysis_date'].' 12:00:00');
            Company::firstOrCreate(['name' => $input['company_name']], ['name_slug' => 'source-'.count(Company::all())]);
            $contract = ElectricityContract::factory()->forCompany($input['company_name'])->create(['id' => $input['contract_id']]);
            $snapshot = ContractSourceSnapshot::create(['contract_id' => $contract->id, 'source_fingerprint' => hash('sha256', $contract->id), 'source_payload' => $source, 'first_observed_at' => now(), 'last_observed_at' => now()]);
            $observation = ContractSourceObservation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id, 'first_observed_at' => now(), 'last_observed_at' => now()]);
            $contract->update(['current_source_observation_id' => $observation->id]);
            $analysis = ContractInterpretation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id, 'analysis_fingerprint' => hash('sha256', $contract->id), 'schema_version' => 'schema-v5', 'prompt_version' => 'prompt-v20', 'validator_version' => 'validator-v18', 'provider' => 'test', 'model' => 'test', 'status' => 'pending', 'output' => $output, 'validation_errors' => [], 'completed_at' => now()]);
            $changed = $source;
            $changed['Details']['Pricing']['PriceComponents'][0]['OriginalPayment']['Price'] += 1;
            $snapshot->update(['source_payload' => $changed]);
            $this->assertFalse(app(ContractInterpretationPublisher::class)->publish($analysis));
            $this->assertNull($contract->fresh()->published_interpretation_id);
            $snapshot->update(['source_payload' => $source]);
            $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
            $this->assertSame('fixed_price', $contract->fresh()->canonical_pricing['phases'][0]['components'][0]['energy_rule']['kind']);
        }
        Http::assertNothingSent();
    }

    public function test_qualified_real_sources_stay_unknown_without_source_edits(): void
    {
        $method = new \ReflectionMethod(EnergyRuleSourceProof::class, 'sourceClauses');
        foreach (['oomi', 'voima', 'iin', 'tyyni', 'hehku'] as $name) {
            [$input] = Full::real($name);
            $this->assertNull($method->invoke(new EnergyRuleSourceProof, $input), $name);
            $index = array_search('General', array_column($input['components'], 'price_component_type'), true);
            $component = ['component_type' => 'energy_general', 'unit' => 'cents_per_kwh', 'amount' => $input['components'][$index]['price'], 'energy_rule' => F::unknown()];
            $output = ['pricing' => ['phases' => [['components' => [$component]]]]];
            $this->assertSame([], (new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        }
    }

    public function test_adversarial_combined_and_linked_sources_cannot_prove_energy(): void
    {
        foreach ([
            ['contract_name', 'Other product'],
            ['metering', 'Time'],
            ['extra_information_fi', '7,49', '7,59'],
            ['extra_information_fi', 'on kiinteä energiahinta', 'ei ole kiinteä energiahinta'],
            ['extra_information_fi', 'on kiinteä energiahinta', 'on energiahinta'],
            ['extra_information_fi', '30.9.2026', '31.9.2026'],
            ['extra_information_fi', 'Hinta on lukittu', 'Hinta ei ole lukittu'],
            ['extra_information_fi', 'Cheap Kvartaalisähkö -sopimuksessa', 'Other product -sopimuksessa'],
        ] as $mutation) {
            [$input, $output] = Full::cheap();
            $input[$mutation[0]] = count($mutation) === 2 ? $mutation[1] : str_replace($mutation[1], $mutation[2], $input[$mutation[0]]);
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator), json_encode($mutation));
        }
        [$input, $output] = Full::cheap();
        $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
        $rule['normal_basis'] = ['kind' => 'fixed_price', 'starts' => ['kind' => 'date', 'value' => $input['analysis_date']], 'ends' => ['kind' => 'date', 'value' => '2026-09-30'], 'evidence' => [...F::scope(), F::citation('extra_information_fi', 'Hinta on lukittu 30.9.2026 asti.')]];
        $output['pricing']['phases'][0]['components'][0]['normal_amount'] = 9.95;
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_linked_lock_requires_both_clauses_and_explicit_start_not_observation_date(): void
    {
        [$input, $output] = Full::wholeTerm();
        $price = 'Energiahinta on 9 snt/kWh 15.9.2026 alkaen.';
        $lock = 'Hinta on lukittu 30.9.2026 asti.';
        $input['short_description'] = $price.' '.$lock;
        $phase = &$output['pricing']['phases'][0];
        $rule = &$phase['components'][0]['energy_rule'];
        $phase['starts'] = $rule['starts'] = ['kind' => 'date', 'value' => '2026-09-15'];
        $phase['ends'] = $rule['ends'] = ['kind' => 'date', 'value' => '2026-09-30'];
        $rule['evidence'] = [...F::scope(), F::citation('short_description', $price), F::citation('short_description', $lock)];
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $input['analysis_date'] = '2026-09-17';
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        array_pop($rule['evidence']);
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
        $rule['evidence'][] = F::citation('short_description', $lock);
        $input['short_description'] = str_replace(' 15.9.2026 alkaen', '', $input['short_description']);
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
    }

    public function test_current_structured_normal_requires_its_own_adjustable_proof_and_exact_identity(): void
    {
        [$input, $output] = Full::wholeTerm();
        $input['extra_information_default'] = 'Normaali energiahinta on muuttuva.';
        $component = &$output['pricing']['phases'][0]['components'][0];
        $component['normal_amount'] = 9;
        $component['energy_rule']['normal_basis'] = ['kind' => 'adjustable_tariff', 'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null], 'evidence' => [...F::scope(), F::citation('extra_information_default', $input['extra_information_default']), F::citation('components[0].price', 9), F::citation('components[0].has_discount', false)]];
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        array_pop($component['energy_rule']['normal_basis']['evidence']);
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
        $component['energy_rule']['normal_basis']['evidence'][] = F::citation('components[0].has_discount', false);
        $input['components'][0]['has_discount'] = true;
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
    }

    public function test_product_prefix_is_generic_and_must_match_the_input_name(): void
    {
        [$input, $output] = Full::cheap();
        foreach (['contract_name', 'pricing_name', 'extra_information_fi'] as $field) {
            $input[$field] = str_replace('Cheap Kvartaalisähkö', 'Tuuli', $input[$field]);
        }
        $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
        $rule['evidence'][2]['quote'] = str_replace('Cheap Kvartaalisähkö', 'Tuuli', $rule['evidence'][2]['quote']);
        $this->assertSame([], (new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        $input['contract_name'] = 'Tuuli No price guarantee';
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_term_needs_guarantee_exact_amount_duration_and_discount_identity(): void
    {
        foreach (['amount', 'duration', 'discount', 'citation', 'name_only', 'qualification', 'negation'] as $change) {
            [$input, $output] = Full::wholeTerm();
            $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
            match ($change) {
                'amount' => $input['components'][0]['price'] = 10,
                'duration' => $input['fixed_time_range'] = 'Fixed12',
                'discount' => $input['components'][0]['has_discount'] = true,
                'citation' => array_pop($rule['evidence']),
                'name_only' => $input['short_description'] = null,
                'qualification' => $input['long_description'] .= ' Vain alle 100 000 kWh vuodessa.',
                'negation' => $input['short_description'] = 'Hinta ei pysy samana koko sopimuskauden ajan.',
            };
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator), $change);
        }
    }
}
