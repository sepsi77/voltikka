<?php

namespace Tests\Feature;

use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\ContractCard\ContractCardCopy;
use App\Services\ContractCard\ContractCardPresenter;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingCategory;
use App\Services\ContractPricing\ContractPricingViewData;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AnnualEstimateCopyConsistencyTest extends TestCase
{
    public function test_spot_vat_copy_uses_the_selected_bill_basis(): void
    {
        foreach (['rolling_365_spot', 'forward_curve_spot'] as $method) {
            foreach (['included', 'excluded'] as $basis) {
                $estimate = ContractCardCopy::estimate($this->pricing($method, [
                    'vat_basis' => $basis,
                    'spot_price_day_avg' => 8,
                    'spot_price_night_avg' => 8,
                    'spot_price_margin' => 0.5,
                ]), new PricingCategoryFacts(PricingCategory::Market, isSpot: true));
                $this->assertStringContainsString($basis === 'excluded' ? 'ilman alv:tä' : 'sis. alv', $estimate->body);
            }
        }
    }

    public function test_held_unknown_prices_have_one_visible_estimate_popover(): void
    {
        $facts = new PricingCategoryFacts(PricingCategory::Fixed);
        $estimate = ContractCardCopy::estimate($this->pricing('hold_last_known_price'), $facts);

        $this->assertNotNull($estimate);
        $this->assertStringContainsString('tiedossa olevia hintoja niiden voimassaoloajalta', $estimate->body);
        $this->assertStringContainsString('viimeisin soveltuva hinta', $estimate->body);
        $this->assertStringContainsString('myyjän ilmoittama normaalihinta', $estimate->body);
        $this->assertStringContainsString('ei ole hintalupaus', $estimate->body);
        $this->assertDoesNotMatchRegularExpression('/kuukausittain|neljännesvuosittain|hinta tarkistetaan/ui', $estimate->body);

        $html = Blade::render('<x-card.band :band="$band" :estimate="$estimate" />', [
            'band' => ContractCardCopy::band($facts, 'OpenEnded', null, hasEstimatedUnknownPrices: true),
            'estimate' => $estimate,
        ]);
        $this->assertStringContainsString('Arvio', $html);
        $this->assertStringContainsString($estimate->body, $html);
        $this->assertSame(1, substr_count($html, 'wire:key="info-popover-panel"'));
        $this->assertStringContainsString('/tietoa#menetelma', $html);
    }

    public function test_short_term_copy_annualizes_the_real_term_without_claiming_one_constant_price(): void
    {
        foreach (['term_price_annualized', 'hold_last_known_price'] as $method) {
            $estimate = ContractCardCopy::estimate($this->pricing($method, [
                'term_months' => 6,
                'contract_term' => ['months' => 6, 'total_cost' => 250, 'base_total_cost' => 300, 'discount_savings_total' => 50],
                'assumptions' => ['term_price_annualized', 'unknown_periods_use_latest_applicable_price_or_disclosed_normal'],
            ]), new PricingCategoryFacts(PricingCategory::Fixed));

            $this->assertNotNull($estimate);
            $this->assertStringContainsString('sopimuskauden laskettu kustannus luvulla 12 / 6', $estimate->body);
            $this->assertStringContainsString('mahdolliset arviot tuntemattomille osille', $estimate->body);
            $this->assertStringNotContainsString('sama hinta jatkuu koko vuoden', $estimate->body);
            $this->assertStringNotContainsString('Sopimus on kiinteä', $estimate->body);
        }
    }

    public function test_hybrid_base_prices_and_unknown_parts_compose_with_short_term_cost(): void
    {
        $estimate = ContractCardCopy::estimate($this->pricing('hybrid_base_only', [
            'term_months' => 6,
            'general_kwh_price' => 8.59,
            'phase_breakdown' => [
                $this->phase('2026-08-01', '2026-08-31', 8.59),
                $this->phase('2026-09-01', '2026-10-31', 10.59),
            ],
            'assumptions' => ['term_price_annualized', 'excludes_consumption_effect'],
        ]), new PricingCategoryFacts(PricingCategory::ConsumptionEffect, hasConsumptionEffect: true));

        $this->assertNotNull($estimate);
        $this->assertStringContainsString('tiedossa olevia perushintoja', $estimate->body);
        $this->assertStringContainsString('tuntemattomat osat arvioidaan', $estimate->body);
        $this->assertStringContainsString('12 / 6', $estimate->body);
        $this->assertSame(1, substr_count($estimate->body, 'Arvio ei sisällä kulutusvaikutusta'));
        $this->assertStringNotContainsString('kiinteällä perushinnalla 8,59', $estimate->body);
    }

    public function test_reset_and_term_reasons_keep_the_hybrid_exclusion(): void
    {
        $estimate = ContractCardCopy::estimate($this->pricing('hybrid_base_only', [
            'term_months' => 6,
            'assumptions' => ['term_price_annualized', 'excludes_consumption_effect'],
        ]), new PricingCategoryFacts(PricingCategory::Market, isReset: true, cadence: 'quarterly', hasConsumptionEffect: true));

        $this->assertStringContainsString('nykyisen tunnetun hintajakson', $estimate->body);
        $this->assertStringContainsString('neljännesvuosittain', $estimate->body);
        $this->assertStringContainsString('12 / 6', $estimate->body);
        $this->assertSame(1, substr_count($estimate->body, 'Arvio ei sisällä kulutusvaikutusta'));
        $this->assertStringNotContainsString('kiinteällä perushinnalla', $estimate->body);
    }

    public function test_forward_shape_fallback_does_not_claim_historical_day_night_difference(): void
    {
        $estimate = ContractCardCopy::estimate($this->pricing('forward_curve_spot', [
            'spot_price_day_avg' => 8,
            'spot_price_night_avg' => 8,
            'spot_price_margin' => 0.39,
            'spot_estimate' => $this->spotEstimate('lower'),
        ]), new PricingCategoryFacts(PricingCategory::Market, isSpot: true));

        $this->assertStringContainsString('sähköfutuureihin', $estimate->body);
        $this->assertStringContainsString('päivälle ja yölle oletetaan sama pörssihinta', $estimate->body);
        $this->assertStringContainsString('marginaali 0,39 c/kWh', $estimate->body);
        $this->assertStringNotContainsString('ero säilytetään', $estimate->body);
        $this->assertStringNotContainsString('365 päivän', $estimate->body);
    }

    public function test_good_shape_and_missing_auxiliary_payload_keep_existing_forward_copy(): void
    {
        foreach ([$this->spotEstimate('higher'), null] as $spot) {
            $estimate = ContractCardCopy::estimate($this->pricing('forward_curve_spot', ['spot_estimate' => $spot]), new PricingCategoryFacts(PricingCategory::Market, isSpot: true));
            $this->assertStringContainsString('365 päivän toteutuneiden päivä- ja yöhintojen ero säilytetään', $estimate->body);
            $this->assertStringNotContainsString('oletetaan sama pörssihinta', $estimate->body);
        }

        $this->assertNull(ContractCardCopy::estimate($this->pricing('none'), new PricingCategoryFacts(PricingCategory::Fixed)));
        $this->assertNotNull(ContractCardCopy::estimate($this->pricing('term_price_annualized'), new PricingCategoryFacts(PricingCategory::Fixed)));
    }

    public function test_presenter_does_not_guarantee_unknown_fixed_or_hybrid_prices(): void
    {
        foreach ([
            ['FixedPrice', 'hold_last_known_price', [], PricingCategory::Fixed],
            ['FixedPrice', 'term_price_annualized', ['unknown_periods_use_latest_applicable_price_or_disclosed_normal'], PricingCategory::Fixed],
            ['Hybrid', 'hybrid_base_only', ['unknown_periods_use_latest_applicable_price_or_disclosed_normal'], PricingCategory::ConsumptionEffect],
        ] as [$model, $method, $assumptions, $category]) {
            $contract = new ElectricityContract([
                'id' => 'copy-test', 'name' => 'Copy test', 'company_name' => 'Copy Oy',
                'pricing_model' => $model, 'contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed6',
                'metering' => 'General',
            ]);
            $contract->setRelation('electricitySource', null);
            $contract->setAttribute('calculated_cost', $this->pricing($method, ['term_months' => 6, 'assumptions' => $assumptions])->toArray());
            $card = (new ContractCardPresenter(new PricingMode(true, false)))->present($contract);

            $this->assertSame($category, $card->category);
            $this->assertNotNull($card->estimate);
            $this->assertStringContainsString('on arvioitu', $card->band->detail);
            $this->assertStringNotContainsString('ei muutu', $card->band->headline);
            $this->assertStringNotContainsString('Kiinteä hinta +', $card->band->headline);
            $this->assertStringNotContainsString('Myyjä voi muuttaa', $card->band->detail);
        }

        $band = ContractCardCopy::band(new PricingCategoryFacts(PricingCategory::Fixed), 'FixedTerm', 'Fixed12');
        $this->assertSame('Ennalta ilmoitettu energianhinta', $band->headline);
    }

    private function spotEstimate(string $confidence): array
    {
        return (new SpotEstimate(
            basis: SpotEstimateBasis::ForwardCurve,
            shapeOverallCentsPerKwh: $confidence === 'higher' ? 7 : null,
            shapeDayCentsPerKwh: $confidence === 'higher' ? 8 : null,
            shapeNightCentsPerKwh: $confidence === 'higher' ? 6 : null,
            dayOffsetCentsPerKwh: $confidence === 'higher' ? 1 : 0,
            nightOffsetCentsPerKwh: $confidence === 'higher' ? -1 : 0,
            shapePeriodStart: '2025-08-01',
            shapePeriodEnd: '2026-07-31',
            currentCurveTradeDate: '2026-07-30',
            futureCurveTradeDate: '2026-07-30',
            months: ['2026-08' => ['base_price' => 8, 'day_price' => 8, 'night_price' => 8, 'source_kind' => 'month', 'trade_date' => '2026-07-30']],
            annualEquivalentBaseCentsPerKwh: 8,
            annualEquivalentDayCentsPerKwh: 8,
            annualEquivalentNightCentsPerKwh: 8,
            confidence: $confidence,
            flags: $confidence === 'lower' ? ['zero_intraday_shape_fallback', 'insufficient_shape_coverage'] : [],
        ))->toArray();
    }

    private function phase(string $start, string $end, float $rate): array
    {
        return [
            'label' => 'Known price', 'phase_kind' => 'current_structured',
            'starts' => 'date', 'ends' => 'date', 'ends_value' => $end,
            'window_start' => $start, 'window_end' => $end,
            'uses_spot' => false, 'energy_cents' => $rate,
            'spot_margin_cents' => null, 'monthly_fee' => 4, 'energy_package' => null,
        ];
    }

    private function pricing(string $method, array $overrides = []): ContractPricingViewData
    {
        return ContractPricingViewData::fromArray(array_replace([
            'pricing_basis' => 'canonical',
            'total_cost' => 600.0,
            'avg_monthly_cost' => 50.0,
            'monthly_costs' => array_fill(0, 12, 50.0),
            'monthly_fixed_fee' => 4.0,
            'spot_price_margin' => null,
            'general_kwh_price' => 7.2,
            'nighttime_kwh_price' => null,
            'daytime_kwh_price' => null,
            'seasonal_winter_day_kwh_price' => null,
            'seasonal_other_kwh_price' => null,
            'spot_price_day_avg' => null,
            'spot_price_night_avg' => null,
            'is_spot_contract' => false,
            'base_total_cost' => 600.0,
            'base_avg_monthly_cost' => 50.0,
            'base_monthly_costs' => array_fill(0, 12, 50.0),
            'discount_savings_total' => 0.0,
            'monthly_discount_savings' => array_fill(0, 12, 0.0),
            'includes_discounts' => false,
            'comparability' => $method === 'none' ? 'comparable_exact' : 'comparable_estimate',
            'is_estimate' => $method !== 'none',
            'estimate_method' => $method,
            'term_months' => null,
            'energy_package' => null,
            'contract_term' => null,
            'phase_breakdown' => [],
            'offer_terms' => [],
            'structured_only_total' => 600.0,
            'consumption_effect' => null,
            'assumptions' => [],
            'reset_estimate' => null,
            'supplier_adjusted_estimate' => null,
            'spot_estimate' => null,
        ], $overrides));
    }
}
