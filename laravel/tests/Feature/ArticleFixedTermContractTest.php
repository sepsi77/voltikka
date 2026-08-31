<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArticleFixedTermContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('canonical_pricing.enabled', true);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);
        config()->set('price_forecasting.fixed_term.model_version', 'article_test_model');
        app()->forgetScopedInstances();
        Cache::flush();
    }

    public function test_article_tells_one_plain_language_decision_story_from_aggregate_data(): void
    {
        $this->unitStatistic('2026-06-08', 'open_ended', 8.0, 9.0, 10.0, 14);
        $this->unitStatistic('2026-06-15', 'open_ended', 8.0, 9.0, 10.0, 14);
        $this->statistic('2026-06-08', 6, 8.2, 9.2, 10.2, 12);
        $this->statistic('2026-06-08', 12, 8.1, 9.1, 10.1, 12);
        $this->statistic('2026-06-08', 24, 7.4, 8.4, 9.4, 12);
        $this->statistic('2026-06-15', 6, 9.2, 10.2, 11.2, 12);
        $this->statistic('2026-06-15', 12, 7.9, 8.9, 9.9, 12);
        $this->statistic('2026-06-15', 24, 7.4, 8.4, 9.4, 12);
        foreach ([6, 12, 24] as $duration) {
            $this->forecastQuantiles('2026-06-18', $duration);
        }
        $this->annualStatistic('2026-06-15', 'open_ended', 640.0, 718.0, 790.0, 14);
        $this->annualStatistic('2026-06-15', 'fixed_term_12', 630.0, 700.0, 780.0, 12);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $payload = app(ContractMarketInsightService::class)->fixedTermArticle();
        $aggregateQueries = implode("\n", $queries);
        $this->assertStringNotContainsString('electricity_contracts', $aggregateQueries);
        $this->assertStringNotContainsString('price_components', $aggregateQueries);
        $this->assertStringNotContainsString('active_contracts', $aggregateQueries);
        $this->assertSame(24, $payload['current']['lowest_fixed_duration_months']);
        $this->assertSame(6, $payload['current']['highest_fixed_duration_months']);
        $this->assertSame('fixed_12_cheaper', $payload['price_of_certainty']['difference_direction']);
        $this->assertTrue($payload['price_of_certainty']['difference_is_small']);
        $this->assertEqualsWithDelta(-1.5, $payload['price_of_certainty']['median_difference_monthly_eur'], 0.0001);
        $this->assertSame('down', $payload['forecast']['direction_summary']);
        $historyByDuration = collect($payload['history']['series'])->keyBy('duration_months');
        $this->assertSame('rose', $historyByDuration[6]['summary']['direction']);
        $this->assertSame('fell', $historyByDuration[12]['summary']['direction']);
        $this->assertSame('stable', $historyByDuration[24]['summary']['direction']);

        $response = $this->get('/sahkosopimus/kannattaako-maaraaikainen')->assertOk();
        $text = strip_tags($response->getContent());

        $response
            ->assertSeeText('Hinnat ovat nyt lähellä toisiaan')
            ->assertSeeText('12 kuukauden sopimuksen arvioitu vuosihinta on 700 € ja toistaiseksi voimassa olevan 718 €. Ero on 12 kuukauden sopimuksen hyväksi 18 € vuodessa eli 1,50 € kuukaudessa')
            ->assertSeeText('Ero on pieni')
            ->assertSeeText('Mitä tässä verrataan?')
            ->assertSeeText('Mediaani tarkoittaa listattujen hintojen keskimmäistä hintaa')
            ->assertSeeText('Mitä sopimus maksaisi vuodessa?')
            ->assertSeeText('718 €/vuosi')
            ->assertSeeText('700 €/vuosi')
            ->assertSeeText('12 kuukauden sopimus on noin 18 €/vuosi eli 1,50 €/kuukausi halvempi')
            ->assertSeeText('Mitä energiahinta on nyt?')
            ->assertSeeText('10,20 c/kWh')
            ->assertSeeText('8,90 c/kWh')
            ->assertSeeText('8,40 c/kWh')
            ->assertSeeText('Miten sopimuspituutta kannattaa ajatella?')
            ->assertSeeText('Tasapainoinen yhden vuoden valinta')
            ->assertSeeText('Matalin nykyinen keskimmäinen energiahinta, 8,40 c/kWh')
            ->assertSeeText('Korkein nykyinen keskimmäinen energiahinta, 10,20 c/kWh')
            ->assertSeeText('nousi')
            ->assertSeeText('9,20 c/kWh')
            ->assertSeeText('laski')
            ->assertSeeText('9,10 c/kWh')
            ->assertSeeText('pysyi lähes ennallaan')
            ->assertSeeText('Hinta, c/kWh')
            ->assertSeeText('Koralliviiva: keskimmäinen hinta')
            ->assertSeeText('Vaalea alue: hintojen keskimmäinen 60 % (p20–p80)')
            ->assertSeeText('Kaikki saatavilla olevat ennusteet viittaavat hienoiseen laskuun')
            ->assertSeeText('9,00 c/kWh')
            ->assertSeeText('8,80 c/kWh')
            ->assertSeeText('Laskua 0,20 c/kWh')
            ->assertSeeText('Luottamus: matala')
            ->assertSeeText('p20–p80 on listattujen hintojen keskimmäinen 60 %')
            ->assertSeeHtml('<details')
            ->assertSeeHtml('preserveAspectRatio="none"')
            ->assertSeeHtml('vector-effect="non-scaling-stroke"')
            ->assertSeeHtml('grid-cols-[76px_minmax(0,1fr)]')
            ->assertSeeText('Uusimmat markkina- ja ennustetiedot')
            ->assertSeeText('18.6.2026')
            ->assertSeeText('Teksti tarkistettu')
            ->assertSeeText('31.8.2026')
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen"')
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-6-kk"')
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-12-kk"')
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-24-kk"')
            ->assertSee('"headline": "Kannattaako määräaikainen sähkösopimus?"', false)
            ->assertSee('"datePublished": "2026-01-31"', false)
            ->assertSee('"dateModified": "2026-08-31"', false)
            ->assertSee('"temporalCoverage": "2026-06-18"', false)
            ->assertDontSeeText('Markkinakatsaus')
            ->assertDontSeeText('Lyhyt vastaus')
            ->assertDontSeeText('Varmuuden hinta')
            ->assertDontSeeText('aktiivinen menetelmä')
            ->assertDontSeeText('hinnoitteluperuste')
            ->assertDontSeeText('kelvollinen jakauma')
            ->assertDontSeeText('yhteinen aineistopäivä')
            ->assertDontSeeText('mediaaniero')
            ->assertDontSee('contract-type-comparison', false)
            ->assertDontSeeText('Alla oleva laskuri')
            ->assertDontSeeText('Sopimuksen purkaminen maksaa yleensä')
            ->assertDontSeeText('jatkuu automaattisesti');

        $this->assertTextAppearsInOrder($text, [
            'Hinnat ovat nyt lähellä toisiaan',
            'Mitä tässä verrataan?',
            'Mitä sopimus maksaisi vuodessa?',
            'Mitä energiahinta on nyt?',
            'Miten sopimuspituutta kannattaa ajatella?',
            'Miten määräaikaisten hinnat ovat muuttuneet?',
            'Mitä hinnalle voi tapahtua seuraavan 30 päivän aikana?',
            'Tarkista nämä ennen sopimusta',
            'Vertaa seuraavaksi tarjoukset',
        ]);
    }

    public function test_conclusion_changes_when_fixed_twelve_month_price_is_materially_higher(): void
    {
        $this->unitStatistic('2026-06-15', 'open_ended', 7.0, 8.0, 9.0, 20);
        $this->annualStatistic('2026-06-15', 'open_ended', 500.0, 600.0, 700.0, 20);
        $this->annualStatistic('2026-06-15', 'fixed_term_12', 600.0, 700.0, 800.0, 20);

        $payload = app(ContractMarketInsightService::class)->fixedTermArticle();
        $this->assertSame('open_ended_cheaper', $payload['price_of_certainty']['difference_direction']);
        $this->assertFalse($payload['price_of_certainty']['difference_is_small']);

        $this->get('/sahkosopimus/kannattaako-maaraaikainen')
            ->assertOk()
            ->assertSeeText('Toistaiseksi voimassa olevan sopimuksen arvio on nyt edullisempi')
            ->assertSeeText('12 kuukauden sopimuksen arvioitu vuosihinta on 700 € ja toistaiseksi voimassa olevan 600 €. Ero on toistaiseksi voimassa olevan sopimuksen hyväksi 100 € vuodessa eli 8,33 € kuukaudessa')
            ->assertSeeText('12 kuukauden sopimus on noin 100 €/vuosi eli 8,33 €/kuukausi kalliimpi')
            ->assertDontSeeText('Hinnat ovat nyt lähellä toisiaan')
            ->assertDontSeeText('Ero on pieni');
    }

    public function test_current_comparison_uses_latest_valid_four_segment_common_date_expected_basis_and_contract_floor(): void
    {
        foreach (['open_ended', 'fixed_term_6', 'fixed_term_12', 'fixed_term_24'] as $segment) {
            $this->unitStatistic('2026-05-20', $segment, 7.0, 8.0, 9.0, 10);
            $this->unitStatistic('2026-06-20', $segment, 10.0, 11.0, 12.0, 11);
            $this->unitStatistic('2026-06-21', $segment, 20.0, 21.0, 22.0, 20, 'observed_seller_data');
        }

        ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', '2026-06-20')
            ->where('segment_key', 'fixed_term_12')
            ->update(['p20_value' => 13.0]);
        ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', '2026-06-20')
            ->where('segment_key', 'fixed_term_24')
            ->update(['contract_count' => 9]);
        Cache::flush();

        $payload = app(ContractMarketInsightService::class)->fixedTermArticle();

        $this->assertSame('2026-05-20', $payload['current']['date']);
        $this->assertSame('canonical_calculation', $payload['current']['basis']);
        $this->assertSame(
            ['open_ended', 'fixed_term_6', 'fixed_term_12', 'fixed_term_24'],
            array_column($payload['current']['rows'], 'segment_key'),
        );
        $this->assertSame([10, 10, 10, 10], array_column($payload['current']['rows'], 'contract_count'));
    }

    public function test_feature_off_mode_fails_closed_for_article_open_ended_rows_without_changing_fixed_term_comparison(): void
    {
        foreach ([6, 12, 24] as $duration) {
            $this->statistic('2026-05-20', $duration, 7.0, 8.0, 9.0, 10, 'observed_seller_data');
            $this->statistic('2026-05-20', $duration, 17.0, 18.0, 19.0, 10);
        }
        $this->unitStatistic('2026-05-20', 'open_ended', 7.0, 8.0, 9.0, 10, 'observed_seller_data');

        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        Cache::flush();

        $service = app(ContractMarketInsightService::class);
        $article = $service->fixedTermArticle();

        $this->assertSame([], $article['current']);
        $this->assertSame([], $article['price_of_certainty']);
        $this->assertSame([6, 12, 24], array_column($service->fixedTermComparison()['rows'], 'duration_months'));
        $sixMonthHistory = collect($article['history']['series'])->firstWhere('duration_months', 6);
        $this->assertEqualsWithDelta(8.0, $sixMonthHistory['points'][0]['median'], 0.0001);
    }

    public function test_history_is_bounded_weekly_and_canonical_wins_same_date(): void
    {
        $this->statistic('2025-05-01', 6, 2.0, 3.0, 4.0, 20, 'observed_seller_data');
        foreach ([6, 12, 24] as $duration) {
            $this->statistic('2026-05-04', $duration, 7.0, 8.0, 9.0, 20, 'observed_seller_data');
            $this->statistic('2026-05-04', $duration, 17.0, 18.0, 19.0, 30);
            $this->statistic('2026-05-05', $duration, 19.0, 20.0, 21.0, 40);
        }
        $this->unitStatistic('2026-05-05', 'open_ended', 9.0, 10.0, 11.0, 40);

        $history = app(ContractMarketInsightService::class)->fixedTermArticle()['history'];
        $sixMonth = collect($history['series'])->firstWhere('duration_months', 6);

        $this->assertSame('2025-05-05', $history['start_date']);
        $this->assertSame('2026-05-05', $history['end_date']);
        $this->assertSame([6, 12, 24], array_column($history['series'], 'duration_months'));
        $this->assertCount(1, $sixMonth['points']);
        $this->assertEqualsWithDelta(19.0, $sixMonth['points'][0]['median'], 0.0001);
        $this->assertSame(35, $sixMonth['points'][0]['contract_count']);
        $this->assertSame('stable', $sixMonth['summary']['direction']);
        $this->assertGreaterThanOrEqual(4, count($history['ticks']));
        $this->assertLessThanOrEqual(6, count($history['ticks']));
        $this->assertLessThanOrEqual(18.0, $history['scale_min']);
        $this->assertGreaterThanOrEqual(20.0, $history['scale_max']);
        $this->assertSame(100.0, $history['ticks'][0]['percent']);
        $this->assertSame(0.0, $history['ticks'][array_key_last($history['ticks'])]['percent']);
    }

    public function test_price_of_certainty_accepts_different_segment_estimators_on_latest_eligible_common_date(): void
    {
        $this->unitStatistic('2026-06-24', 'open_ended', 7.0, 8.0, 9.0, 20);

        $this->annualStatistic('2026-06-20', 'open_ended', 580.0, 650.0, 740.0, 14);
        $this->annualStatistic('2026-06-20', 'fixed_term_12', 620.0, 700.0, 790.0, 12);

        $this->annualStatistic('2026-06-21', 'open_ended', 800.0, 700.0, 900.0, 20);
        $this->annualStatistic('2026-06-21', 'fixed_term_12', 700.0, 800.0, 900.0, 20);

        $this->annualStatistic('2026-06-24', 'open_ended', 700.0, 800.0, 900.0, 20, estimateMethod: 'supplier_adjusted_spot_seasonal_index');
        $this->annualStatistic('2026-06-24', 'fixed_term_12', 700.0, 810.0, 920.0, 20, estimateMethod: 'none');

        $this->annualStatistic('2026-06-25', 'open_ended', 100.0, 200.0, 300.0, 20, pricingBasis: 'observed_seller_data');
        $this->annualStatistic('2026-06-25', 'fixed_term_12', 100.0, 210.0, 300.0, 20, pricingBasis: 'observed_seller_data');
        $this->annualStatistic('2026-06-26', 'open_ended', 100.0, 200.0, 300.0, 20, methodVersion: AnnualCostMethodVersion::Legacy->value);
        $this->annualStatistic('2026-06-26', 'fixed_term_12', 100.0, 210.0, 300.0, 20, methodVersion: AnnualCostMethodVersion::Legacy->value);
        Cache::flush();

        $comparison = app(ContractMarketInsightService::class)->fixedTermArticle()['price_of_certainty'];

        $this->assertSame('2026-06-24', $comparison['date']);
        $this->assertSame(AnnualCostMethodVersion::AsOf->value, $comparison['method_version']);
        $this->assertSame(5000, $comparison['consumption_kwh']);
        $this->assertEqualsWithDelta(10.0, $comparison['median_difference_eur'], 0.0001);
    }

    public function test_price_of_certainty_accepts_mixed_evidence_only_on_current_unit_endpoint(): void
    {
        $this->unitStatistic('2026-06-30', 'open_ended', 7.0, 8.0, 9.0, 20);
        $this->annualStatistic('2026-06-29', 'open_ended', 500.0, 600.0, 700.0, 20, pricingBasis: 'mixed_evidence');
        $this->annualStatistic('2026-06-29', 'fixed_term_12', 550.0, 650.0, 750.0, 20, pricingBasis: 'mixed_evidence');
        $this->annualStatistic('2026-06-30', 'open_ended', 600.0, 700.0, 800.0, 20, pricingBasis: 'mixed_evidence');
        $this->annualStatistic('2026-06-30', 'fixed_term_12', 650.0, 760.0, 850.0, 20, pricingBasis: 'mixed_evidence');
        Cache::flush();

        $comparison = app(ContractMarketInsightService::class)->fixedTermArticle()['price_of_certainty'];

        $this->assertSame('2026-06-30', $comparison['date']);
        $this->assertSame(['mixed_evidence', 'mixed_evidence'], array_column($comparison['rows'], 'pricing_basis'));
        $this->assertEqualsWithDelta(60.0, $comparison['median_difference_eur'], 0.0001);
    }

    public function test_forecast_rejects_old_model_and_wrong_basis_and_marks_incomplete_duration_unavailable(): void
    {
        foreach ([6, 12, 24] as $duration) {
            $this->forecastQuantiles('2026-06-18', $duration);
            $this->forecastQuantiles('2026-06-19', $duration, modelVersion: 'old_model');
            $this->forecastQuantiles('2026-06-20', $duration, pricingBasis: 'observed_seller_data');
        }

        FixedContractPriceForecast::query()
            ->whereDate('forecast_date', '2026-06-18')
            ->where('duration_months', 24)
            ->where('target_quantile', 'p80')
            ->update(['forecast_price_cents_per_kwh' => 8.0]);
        Cache::flush();

        $forecast = app(ContractMarketInsightService::class)->fixedTermArticle()['forecast'];
        $durations = collect($forecast['durations'])->keyBy('duration_months');

        $this->assertSame('2026-06-18', $forecast['date']);
        $this->assertTrue($durations[6]['available']);
        $this->assertTrue($durations[12]['available']);
        $this->assertFalse($durations[24]['available']);
        $this->assertSame('2026-07-18', $durations[12]['target_date']);
        $this->assertEqualsWithDelta(-0.2, $durations[12]['median_change'], 0.0001);
        $this->assertSame('down', $forecast['direction_summary']);
    }

    public function test_forecast_summary_changes_for_upward_and_mixed_directions(): void
    {
        foreach ([6, 12, 24] as $duration) {
            $this->forecastQuantiles('2026-06-18', $duration, forecastChange: 0.2);
        }

        $service = app(ContractMarketInsightService::class);
        $this->assertSame('up', $service->fixedTermArticle()['forecast']['direction_summary']);

        FixedContractPriceForecast::query()
            ->where('duration_months', 24)
            ->update([
                'forecast_price_cents_per_kwh' => DB::raw('current_price_cents_per_kwh - 0.2'),
                'expected_change_cents_per_kwh' => -0.2,
            ]);
        Cache::flush();

        $this->assertSame('mixed', $service->fixedTermArticle()['forecast']['direction_summary']);
    }

    public function test_article_has_honest_unavailable_states_without_aggregate_data(): void
    {
        $this->get('/sahkosopimus/kannattaako-maaraaikainen')
            ->assertOk()
            ->assertSeeText('Vuosihintojen vertailu ei ole juuri nyt saatavilla')
            ->assertSeeText('Vuosihintojen vertailua ei ole juuri nyt saatavilla')
            ->assertSeeText('Saman päivän energiahintoja ei ole juuri nyt saatavilla')
            ->assertSeeText('Sopimuspituuksia ei voi asettaa hintajärjestykseen ilman saman päivän tietoja')
            ->assertSeeText('Hintahistoriaa ei ole vielä riittävästi')
            ->assertSeeText('30 päivän ennustetta ei ole juuri nyt saatavilla')
            ->assertSeeText('Ei saatavilla')
            ->assertDontSeeText('kelvollinen');
    }

    /** @param list<string> $needles */
    private function assertTextAppearsInOrder(string $text, array $needles): void
    {
        $lastPosition = -1;

        foreach ($needles as $needle) {
            $position = mb_strpos($text, $needle);
            $this->assertNotFalse($position, "Text not found: {$needle}");
            $this->assertGreaterThan($lastPosition, $position, "Text is out of order: {$needle}");
            $lastPosition = $position;
        }
    }

    private function statistic(
        string $date,
        int $duration,
        float $p20,
        float $median,
        float $p80,
        int $contractCount,
        string $pricingBasis = 'canonical_calculation',
    ): void {
        $this->unitStatistic(
            $date,
            "fixed_term_{$duration}",
            $p20,
            $median,
            $p80,
            $contractCount,
            $pricingBasis,
        );
    }

    private function unitStatistic(
        string $date,
        string $segment,
        float $p20,
        float $median,
        float $p80,
        int $contractCount,
        string $pricingBasis = 'canonical_calculation',
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => 'energy_price',
            'pricing_basis' => $pricingBasis,
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            'consumption_kwh' => null,
            'p20_value' => $p20,
            'median_value' => $median,
            'p80_value' => $p80,
            'contract_count' => $contractCount,
        ]);
    }

    private function annualStatistic(
        string $date,
        string $segment,
        float $p20,
        float $median,
        float $p80,
        int $contractCount,
        string $pricingBasis = 'canonical_calculation',
        string $methodVersion = AnnualCostMethodVersion::AsOf->value,
        string $estimateMethod = 'flat',
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => 'annual_cost',
            'pricing_basis' => $pricingBasis,
            'method_version' => $methodVersion,
            'calculation_basis' => $pricingBasis === 'mixed_evidence' ? 'mixed' : 'canonical_outcome',
            'estimate_basis' => $estimateMethod,
            'compatibility_key' => "stored-{$segment}-{$estimateMethod}",
            'basis_counts' => ['estimate_method' => [$estimateMethod => $contractCount]],
            'consumption_kwh' => 5000,
            'p20_value' => $p20,
            'median_value' => $median,
            'p80_value' => $p80,
            'contract_count' => $contractCount,
        ]);
    }

    private function forecastQuantiles(
        string $forecastDate,
        int $duration,
        string $modelVersion = 'article_test_model',
        string $pricingBasis = 'canonical_calculation',
        float $forecastChange = -0.2,
        string $confidence = 'low',
    ): void {
        $prices = [
            'p20' => 8.0,
            'median' => 9.0,
            'p80' => 10.0,
        ];

        foreach ($prices as $quantile => $currentPrice) {
            FixedContractPriceForecast::create([
                'forecast_date' => $forecastDate,
                'target_date' => CarbonImmutable::parse($forecastDate)->addDays(30)->toDateString(),
                'horizon_days' => 30,
                'duration_months' => $duration,
                'target_quantile' => $quantile,
                'current_price_cents_per_kwh' => $currentPrice,
                'forecast_price_cents_per_kwh' => $currentPrice + $forecastChange,
                'expected_change_cents_per_kwh' => $forecastChange,
                'hedge_cost_cents_per_kwh' => 7.0,
                'retail_premium_cents_per_kwh' => 2.0,
                'normal_retail_premium_cents_per_kwh' => 2.1,
                'fair_price_cents_per_kwh' => $currentPrice + 0.3,
                'gap_cents_per_kwh' => 0.3,
                'futures_trade_date' => CarbonImmutable::parse($forecastDate)->subDay()->toDateString(),
                'coverage_quality' => 'all_monthly',
                'confidence' => $confidence,
                'direction' => $forecastChange < 0 ? 'slightly_falling' : 'slightly_rising',
                'consumer_signal' => 'neutral',
                'contract_count' => 25,
                'model_version' => $modelVersion,
                'source_metadata' => ['current_retail_pricing_basis' => $pricingBasis],
            ]);
        }
    }
}
