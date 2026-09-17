<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CurrentSourcePromotionEvidence;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture as F;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentPromotionTermsTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPAIGN = 'Kampanjahinta 14,90 snt/kWh + perusmaksu 0 € ensimmäisen kuukauden ajan, tämän jälkeenkin vain 4,90€/kk.';

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'Terms Oy', 'name_slug' => 'terms-oy']);
        config()->set('canonical_pricing.enabled', true);
        $this->app->forgetScopedInstances();
    }

    public function test_source_energy_campaign_excludes_but_superlative_margin_with_complete_fee_offer_remains(): void
    {
        $hehku = $this->contract('campaign', self::CAMPAIGN);
        $cheap = $this->contract('margin', 'SUPERDIILI! CHEAP PÖRSSISÄHKÖN MARGINAALI VAIN 0,32 SNT/KWH. Neljän kuukauden perusmaksut 0 €/kk.', spot: true);
        $service = app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(6, 4));
        $date = CarbonImmutable::parse('2026-09-15', 'Europe/Helsinki');
        $usage = new EnergyUsage(total: 5000, basicLiving: 5000);
        $metrics = $service->metricsForContracts(collect([$hehku, $cheap]), $usage, $date);
        $this->assertFalse($metrics[$hehku->id]->isListed());
        $this->assertTrue($metrics[$cheap->id]->isListed());
        $this->assertSame(['insufficient_promotion_terms'], $metrics[$hehku->id]->pricing()->toArray()['assumptions']);
        $single = $service->evaluate($hehku, $usage, startDate: $date)['outcome'];
        $this->assertSame(['insufficient_promotion_terms'], $single->assumptions);
        $stats = $service->outcomesForContractsAtConsumptions(collect([$hehku, $cheap]), [2000, 5000, 18000], new SpotAssumptions(6, 4), $date);
        foreach ($stats[$hehku->id] as $outcome) {
            $this->assertNull($outcome->totalCost);
            $this->assertSame(['insufficient_promotion_terms'], $outcome->assumptions);
        }
        $period = $service->periodEvaluationsForContracts(collect([$hehku]), new CanonicalPeriodPricingRequest($date, $date->addDays(20), 200, 5000, []));
        $this->assertNull($period[$hehku->id]['period']->periodTotal);
        $this->assertSame('insufficient_promotion_terms', $period[$hehku->id]['period']->unavailableReason->value);
        $this->postJson('/api/calculate-price', ['contract_id' => $hehku->id, 'energy_usage' => ['total' => 5000, 'basic_living' => 3000, 'room_heating' => 2000]])
            ->assertOk()->assertJsonPath('data.total_cost', null)
            ->assertJsonPath('data.assumptions', ['insufficient_promotion_terms']);
        $this->getJson('/api/contracts/'.$hehku->id.'?consumption=5000')->assertOk()
            ->assertJsonPath('data.current_pricing.availability', 'unavailable')
            ->assertJsonPath('data.calculated_cost.assumptions', ['insufficient_promotion_terms']);
    }

    public function test_explicit_campaign_margin_requires_timing_and_normal_margin_despite_a_complete_fee_offer(): void
    {
        $steady = $this->contract('campaign-margin-steady', 'Marginaalin kampanjahinta 0,32 snt/kWh.', spot: true);
        $unknownDuration = $this->contract('campaign-margin-duration', 'Kampanjamarginaali 0,32 snt/kWh.', spot: true);
        $pricing = $unknownDuration->canonical_pricing;
        foreach ($pricing['phases'] as &$phase) {
            $phase['components'][0]['normal_amount'] = 0.5;
        }
        unset($phase);
        $unknownDuration->update(['canonical_pricing' => $pricing]);

        $unknownNormal = $this->contract('campaign-margin-normal', 'Marginaalin kampanjahinta 0,32 snt/kWh ensimmäiset neljä kuukautta.', spot: true);
        $pricing = $unknownNormal->canonical_pricing;
        $pricing['phases'][1]['components'][0]['amount'] = null;
        $unknownNormal->update(['canonical_pricing' => $pricing]);

        $complete = $this->contract('campaign-margin-complete', 'Kampanjamarginaali 0,32 snt/kWh ensimmäiset neljä kuukautta, sitten 0,50 snt/kWh.', spot: true);
        $pricing = $complete->canonical_pricing;
        $pricing['phases'][0]['components'][0]['normal_amount'] = 0.5;
        $pricing['phases'][1]['components'][0]['amount'] = 0.5;
        $complete->update(['canonical_pricing' => $pricing]);

        $contracts = collect([$steady, $unknownDuration, $unknownNormal, $complete]);
        $evidence = (new CurrentSourcePromotionEvidence)->forContracts($contracts);
        foreach ($evidence as $record) {
            $this->assertTrue($record['valid']);
            $this->assertSame([0.32], $record['rates']);
        }
        $service = app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(6, 4));
        $date = CarbonImmutable::parse('2026-09-15', 'Europe/Helsinki');
        $usage = new EnergyUsage(total: 5000, basicLiving: 5000);
        $metrics = $service->metricsForContracts($contracts, $usage, $date);
        $periods = $service->periodEvaluationsForContracts($contracts, new CanonicalPeriodPricingRequest($date, $date->addDays(20), 200, 5000, []));
        foreach ([$steady, $unknownDuration, $unknownNormal] as $contract) {
            $this->assertFalse($metrics[$contract->id]->isListed());
            $this->assertSame(['insufficient_promotion_terms'], $metrics[$contract->id]->pricing()->toArray()['assumptions']);
            $this->assertNull($periods[$contract->id]['period']->periodTotal);
            $this->assertSame('insufficient_promotion_terms', $periods[$contract->id]['period']->unavailableReason->value);
        }
        $this->assertTrue($metrics[$complete->id]->isListed());
    }

    public function test_dated_expired_campaign_is_not_a_new_non_reset_promotion_but_a_new_introduction_is_checked(): void
    {
        $oldText = 'Kampanjahinta 14,90 snt/kWh 1.3.2026–30.4.2026. Normaali energiahinta 1.5.2026 alkaen 14,90 snt/kWh.';
        $expired = $this->contract('expired-non-reset', $oldText);
        $pricing = $expired->canonical_pricing;
        $pricing['phases'][0]['starts'] = ['kind' => 'date', 'value' => '2026-03-01'];
        $pricing['phases'][0]['ends'] = ['kind' => 'date', 'value' => '2026-04-30'];
        $pricing['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-05-01'];
        $expired->update(['canonical_pricing' => $pricing]);
        $this->assertFalse($pricing['recurring_schedule']['present']);

        $active = $this->contract('new-campaign-same-rate', $oldText.' Uusi kampanjahinta 14,90 snt/kWh syyskuussa 2026.');
        $pricing['phases'][1]['ends'] = ['kind' => 'date', 'value' => '2026-08-31'];
        // The fee offer is complete. Only the new source-scoped energy introduction
        // lacks a normal continuation; the old equal-valued campaign cannot excuse it.
        $pricing['phases'][] = F::phase('New introduction', PhaseKind::Introductory,
            F::boundary(BoundaryKind::Date, '2026-09-01'), F::boundary(BoundaryKind::Date, '2026-09-30'), [
                F::component(ComponentType::EnergyGeneral, 14.9, ComponentUnit::CentsPerKwh),
                F::component(ComponentType::MonthlyFee, 0, ComponentUnit::EurPerMonth, PriceRole::Introductory, 4.9),
            ]);
        $active->update(['canonical_pricing' => $pricing]);
        $service = app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(6, 4));
        $date = CarbonImmutable::parse('2026-09-15', 'Europe/Helsinki');
        $usage = new EnergyUsage(total: 5000, basicLiving: 5000);
        $metrics = $service->metricsForContracts(collect([$expired, $active]), $usage, $date);
        $this->assertTrue($metrics[$expired->id]->isListed());
        $this->assertFalse($metrics[$active->id]->isListed());
        $this->assertSame(['insufficient_promotion_terms'], $metrics[$active->id]->pricing()->toArray()['assumptions']);
        $periods = $service->periodEvaluationsForContracts(collect([$expired, $active]), new CanonicalPeriodPricingRequest($date, $date->addDays(10), 200, 5000, []));
        $this->assertNotNull($periods[$expired->id]['period']->periodTotal);
        $this->assertSame('insufficient_promotion_terms', $periods[$active->id]['period']->unavailableReason->value);
    }

    public function test_complete_energy_campaign_and_expired_campaign_do_not_exclude_current_prices(): void
    {
        $complete = $this->contract('complete', self::CAMPAIGN);
        $attributes = $complete->canonical_pricing;
        $attributes['phases'][0]['components'][0]['normal_amount'] = 16;
        $attributes['phases'][1]['components'][0]['amount'] = 16;
        $complete->update(['canonical_pricing' => $attributes]);
        $expired = $this->contract('expired', self::CAMPAIGN);
        $attributes = $expired->canonical_pricing;
        $attributes['phases'][0]['starts'] = ['kind' => 'date', 'value' => '2026-03-01'];
        $attributes['phases'][0]['ends'] = ['kind' => 'date', 'value' => '2026-04-30'];
        $attributes['phases'][1]['starts'] = ['kind' => 'date', 'value' => '2026-05-01'];
        $attributes['phases'][1]['components'][0]['amount'] = 9;
        $attributes['recurring_schedule'] = F::recurringSchedule('monthly', '2026-09-01', '2026-09-30', false);
        $expired->update(['canonical_pricing' => $attributes]);
        $generic = $this->contract('generic', 'SUPERDIILI! Tarjous, vain 14,90 snt/kWh.');
        $service = app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(6, 4));
        $metrics = $service->metricsForContracts(collect([$complete, $expired, $generic]), new EnergyUsage(total: 5000, basicLiving: 5000), CarbonImmutable::parse('2026-09-15'));
        foreach ($metrics as $metric) {
            $this->assertTrue($metric->isListed());
        }
        // A later ordinary reset can happen to have the same rate as the expired campaign.
        $attributes['phases'][1]['components'][0]['amount'] = 14.9;
        $expired->update(['canonical_pricing' => $attributes]);
        $this->assertTrue($service->evaluate($expired, new EnergyUsage(total: 5000, basicLiving: 5000), startDate: CarbonImmutable::parse('2026-09-15'))['outcome']->isListed());
    }

    public function test_source_assessment_is_one_batch_and_never_uses_newest_unpointed_snapshot(): void
    {
        $contracts = collect();
        for ($i = 0; $i < 8; $i++) {
            $contract = $this->contract('batch-'.$i, 'Vain 14,90 snt/kWh.');
            ContractSourceSnapshot::create([
                'contract_id' => $contract->id, 'source_fingerprint' => hash('sha256', 'new-'.$i),
                'source_payload' => ['Details' => ['LongDescription' => self::CAMPAIGN]],
                'first_observed_at' => '2026-09-16', 'last_observed_at' => '2026-09-16',
            ]);
            $contracts->push($contract);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $evidence = (new CurrentSourcePromotionEvidence)->forContracts($contracts);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('price_components', strtolower($queries[0]['query']));
        foreach ($evidence as $record) {
            $this->assertTrue($record['valid']);
            $this->assertSame([], $record['rates']);
        }
    }

    public function test_mismatched_or_invalid_publication_cannot_supply_a_current_price(): void
    {
        $mismatch = $this->contract('mismatch', 'Vain 14,90 snt/kWh.');
        $invalid = $this->contract('invalid', 'Vain 14,90 snt/kWh.');
        $other = $this->contract('other', 'Vain 14,90 snt/kWh.');
        $mismatch->update(['current_source_observation_id' => $other->current_source_observation_id]);
        ContractInterpretation::whereKey($invalid->published_interpretation_id)->update(['validation_errors' => ['invalid']]);
        $service = app(CanonicalContractPricingService::class)->withSpotAssumptions(new SpotAssumptions(6, 4));
        $metrics = $service->metricsForContracts(collect([$mismatch, $invalid]), new EnergyUsage(total: 5000, basicLiving: 5000));
        foreach ($metrics as $metric) {
            $this->assertFalse($metric->isListed());
            $this->assertNull($metric->sortKey());
        }
    }

    private function contract(string $id, string $text, bool $spot = false): ElectricityContract
    {
        $attributes = $spot ? F::spotAttributes() : F::fixedAttributes();
        $component = F::component($spot ? ComponentType::SpotMargin : ComponentType::EnergyGeneral, $spot ? 0.32 : 14.9, ComponentUnit::CentsPerKwh);
        $months = $spot ? '4' : '1';
        $fee = $spot ? 4.45 : 4.9;
        $attributes['canonical_pricing']['phases'] = [
            F::phase('Fee offer', PhaseKind::Introductory, F::boundary(BoundaryKind::ContractStart), F::boundary(BoundaryKind::AfterMonths, $months), [
                $component, F::component(ComponentType::MonthlyFee, 0, ComponentUnit::EurPerMonth, PriceRole::Introductory, $fee),
            ]),
            F::phase('Normal', PhaseKind::Normal, F::boundary(BoundaryKind::AfterMonths, $months), F::boundary(BoundaryKind::None), [
                $component, F::component(ComponentType::MonthlyFee, $fee, ComponentUnit::EurPerMonth),
            ]),
        ];
        $contract = ElectricityContract::factory()->forCompany('Terms Oy')->create([
            'id' => $id, 'pricing_model' => $spot ? 'Spot' : 'FixedPrice', 'contract_type' => 'OpenEnded', ...$attributes,
        ]);
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $id, 'source_fingerprint' => hash('sha256', $id),
            'source_payload' => ['Name' => $id, 'Details' => ['LongDescription' => $text]],
            'first_observed_at' => '2026-09-01', 'last_observed_at' => '2026-09-15',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $id, 'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => '2026-09-01', 'last_observed_at' => '2026-09-15',
        ]);
        $interpretation = ContractInterpretation::create([
            'contract_id' => $id, 'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => hash('sha256', 'interpretation-'.$id), 'status' => 'published',
            'schema_version' => 4, 'prompt_version' => 'test', 'validator_version' => 'test', 'provider' => 'test', 'model' => 'test',
            'output' => [], 'validation_errors' => [], 'published_fields' => [], 'relational_pricing_published' => false,
        ]);
        $contract->update(['current_source_observation_id' => $observation->id, 'published_interpretation_id' => $interpretation->id]);

        return $contract;
    }
}
