<?php

namespace Tests\Feature;

use App\Models\ElectricityContract;
use App\Services\ContractCard\ContractCardPresenter;
use App\Services\ContractCard\Enums\PricingBucket;
use App\Services\ContractCard\Enums\PricingCategory;
use App\Services\ContractCard\PricingCategoryResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract card's server-side derivation.
 *
 * These rules are user-visible promises: which of the three pricing categories a contract
 * is in, whether its annual price is an estimate and why, and which caveats a card is
 * allowed to shout. They were previously spread across two Blade files that drifted apart,
 * so pin each rule here rather than trusting a rendered-HTML assertion to catch a change.
 */
class ContractCardPresenterTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-07-26';

    private function present(ElectricityContract $contract, array $prices = [], bool $billMode = false): \App\Services\ContractCard\DTO\ContractCardView
    {
        return app(ContractCardPresenter::class)->present(
            contract: $contract,
            prices: $prices,
            billMode: $billMode,
            today: CarbonImmutable::parse(self::TODAY, 'Europe/Helsinki'),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $cost
     * @param array<string, mixed>|null $integrity
     */
    private function contract(array $attributes = [], array $cost = [], ?array $integrity = null): ElectricityContract
    {
        $contract = new ElectricityContract(array_merge([
            'id' => 'test-'.uniqid(),
            'company_name' => 'Testi Energia Oy',
            'name' => 'Testisopimus',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'canonical_pricing' => $this->canonicalPricing(),
        ], $attributes));

        $contract->calculated_cost = array_merge([
            'total_cost' => 600.0,
            'monthly_fixed_fee' => 4.0,
            'general_kwh_price' => 7.2,
            'is_spot_contract' => false,
            'is_estimate' => false,
            'estimate_method' => 'none',
        ], $cost);
        $contract->pricing_integrity = $integrity;
        $contract->exceeds_consumption_limit = $attributes['exceeds_consumption_limit'] ?? false;

        return $contract;
    }

    /**
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $effect
     */
    private function canonicalPricing(array $schedule = [], array $effect = []): array
    {
        return [
            'phases' => [],
            'recurring_schedule' => array_merge([
                'present' => false, 'cadence' => 'none', 'current_period_start' => null,
                'current_period_end' => null, 'future_price_known' => null, 'description' => null, 'evidence' => [],
            ], $schedule),
            'consumption_effect' => array_merge([
                'present' => false, 'applies_to' => 'unknown', 'cadence' => 'none',
                'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null,
                'typical_max_cents_per_kwh' => null, 'hard_min_cents_per_kwh' => null,
                'hard_max_cents_per_kwh' => null, 'uncapped' => null, 'description' => null, 'evidence' => [],
            ], $effect),
        ];
    }

    // ---------------------------------------------------------------- categories

    public function test_spot_contract_is_the_market_category(): void
    {
        $card = $this->present($this->contract(['pricing_model' => 'Spot']));

        $this->assertSame(PricingCategory::Market, $card->category);
        $this->assertSame('Hinta seuraa pörssin tuntihintaa', $card->band->headline);
        $this->assertSame('Muuttuu joka tunti', $card->band->detail);
    }

    public function test_quarterly_reset_is_the_market_category_with_the_next_reset_date(): void
    {
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing([
                'present' => true, 'cadence' => 'quarterly', 'current_period_end' => '2026-09-30',
            ]),
        ]));

        $this->assertSame(PricingCategory::Market, $card->category);
        $this->assertSame('Hinta tarkistetaan neljännesvuosittain', $card->band->headline);
        // current_period_end is inclusive, so the next period starts the day after.
        $this->assertSame('Seuraava tarkistus 1.10.2026', $card->band->detail);
    }

    public function test_reset_band_omits_the_detail_when_the_period_end_is_not_disclosed(): void
    {
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'monthly']),
        ]));

        $this->assertSame('Hinta tarkistetaan kuukausittain', $card->band->headline);
        $this->assertNull($card->band->detail);
    }

    public function test_a_stale_period_end_in_the_past_is_not_shown_as_the_next_reset(): void
    {
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing([
                'present' => true, 'cadence' => 'quarterly', 'current_period_end' => '2026-03-31',
            ]),
        ]));

        $this->assertNull($card->band->detail);
    }

    public function test_unknown_cadence_is_not_evidence_of_a_reset_mechanism(): void
    {
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'unknown']),
        ]));

        $this->assertSame(PricingCategory::Fixed, $card->category);
    }

    public function test_disclosed_base_contract_effect_is_the_consumption_effect_category(): void
    {
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'base_contract']),
        ]));

        $this->assertSame(PricingCategory::ConsumptionEffect, $card->category);
        $this->assertSame('Kiinteä hinta + kulutusvaikutus', $card->band->headline);
        // The band detail deliberately repeats the popover phrasing in hybridBody(), so the
        // band and the Arvio explanation describe the effect the same way.
        $this->assertSame('Vaikutus riippuu siitä, mihin aikaan käytät sähköä', $card->band->detail);
    }

    public function test_hybrid_without_a_consumption_effect_block_still_falls_into_the_effect_category(): void
    {
        // One active contract (Helen Välkkysähkö Yritys) is Hybrid with no effect block at all.
        $card = $this->present($this->contract(['pricing_model' => 'Hybrid']));

        $this->assertSame(PricingCategory::ConsumptionEffect, $card->category);
    }

    public function test_an_effect_that_only_applies_to_optional_fixing_is_not_the_effect_category(): void
    {
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'optional_fixing']),
        ]));

        $this->assertSame(PricingCategory::Fixed, $card->category);
    }

    public function test_market_wins_when_a_contract_is_both_reset_and_consumption_effect(): void
    {
        // Korpela Kvartaali / Vaasan Sähkö Vaikuttaja. The effect category means "fixed
        // otherwise", and a quarterly-reset base is not fixed.
        $contract = $this->contract([
            'pricing_model' => 'Hybrid',
            'canonical_pricing' => $this->canonicalPricing(
                ['present' => true, 'cadence' => 'quarterly', 'current_period_end' => '2026-09-30'],
                ['present' => true, 'applies_to' => 'base_contract'],
            ),
        ]);
        $contract->comparability = 'base_only_hybrid';

        $card = $this->present($contract);

        $this->assertSame(PricingCategory::Market, $card->category);
        // The effect is not lost: it becomes a footer caveat instead of the category.
        $this->assertContains('Ei sisällä kulutusvaikutusta', array_map(fn ($w) => $w->text, $card->warnings));
    }

    public function test_plain_fixed_contract_states_its_duration(): void
    {
        $openEnded = $this->present($this->contract());
        $this->assertSame(PricingCategory::Fixed, $openEnded->category);
        $this->assertSame('Energian hinta ei muutu', $openEnded->band->headline);
        $this->assertSame('Voimassa toistaiseksi', $openEnded->band->detail);

        $fixedTerm = $this->present($this->contract(['contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed24']));
        $this->assertSame('Määräaikainen 24 kk', $fixedTerm->band->detail);
    }

    public function test_the_category_does_not_depend_on_the_canonical_pricing_flag(): void
    {
        config()->set('canonical_pricing.enabled', false);

        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'quarterly']),
        ]));

        $this->assertSame(PricingCategory::Market, $card->category);
    }

    // ------------------------------------------------------- the band is single purpose

    public function test_a_pre_published_price_increase_keeps_a_fixed_band_and_warns_in_the_footer(): void
    {
        $card = $this->present($this->contract(
            cost: ['general_kwh_price' => 5.49],
            integrity: [
                'card_label' => 'Hinta nousee 1.8.2026',
                'change_date' => '2026-08-01',
                'promo_rate_cents' => 5.49,
                'normal_rate_cents' => 13.65,
            ],
        ));

        // Both prices are published in advance, so the pricing TYPE is still fixed.
        $this->assertSame(PricingCategory::Fixed, $card->category);
        $this->assertSame('Kiinteät hinnat', $card->band->headline);
        $this->assertSame('Julkaistu etukäteen, ei sidottu markkinaan', $card->band->detail);

        // The warning lives in the footer, never in the band.
        $this->assertSame('Hinta nousee 1.8.2026', $card->warnings[0]->text);
        $this->assertStringNotContainsStringIgnoringCase('nousee', $card->band->headline.' '.$card->band->detail);
    }

    // ---------------------------------------------------------------- receipt lines

    public function test_fixed_contract_shows_energy_and_the_monthly_fee(): void
    {
        $card = $this->present($this->contract(cost: ['general_kwh_price' => 7.2, 'monthly_fixed_fee' => 4.05]));

        $this->assertSame(['Energia', 'Perusmaksu'], array_map(fn ($l) => $l->label, $card->receiptLines));
        $this->assertSame('7,20', $card->receiptLines[0]->value);
        $this->assertSame('4,05', $card->receiptLines[1]->value);
        $this->assertSame('€/kk', $card->receiptLines[1]->unit);
    }

    public function test_spot_contract_shows_a_soft_market_baseline_above_its_margin(): void
    {
        $card = $this->present($this->contract(['pricing_model' => 'Spot'], [
            'is_spot_contract' => true,
            'estimate_method' => 'rolling_365_spot',
            'general_kwh_price' => null,
            'spot_price_margin' => 0.39,
            'spot_price_day_avg' => 7.77,
            'spot_price_night_avg' => 5.89,
            'monthly_fixed_fee' => 0.0,
        ]));

        $this->assertSame(['Pörssin keskihinta 12 kk', 'Marginaali', 'Perusmaksu'], array_map(fn ($l) => $l->label, $card->receiptLines));
        // The baseline is the estimated part, the margin is contractual.
        $this->assertTrue($card->receiptLines[0]->soft);
        $this->assertFalse($card->receiptLines[1]->soft);
        $this->assertSame('7,77', $card->receiptLines[0]->value);
    }

    public function test_market_reset_shows_the_known_period_price_above_the_estimated_tail(): void
    {
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing([
                'present' => true, 'cadence' => 'quarterly', 'current_period_end' => '2026-09-30',
            ]),
        ], [
            'estimate_method' => 'recurring_forward_curve_shift',
            'general_kwh_price' => 7.97,
            'monthly_fixed_fee' => 2.53,
            'reset_estimate' => [
                'basis' => 'forward_curve_shift',
                'cadence' => 'quarterly',
                'current_period_energy_price' => 7.97,
                'annual_equivalent_energy_price' => 10.51,
                'curve_trade_date' => '2026-07-24',
                'tail_starts' => '2026-10',
            ],
        ]));

        $this->assertSame(['Energia nyt, 30.9. asti', 'Loppuvuosi, arvio', 'Perusmaksu'], array_map(fn ($l) => $l->label, $card->receiptLines));
        $this->assertFalse($card->receiptLines[0]->soft);
        $this->assertTrue($card->receiptLines[1]->soft);
        $this->assertSame('10,51', $card->receiptLines[1]->value);
    }

    public function test_reset_boundary_falls_back_to_the_estimated_tail_start(): void
    {
        // Many reset lineages leave current_period_end null while the estimator still derives
        // a tail start. Band and receipt must then state the same date, not one go silent.
        $card = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'quarterly']),
        ], [
            'estimate_method' => 'recurring_forward_curve_shift',
            'reset_estimate' => [
                'current_period_energy_price' => 6.95,
                'annual_equivalent_energy_price' => 9.62,
                'tail_starts' => '2026-10',
            ],
        ]));

        $this->assertSame('Seuraava tarkistus 1.10.2026', $card->band->detail);
        $this->assertSame('Energia nyt, 30.9. asti', $card->receiptLines[0]->label);
    }

    public function test_consumption_effect_contract_states_the_uncosted_adjustment(): void
    {
        $card = $this->present($this->contract([
            'pricing_model' => 'Hybrid',
            'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'base_contract']),
        ], ['estimate_method' => 'hybrid_base_only', 'general_kwh_price' => 8.59, 'monthly_fixed_fee' => 0.0]));

        $this->assertSame(['Perushinta', 'Kulutusvaikutus', 'Perusmaksu'], array_map(fn ($l) => $l->label, $card->receiptLines));
        $this->assertSame('± käyttöajan mukaan', $card->receiptLines[1]->value);
        $this->assertNull($card->receiptLines[1]->unit);
        $this->assertTrue($card->receiptLines[1]->soft);
    }

    public function test_a_scheduled_increase_shows_both_dated_prices(): void
    {
        $card = $this->present($this->contract(
            cost: ['general_kwh_price' => 5.49, 'monthly_fixed_fee' => 2.99],
            integrity: [
                'card_label' => 'Hinta nousee 1.8.2026',
                'change_date' => '2026-08-01',
                'promo_rate_cents' => 5.49,
                'normal_rate_cents' => 13.65,
            ],
        ));

        $this->assertSame(['Energia 31.7. asti', 'Energia 1.8. alkaen', 'Perusmaksu'], array_map(fn ($l) => $l->label, $card->receiptLines));
        $this->assertSame('13,65', $card->receiptLines[1]->value);
    }

    public function test_time_metering_shows_day_and_night_rates(): void
    {
        $card = $this->present($this->contract(['metering' => 'Time'], [
            'general_kwh_price' => null, 'daytime_kwh_price' => 8.35, 'nighttime_kwh_price' => 6.1,
        ]));

        $this->assertSame(['Päivä', 'Yö', 'Perusmaksu'], array_map(fn ($l) => $l->label, $card->receiptLines));
    }

    public function test_season_metering_shows_winter_and_other_rates(): void
    {
        $card = $this->present($this->contract(['metering' => 'Season'], [
            'general_kwh_price' => null, 'seasonal_winter_day_kwh_price' => 8.1, 'seasonal_other_kwh_price' => 6.4,
        ]));

        $this->assertSame(['Talvi', 'Muu aika', 'Perusmaksu'], array_map(fn ($l) => $l->label, $card->receiptLines));
    }

    public function test_receipt_never_exceeds_three_rows(): void
    {
        $card = $this->present($this->contract(['pricing_model' => 'Spot', 'metering' => 'Time'], [
            'is_spot_contract' => true, 'estimate_method' => 'rolling_365_spot',
            'spot_price_margin' => 0.39, 'spot_price_day_avg' => 7.77, 'spot_price_night_avg' => 5.89,
            'daytime_kwh_price' => 8.0, 'nighttime_kwh_price' => 6.0,
        ]));

        $labels = array_map(fn ($l) => $l->label, $card->receiptLines);
        $this->assertLessThanOrEqual(3, count($labels));
        $this->assertSame('Perusmaksu', array_pop($labels));
    }

    public function test_rates_fall_back_to_relational_prices_when_no_cost_payload_exists(): void
    {
        $contract = $this->contract();
        $contract->calculated_cost = [];

        $card = $this->present($contract, prices: [
            'General' => ['price' => 6.5],
            'Monthly' => ['price' => 3.9],
        ]);

        $this->assertSame('6,50', $card->receiptLines[0]->value);
        $this->assertSame('3,90', $card->receiptLines[1]->value);
    }

    // ---------------------------------------------------------------- estimate disclosure

    public function test_an_exact_contract_has_no_estimate_chip(): void
    {
        $this->assertNull($this->present($this->contract())->estimate);
        $this->assertFalse($this->present($this->contract())->isEstimate());
    }

    public function test_each_estimate_method_produces_its_own_explanation(): void
    {
        $spot = $this->present($this->contract(['pricing_model' => 'Spot'], [
            'estimate_method' => 'rolling_365_spot', 'is_spot_contract' => true,
            'spot_price_day_avg' => 7.77, 'spot_price_night_avg' => 5.89, 'spot_price_margin' => 0.39,
        ]))->estimate;
        $this->assertStringContainsString('12 kuukauden toteutuneeseen pörssikeskihintaan', $spot->body);
        $this->assertStringContainsString('päivä 7,77 c', $spot->body);
        $this->assertStringContainsString('0,39 c/kWh', $spot->body);

        $term = $this->present($this->contract(
            ['contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed6'],
            ['estimate_method' => 'term_price_annualized', 'term_months' => 6],
        ))->estimate;
        $this->assertStringContainsString('kiinteä 6 kuukautta', $term->body);

        $hybrid = $this->present($this->contract(
            ['pricing_model' => 'Hybrid'],
            ['estimate_method' => 'hybrid_base_only', 'general_kwh_price' => 8.59],
        ))->estimate;
        $this->assertStringContainsString('8,59 c/kWh', $hybrid->body);
        $this->assertStringContainsString('kulutusvaikutus', $hybrid->body);

        $held = $this->present($this->contract([
            'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'monthly', 'current_period_end' => '2026-07-31']),
        ], ['estimate_method' => 'hold_current_recurring_price', 'general_kwh_price' => 4.98]))->estimate;
        $this->assertStringContainsString('4,98 c/kWh on tiedossa 31.7. asti', $held->body);
        $this->assertStringContainsString('kuukausittain', $held->body);
    }

    public function test_a_reset_that_is_also_base_only_hybrid_explains_the_reset_not_a_flat_price(): void
    {
        // Vaasan Sähkö Vaikuttaja / Korpela Kvartaali. The calculator decides the
        // unsupported-Hybrid branch before the recurring-reset branch, so estimate_method
        // reports `hybrid_base_only` even though the market-reset estimator repriced the tail.
        // Keying the copy off that one value claimed the year was priced at a flat current
        // rate while the receipt rows showed the shifted figure: 6,60 now vs 9,28 for the year.
        $card = $this->present($this->contract([
            'pricing_model' => 'Hybrid',
            'canonical_pricing' => $this->canonicalPricing(
                ['present' => true, 'cadence' => 'quarterly', 'current_period_end' => '2026-09-30'],
                ['present' => true, 'applies_to' => 'base_contract'],
            ),
        ], [
            'estimate_method' => 'hybrid_base_only',
            'general_kwh_price' => 6.6,
            'reset_estimate' => [
                'basis' => 'forward_curve_shift',
                'cadence' => 'quarterly',
                'current_period_energy_price' => 6.6,
                'annual_equivalent_energy_price' => 9.28,
                'tail_starts' => '2026-10',
            ],
        ]));

        $body = $card->estimate->body;

        // The price level comes from the reset, and states the annual figure the total used.
        $this->assertStringContainsString('6,60 c/kWh on tiedossa 30.9. asti', $body);
        $this->assertStringContainsString('sähköjohdannaisten markkinahinnoista', $body);
        $this->assertStringContainsString('9,28 c/kWh', $body);
        // The consumption effect is an exclusion appended to it, not a replacement for it.
        $this->assertStringContainsString('ei sisällä kulutusvaikutusta', $body);
        // It must NOT claim the year was priced at a flat base rate.
        $this->assertStringNotContainsString('kiinteällä perushinnalla', $body);
    }

    public function test_the_reset_basis_is_read_from_the_payload_not_the_estimate_method(): void
    {
        // With RESET_FORWARD_SHIFT_ENABLED off there is no payload and the tail holds flat,
        // so the same contract must say so rather than describe a curve it did not use.
        $card = $this->present($this->contract([
            'pricing_model' => 'Hybrid',
            'canonical_pricing' => $this->canonicalPricing(
                ['present' => true, 'cadence' => 'quarterly', 'current_period_end' => '2026-09-30'],
                ['present' => true, 'applies_to' => 'base_contract'],
            ),
        ], ['estimate_method' => 'hybrid_base_only', 'general_kwh_price' => 6.6]));

        $this->assertStringContainsString('arvio olettaa nykyisen hinnan jatkuvan', $card->estimate->body);
        $this->assertStringNotContainsString('johdannais', $card->estimate->body);
    }

    public function test_a_plain_hybrid_still_gets_the_flat_base_price_explanation(): void
    {
        // No reset schedule, so "laskettu kiinteällä perushinnalla" is the accurate statement
        // and must not be replaced by reset copy.
        $card = $this->present($this->contract([
            'pricing_model' => 'Hybrid',
            'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'base_contract']),
        ], ['estimate_method' => 'hybrid_base_only', 'general_kwh_price' => 8.59]));

        $this->assertStringContainsString('kiinteällä perushinnalla 8,59 c/kWh', $card->estimate->body);
        $this->assertStringNotContainsString('tarkistetaan', $card->estimate->body);
    }

    public function test_every_estimate_links_to_the_methodology_page(): void
    {
        $estimate = $this->present($this->contract(['pricing_model' => 'Spot'], [
            'estimate_method' => 'rolling_365_spot', 'is_spot_contract' => true,
        ]))->estimate;

        $this->assertSame('https://voltikka.fi/tietoa#menetelma', $estimate->linkUrl);
        $this->assertSame('Näin laskemme arviot', $estimate->linkText);
    }

    public function test_legacy_pricing_still_marks_a_spot_total_as_an_estimate(): void
    {
        // With CANONICAL_PRICING_ENABLED off there is no estimate_method, but a spot total is
        // still an estimate and has to say so.
        $card = $this->present($this->contract(['pricing_model' => 'Spot'], [
            'estimate_method' => null, 'is_spot_contract' => true, 'spot_price_day_avg' => 7.77, 'spot_price_night_avg' => 5.89,
        ]));

        $this->assertNotNull($card->estimate);
    }

    public function test_bill_mode_hides_the_annual_estimate_chip_but_keeps_the_band(): void
    {
        $contract = $this->contract(['pricing_model' => 'Spot'], [
            'estimate_method' => 'rolling_365_spot', 'is_spot_contract' => true,
        ]);

        $card = $this->present($contract, billMode: true);

        $this->assertNull($card->estimate);
        $this->assertSame('Hinta seuraa pörssin tuntihintaa', $card->band->headline);
    }

    // ---------------------------------------------------------------- footer

    public function test_consumption_caps_out_of_household_reach_are_not_shown(): void
    {
        // The largest consumption preset is 18 000 kWh/v, so "Max 80 000 kWh/v" is noise.
        $card = $this->present($this->contract(['consumption_limitation_max_x_kwh_per_y' => 80000]));

        $this->assertSame([], $card->warnings);
    }

    public function test_a_reachable_consumption_cap_is_shown(): void
    {
        $card = $this->present($this->contract(['consumption_limitation_max_x_kwh_per_y' => 20000]));

        $this->assertSame('Max 20 000 kWh/v', $card->warnings[0]->text);
    }

    public function test_an_exceeded_cap_is_always_shown_however_large_it_is(): void
    {
        // The card is greyed out and sorted last, so the reason must stay visible.
        $contract = $this->contract(['consumption_limitation_max_x_kwh_per_y' => 80000]);
        $contract->exceeds_consumption_limit = true;

        $card = $this->present($contract);

        $this->assertSame('Max 80 000 kWh/v', $card->warnings[0]->text);
        $this->assertTrue($card->exceedsConsumptionLimit);
    }

    public function test_warnings_follow_their_priority_order_and_stop_at_two(): void
    {
        $contract = $this->contract(
            ['consumption_limitation_max_x_kwh_per_y' => 20000, 'contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed6'],
            ['term_months' => 6],
            ['card_label' => 'Hinta nousee 1.8.2026', 'change_date' => '2026-08-01'],
        );
        $contract->comparability = 'term_price_only';

        $card = $this->present($contract);

        $this->assertCount(2, $card->warnings);
        $this->assertSame('Hinta nousee 1.8.2026', $card->warnings[0]->text);
        $this->assertSame('Max 20 000 kWh/v', $card->warnings[1]->text);
    }

    public function test_the_consumption_effect_caveat_is_suppressed_when_the_band_already_says_it(): void
    {
        $contract = $this->contract([
            'pricing_model' => 'Hybrid',
            'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'base_contract']),
        ]);
        $contract->comparability = 'base_only_hybrid';

        $card = $this->present($contract);

        $this->assertSame(PricingCategory::ConsumptionEffect, $card->category);
        $this->assertSame([], $card->warnings);
    }

    public function test_energy_source_is_a_fact_tag_with_the_real_figure(): void
    {
        $contract = $this->contract();
        $contract->emission_factor = 120.0;
        $contract->setRelation('electricitySource', new \App\Models\ElectricitySource(['renewable_total' => 13.0]));

        $card = $this->present($contract);

        $this->assertSame('Uusiutuva 13 %', $card->facts[0]->text);
    }

    public function test_zero_emission_contracts_are_labelled_paastoton(): void
    {
        $contract = $this->contract();
        $contract->emission_factor = 0.0;

        $this->assertSame('Päästötön', $this->present($contract)->facts[0]->text);
    }

    // ---------------------------------------------------------------- discounts

    public function test_a_rounding_size_saving_is_not_shown_as_a_promise(): void
    {
        $card = $this->present($this->contract(cost: [
            'includes_discounts' => true, 'discount_savings_total' => 2.0, 'base_total_cost' => 602.0,
        ]));

        $this->assertNull($card->discountSavings);
    }

    public function test_a_meaningful_saving_is_shown(): void
    {
        $card = $this->present($this->contract(cost: [
            'includes_discounts' => true, 'discount_savings_total' => 42.0, 'base_total_cost' => 642.0,
        ]));

        $this->assertSame(42.0, $card->discountSavings);
    }

    // ---------------------------------------------------------------- render smoke tests

    public function test_the_card_renders_its_band_estimate_chip_and_footer(): void
    {
        $contract = $this->contract(
            ['pricing_model' => 'Spot', 'consumption_limitation_max_x_kwh_per_y' => 20000],
            [
                'estimate_method' => 'rolling_365_spot', 'is_spot_contract' => true,
                'spot_price_margin' => 0.39, 'spot_price_day_avg' => 7.77, 'spot_price_night_avg' => 5.89,
                'monthly_fixed_fee' => 0.0,
            ],
        );

        $html = $this->blade('<x-contract-card :contract="$contract" :consumption="5000" />', ['contract' => $contract]);

        $html->assertSee('Hinta seuraa pörssin tuntihintaa');
        $html->assertSee('Pörssin keskihinta 12 kk');
        $html->assertSee('Max 20 000 kWh/v');
        // The Arvio chip has to be a real button with the methodology link inside it.
        $html->assertSee('Arvio');
        $html->assertSee('voltikka.fi/tietoa#menetelma');
        $html->assertSee('Näin laskemme arviot');
    }

    public function test_the_featured_card_renders_the_same_warnings_as_a_normal_card(): void
    {
        // The featured card used to have no integrity warning at all, which is why it now
        // shares the presenter. Pin that it warns.
        $contract = $this->contract(
            cost: ['general_kwh_price' => 5.49],
            integrity: [
                'card_label' => 'Hinta nousee 1.8.2026',
                'change_date' => '2026-08-01',
                'promo_rate_cents' => 5.49,
                'normal_rate_cents' => 13.65,
            ],
        );

        $html = $this->blade('<x-featured-contract-card :contract="$contract" :consumption="5000" />', ['contract' => $contract]);

        $html->assertSee('Hinta nousee 1.8.2026');
        $html->assertSee('Kiinteät hinnat');
        $html->assertSee('Energia 1.8. alkaen');
    }

    public function test_bill_mode_renders_period_figures_without_the_annual_receipt(): void
    {
        $contract = $this->contract(['pricing_model' => 'Spot'], [
            'estimate_method' => 'rolling_365_spot', 'is_spot_contract' => true,
            'spot_price_margin' => 0.39, 'spot_price_day_avg' => 7.77,
        ]);

        $html = $this->blade(
            '<x-contract-card :contract="$contract" :consumption="5000" :bill-mode="true" :period-comparison="$period" />',
            [
                'contract' => $contract,
                'period' => ['period_monthly_cost' => 41.5, 'period_monthly_saving' => 8.25, 'is_spot' => true],
            ],
        );

        $html->assertSee('laskutusjaksollasi · arvio');
        $html->assertSee('Säästö 8,3 €/kk');
        // The band still states the category; the annual receipt and Arvio chip do not belong
        // beside a billing-period figure.
        $html->assertSee('Hinta seuraa pörssin tuntihintaa');
        $html->assertDontSee('Pörssin keskihinta 12 kk');
    }

    public function test_the_card_no_longer_renders_percentile_callouts(): void
    {
        $contract = $this->contract(cost: ['general_kwh_price' => 3.0, 'monthly_fixed_fee' => 1.0]);

        $html = $this->blade(
            '<x-contract-card :contract="$contract" :consumption="5000" :percentiles="$percentiles" />',
            [
                'contract' => $contract,
                // The prop is retained as a no-op so existing callers do not break.
                'percentiles' => [
                    'fixed_energy' => ['p15' => 5.0, 'p85' => 9.0],
                    'monthly_fee' => ['p15' => 2.0, 'p85' => 6.0],
                ],
            ],
        );

        $html->assertDontSee('Edullinen energianhinta');
        $html->assertDontSee('Edullinen perusmaksu');
    }

    // ---------------------------------------------------------------- query scope parity

    public function test_the_query_scope_agrees_with_the_resolver(): void
    {
        $fixtures = [
            ['id' => 'cat-spot', 'pricing_model' => 'Spot', 'canonical_pricing' => $this->canonicalPricing()],
            ['id' => 'cat-reset', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'quarterly'])],
            ['id' => 'cat-effect', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'base_contract'])],
            ['id' => 'cat-hybrid', 'pricing_model' => 'Hybrid', 'canonical_pricing' => $this->canonicalPricing()],
            ['id' => 'cat-fixed', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing()],
            ['id' => 'cat-both', 'pricing_model' => 'Hybrid', 'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'monthly'], ['present' => true, 'applies_to' => 'both'])],
            ['id' => 'cat-optional', 'pricing_model' => 'Spot', 'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'optional_fixing'])],
        ];

        \App\Models\Company::create([
            'name' => 'Testi Energia Oy',
            'name_slug' => 'testi-energia-oy',
            'company_url' => 'https://testi.fi',
        ]);

        foreach ($fixtures as $attributes) {
            ElectricityContract::create(array_merge([
                'company_name' => 'Testi Energia Oy',
                'name' => 'Sopimus '.$attributes['id'],
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'target_group' => 'Household',
                'availability_is_national' => true,
            ], $attributes));
        }

        $resolver = new PricingCategoryResolver();
        $all = ElectricityContract::all();

        foreach (PricingCategory::cases() as $category) {
            $expected = $all
                ->filter(fn (ElectricityContract $c) => $resolver->resolve($c)->category === $category)
                ->pluck('id')->sort()->values()->all();

            $query = ElectricityContract::query();
            PricingCategoryResolver::scopeCategory($query, $category);
            $actual = $query->pluck('id')->sort()->values()->all();

            $this->assertSame($expected, $actual, "scopeCategory disagrees with resolve() for {$category->value}");
        }
    }

    public function test_the_bucket_scope_agrees_with_the_resolver_and_partitions_the_set(): void
    {
        $fixtures = [
            ['id' => 'buc-spot', 'pricing_model' => 'Spot', 'canonical_pricing' => $this->canonicalPricing()],
            // Spot wins inside the market category: a spot contract that also carries a reset
            // schedule is Pörssisähkö, not Päivittyvä hinta.
            ['id' => 'buc-spot-reset', 'pricing_model' => 'Spot', 'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'quarterly'])],
            ['id' => 'buc-reset-quarterly', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'quarterly'])],
            ['id' => 'buc-reset-monthly', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'monthly'])],
            ['id' => 'buc-reset-unknown-cadence', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'unknown'])],
            ['id' => 'buc-effect', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'base_contract'])],
            ['id' => 'buc-hybrid', 'pricing_model' => 'Hybrid', 'canonical_pricing' => $this->canonicalPricing()],
            // Reset base + effect: the reset bucket, with the effect left as a footer caveat.
            ['id' => 'buc-both', 'pricing_model' => 'Hybrid', 'canonical_pricing' => $this->canonicalPricing(['present' => true, 'cadence' => 'monthly'], ['present' => true, 'applies_to' => 'both'])],
            ['id' => 'buc-optional', 'pricing_model' => 'Spot', 'canonical_pricing' => $this->canonicalPricing([], ['present' => true, 'applies_to' => 'optional_fixing'])],
            ['id' => 'buc-fixed', 'pricing_model' => 'FixedPrice', 'canonical_pricing' => $this->canonicalPricing()],
        ];

        \App\Models\Company::create([
            'name' => 'Testi Energia Oy',
            'name_slug' => 'testi-energia-oy',
            'company_url' => 'https://testi.fi',
        ]);

        foreach ($fixtures as $attributes) {
            ElectricityContract::create(array_merge([
                'company_name' => 'Testi Energia Oy',
                'name' => 'Sopimus '.$attributes['id'],
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'target_group' => 'Household',
                'availability_is_national' => true,
            ], $attributes));
        }

        $resolver = new PricingCategoryResolver();
        $all = ElectricityContract::all();
        $listed = [];

        foreach (PricingBucket::cases() as $bucket) {
            $expected = $all
                ->filter(fn (ElectricityContract $c) => PricingBucket::fromFacts($resolver->resolve($c)) === $bucket)
                ->pluck('id')->sort()->values()->all();

            $query = ElectricityContract::query();
            PricingCategoryResolver::scopeBucket($query, $bucket);
            $actual = $query->pluck('id')->sort()->values()->all();

            $this->assertSame($expected, $actual, "scopeBucket disagrees with the resolver for {$bucket->value}");
            $listed = array_merge($listed, $actual);
        }

        // The buckets partition the set: no contract is listed twice and none is dropped.
        $this->assertSame(count($listed), count(array_unique($listed)), 'a contract fell into more than one bucket');
        $this->assertSame($all->pluck('id')->sort()->values()->all(), collect($listed)->sort()->values()->all());

        // The two market buckets together are exactly the existing Market category scope.
        $marketQuery = ElectricityContract::query();
        PricingCategoryResolver::scopeCategory($marketQuery, PricingCategory::Market);

        $spotQuery = ElectricityContract::query();
        PricingCategoryResolver::scopeBucket($spotQuery, PricingBucket::Spot);
        $resetQuery = ElectricityContract::query();
        PricingCategoryResolver::scopeBucket($resetQuery, PricingBucket::MarketReset);

        $this->assertSame(
            $marketQuery->pluck('id')->sort()->values()->all(),
            $spotQuery->pluck('id')->merge($resetQuery->pluck('id'))->sort()->values()->all(),
        );

        $this->assertContains('buc-spot-reset', $spotQuery->pluck('id')->all());
    }
}
