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

    public function test_article_builds_broad_comparison_and_plain_language_sections_from_aggregate_data(): void
    {
        $this->seedCurrentAndHistory();
        $this->annualStatistic('2026-06-15', 'fixed_term_12', 650, 700, 760, 15, estimateMethods: ['none' => 15]);
        $this->annualStatistic('2026-06-15', 'spot', 560, 620, 690, 25, estimateMethods: ['forward_curve_spot' => 25]);
        $this->annualStatistic('2026-06-15', 'open_ended', 720, 780, 850, 18, estimateMethods: ['supplier_adjusted_forward_curve_shift' => 18]);
        $this->annualStatistic('2026-06-15', 'market_reset', 730, 800, 880, 12, estimateMethods: ['recurring_forward_curve_shift' => 12]);
        $this->annualStatistic('2026-06-15', 'quarterly', 690, 740, 810, 11, estimateMethods: ['recurring_forward_curve_shift' => 11]);
        $this->annualStatistic('2026-06-15', 'fixed_term_6', 680, 730, 790, 10, estimateMethods: ['term_price_annualized' => 10]);
        $this->annualStatistic('2026-06-15', 'fixed_term_24', 660, 710, 770, 13, estimateMethods: ['none' => 13]);
        $this->annualStatistic('2026-06-15', 'hybrid', 500, 551, 620, 30, estimateMethods: ['hybrid_base_only' => 30]);
        foreach ([6, 12, 24] as $duration) {
            $this->forecastQuantiles('2026-06-18', $duration);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $payload = app(ContractMarketInsightService::class)->fixedTermArticle();
        $querySql = implode("\n", $queries);
        $this->assertStringContainsString('contract_price_daily_statistics', $querySql);
        $this->assertStringContainsString('fixed_contract_price_forecasts', $querySql);
        foreach (['electricity_contracts', 'active_contracts', 'price_components', 'contract_price_annual_costs'] as $prohibitedTable) {
            $this->assertStringNotContainsString($prohibitedTable, $querySql);
        }

        $annual = $payload['annual_comparison'];
        $this->assertSame('2026-06-15', $annual['date']);
        $this->assertSame('fixed_term_12', $annual['benchmark_segment_key']);
        $this->assertSame(
            ['fixed_term_12', 'spot', 'open_ended', 'market_reset', 'quarterly', 'fixed_term_6', 'fixed_term_24', 'hybrid'],
            array_column($annual['rows'], 'segment_key'),
        );
        $this->assertContains('hybrid', $annual['chart']['segment_keys']);
        $this->assertSame('complete', $annual['comparisons']['hybrid']['state']);
        $this->assertTrue($annual['comparisons']['hybrid']['consumption_effect_ignored']);
        $this->assertSame(30, $annual['comparisons']['hybrid']['base_only_count']);
        $this->assertSame('alternative_cheaper', $annual['comparisons']['spot']['cheaper_direction']);
        $this->assertEqualsWithDelta(80.0, $annual['comparisons']['spot']['median_difference_eur'], 0.0001);
        $this->assertSame('fixed_12_cheaper', $annual['comparisons']['open_ended']['cheaper_direction']);
        $this->assertEqualsWithDelta(-80.0, $annual['comparisons']['open_ended']['median_difference_eur'], 0.0001);
        $this->assertFalse($annual['comparisons']['spot']['difference_is_small']);

        $response = $this->get('/sahkosopimus/kannattaako-maaraaikainen')->assertOk();
        $response
            ->assertSeeText('Kannattaako määräaikainen sähkösopimus?')
            ->assertSeeText('Määräaikainen ei ole aina halvin, mutta sen energiahinta ei muutu kesken vuoden.')
            ->assertSeeText('Hintaerot jäävät tässä vertailussa enintään 6,67 euroon kuukaudessa.')
            ->assertSeeText('Pörssisähkö on halvempi kuin kiinteä 12 kuukauden sopimus.')
            ->assertSeeText('Mediaanien ero on 80 € vuodessa eli 6,67 € kuukaudessa pörssisähkön hyväksi.')
            ->assertSeeText('Kiinteä 12 kuukauden sopimus on halvempi kuin toistaiseksi voimassa oleva sopimus.')
            ->assertSeeText('Mediaanien ero on 80 € vuodessa eli 6,67 € kuukaudessa kiinteän 12 kuukauden sopimuksen hyväksi.')
            ->assertSeeText('Täysin kiinteähintainen sopimus helpottaa sähkölaskun ennakointia.')
            ->assertSeeText('Miten määräaikainen vertautuu muihin sopimustyyppeihin?')
            ->assertSeeText('Valinta on tasapaino hinnan ja hintariskin välillä.')
            ->assertSeeText('Suurempi hintariski voi tuoda säästöä')
            ->assertSeeText('Se ei kuitenkaan takaa halvempaa sopimusta.')
            ->assertSeeText('Hinta ja hintariski rinnakkain')
            ->assertSeeText('Ennakoitavuus: korkea')
            ->assertSeeText('Ennakoitavuus: kohtalainen')
            ->assertSeeText('Ennakoitavuus: matala')
            ->assertSeeText('Perushinta tunnetaan, mutta kulutusvaikutus jää avoimeksi.')
            ->assertSeeText('Ennakoitavuus jää pörssisähkön ja täysin kiinteän sopimuksen väliin')
            ->assertSeeText('Kulutusvaikutus ei sisälly')
            ->assertSee('aria-label="Sopimustyyppien hinnan ennakoitavuus ja vuosikustannus"', false)
            ->assertSeeText('Määräaikainen vai pörssisähkö?')
            ->assertSeeText('Määräaikainen vai toistaiseksi voimassa oleva sopimus?')
            ->assertSeeText('Määräaikainen vai jaksoittain vaihtuva hinta?')
            ->assertSeeText('Määräaikainen vai kvartaalisähkö?')
            ->assertSeeText('Määräaikainen vai kulutusvaikutussopimus?')
            ->assertSeeText('sähköenergian ja sopimuksen kuukausimaksut')
            ->assertSeeText('Sähkönsiirto ei kuulu vertailuun.')
            ->assertSeeText('6 kuukauden hinta on muutettu 12 kuukauden vertailuarvoksi')
            ->assertSeeText('24 kuukauden sopimuksesta verrataan vain seuraavia 12 kuukautta')
            ->assertSeeText('Vuosikustannus perustuu perushintaan, ja kulutusvaikutus oletetaan nollaksi.')
            ->assertSeeText('551 € vuodessa')
            ->assertDontSeeText('Varmuuden hinta')
            ->assertDontSeeText('jatkuu automaattisesti')
            ->assertDontSeeText('Sopimuksen purkaminen maksaa yleensä')
            ->assertSee('id="fixedAnnualComparisonChart"', false)
            ->assertSee('id="fixedPriceHistoryChart"', false)
            ->assertSee('role="img"', false)
            ->assertSee('aria-describedby="annual-comparison-takeaway"', false)
            ->assertSee('aria-describedby="fixed-history-takeaway"', false)
            ->assertSeeText('Näytä tarkemmat vuosikustannukset')
            ->assertSeeText('Näytä hintahaarukka ja sopimusten määrä')
            ->assertSeeText('Näytä viikoittaiset hinnat')
            ->assertSeeText('Näytä hintahaarukka ja ennusteen taustatiedot')
            ->assertSeeHtml('scope="col"')
            ->assertSeeHtml('scope="row"')
            ->assertSee('responsive: true', false)
            ->assertSee('maintainAspectRatio: false', false)
            ->assertSee('chartInstance.destroy()', false)
            ->assertSee("document.addEventListener('DOMContentLoaded', initFixedTermArticleCharts)", false)
            ->assertSee("document.addEventListener('livewire:navigated', initFixedTermArticleCharts)", false)
            ->assertSee('animation: false', false)
            ->assertSeeText('Markkina- ja ennustetiedot päivitetty')
            ->assertSeeText('18.6.2026')
            ->assertSeeText('Artikkeli tarkistettu')
            ->assertSeeText('31.8.2026')
            ->assertSee('"headline": "Kannattaako määräaikainen sähkösopimus?"', false)
            ->assertSee('"description": "Vertailu siitä, miten täysin kiinteähintainen 12 kuukauden sähkösopimus eroaa pörssisähköstä, vaihtuvista hinnoista ja kulutusvaikutuksesta."', false)
            ->assertSee('"dateModified": "2026-08-31"', false)
            ->assertSee('Vertaa 12 kuukauden kiinteähintaista sähkösopimusta pörssisähköön, vaihtuviin hintoihin ja kulutusvaikutukseen. Katso hinnat, erot ja riskit.', false)
            ->assertSee('href="/sahkosopimus/maaraaikainen"', false)
            ->assertSee('href="/sahkosopimus/maaraaikainen-6-kk"', false)
            ->assertSee('href="/sahkosopimus/maaraaikainen-12-kk"', false)
            ->assertSee('href="/sahkosopimus/maaraaikainen-24-kk"', false);

        $plainText = strip_tags($response->getContent());
        foreach ([
            ['Hintaerot jäävät tässä vertailussa enintään 6,67 euroon kuukaudessa.', 'Pörssisähkö on halvempi kuin kiinteä 12 kuukauden sopimus.', 'summary overview'],
            ['Pörssisähkö on halvempi kuin kiinteä 12 kuukauden sopimus.', 'Mediaanien ero on 80 € vuodessa', 'summary'],
            ['Mediaanin perusteella halvin on kulutusvaikutus.', 'Kulutusvaikutus oletetaan nollaksi.', 'annual comparison'],
            ['Kulutusvaikutus oletetaan nollaksi.', 'Kaaviossa näkyvät vain sopimustyypit', 'annual assumption'],
            ['Matalin mediaanihinta on 24 kuukauden sopimuksilla', 'Alla ovat 6, 12 ja 24 kuukauden', 'current prices'],
            ['Hintakehitys vaihteli sopimusajan mukaan.', 'Kaavio näyttää täysin kiinteiden 6, 12 ja 24 kuukauden', 'history'],
            ['Mediaanihinta laskee hieman kaikissa saatavilla olevissa ennusteissa.', 'Ennuste kertoo mahdollisesta suunnasta.', 'forecast'],
        ] as [$verdict, $detail, $section]) {
            $verdictPosition = mb_strpos($plainText, $verdict);
            $detailPosition = mb_strpos($plainText, $detail);
            $this->assertNotFalse($verdictPosition, "Missing {$section} verdict.");
            $this->assertNotFalse($detailPosition, "Missing {$section} detail.");
            $this->assertLessThan($detailPosition, $verdictPosition, "The {$section} detail appears before its verdict.");
        }

        $blade = file_get_contents(resource_path('views/livewire/article-fixed-term-contract.blade.php'));
        $this->assertIsString($blade);
        $this->assertStringNotContainsString('<svg', $blade);
        $this->assertStringNotContainsString('niceScale', $blade);
        $this->assertStringNotContainsString('preserveAspectRatio', $blade);
        $this->assertStringNotContainsString('vector-effect', $blade);
    }

    public function test_annual_comparison_falls_back_when_newest_core_date_is_invalid(): void
    {
        foreach ([
            ['fixed_term_12', 600, 700, 800],
            ['spot', 500, 620, 750],
            ['open_ended', 650, 740, 820],
        ] as [$segment, $p20, $median, $p80]) {
            $this->annualStatistic('2026-06-20', $segment, $p20, $median, $p80, 8, estimateMethods: ['different_'.$segment => 8]);
            $this->annualStatistic('2026-06-21', $segment, $p20, $median, $p80, 9, estimateMethods: ['new_'.$segment => 9]);
        }
        ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', '2026-06-21')
            ->where('segment_key', 'spot')
            ->update(['p20_value' => 900]);
        Cache::flush();

        $annual = app(ContractMarketInsightService::class)->fixedTermArticle()['annual_comparison'];

        $this->assertSame('2026-06-20', $annual['date']);
        $this->assertSame(8, collect($annual['rows'])->firstWhere('segment_key', 'fixed_term_12')['contract_count']);
        $this->assertSame('alternative_cheaper', $annual['comparisons']['spot']['cheaper_direction']);
    }

    public function test_difference_copy_uses_natural_equal_and_small_difference_sentences(): void
    {
        $this->annualStatistic('2026-06-15', 'fixed_term_12', 650, 700, 760, 15, estimateMethods: ['none' => 15]);
        $this->annualStatistic('2026-06-15', 'spot', 640, 690, 750, 25, estimateMethods: ['forward_curve_spot' => 25]);
        $this->annualStatistic('2026-06-15', 'open_ended', 650, 700, 770, 18, estimateMethods: ['supplier_adjusted_forward_curve_shift' => 18]);

        $content = $this->get('/sahkosopimus/kannattaako-maaraaikainen')
            ->assertOk()
            ->assertSeeText('Kiinteän 12 kuukauden sopimuksen ja pörssisähkön hintaero on pieni.')
            ->assertSeeText('Mediaanien ero on 10 € vuodessa eli 0,83 € kuukaudessa pörssisähkön hyväksi.')
            ->assertSeeText('Kiinteän 12 kuukauden sopimuksen ja toistaiseksi voimassa olevan sopimuksen vuosikustannukset ovat samat.')
            ->assertSeeText('Vuosikustannusten mediaanit ovat samat.')
            ->getContent();

        $plainText = strip_tags($content);
        $smallVerdictPosition = mb_strpos($plainText, 'Kiinteän 12 kuukauden sopimuksen ja pörssisähkön hintaero on pieni.');
        $smallDetailPosition = mb_strpos($plainText, 'Mediaanien ero on 10 € vuodessa');
        $this->assertNotFalse($smallVerdictPosition);
        $this->assertNotFalse($smallDetailPosition);
        $this->assertLessThan($smallDetailPosition, $smallVerdictPosition);
    }

    public function test_market_reset_accepts_small_clean_sample_and_gives_the_verdict_before_details(): void
    {
        $this->seedAnnualCore();
        $this->annualStatistic('2026-06-15', 'market_reset', 620, 680, 760, 4, estimateMethods: ['recurring_forward_curve_shift' => 4]);

        $annual = app(ContractMarketInsightService::class)->fixedTermArticle()['annual_comparison'];
        $reset = collect($annual['rows'])->firstWhere('segment_key', 'market_reset');

        $this->assertNotNull($reset);
        $this->assertTrue($reset['low_sample']);
        $this->assertTrue($annual['comparisons']['market_reset']['low_sample']);
        $this->assertContains('market_reset', $annual['chart']['segment_keys']);

        $plainText = strip_tags($this->get('/sahkosopimus/kannattaako-maaraaikainen')->assertOk()->getContent());
        $headingPosition = mb_strpos($plainText, 'Määräaikainen vai jaksoittain vaihtuva hinta?');
        $verdictPosition = mb_strpos($plainText, 'Kiinteän 12 kuukauden sopimuksen ja jaksoittain vaihtuvan sopimuksen hintaero on pieni.', $headingPosition);
        $detailPosition = mb_strpos($plainText, 'Mediaanien ero on 20 € vuodessa eli 1,67 € kuukaudessa jaksoittain vaihtuvan sopimuksen hyväksi.', $headingPosition);
        $warningPosition = mb_strpos($plainText, 'Mukana on alle 10 sopimusta. Tulos on siksi vain suuntaa antava.', $headingPosition);
        $this->assertNotFalse($headingPosition);
        $this->assertNotFalse($verdictPosition);
        $this->assertNotFalse($detailPosition);
        $this->assertNotFalse($warningPosition);
        $this->assertLessThan($detailPosition, $verdictPosition);
        $this->assertLessThan($warningPosition, $detailPosition);
    }

    public function test_market_reset_with_hybrid_contributor_uses_the_base_price_with_a_disclosure(): void
    {
        $this->seedAnnualCore();
        $this->annualStatistic('2026-06-15', 'market_reset', 500, 551, 620, 12, estimateMethods: [
            'recurring_forward_curve_shift' => 9,
            'hybrid_base_only' => 3,
        ]);

        $annual = app(ContractMarketInsightService::class)->fixedTermArticle()['annual_comparison'];

        $this->assertSame('complete', $annual['comparisons']['market_reset']['state']);
        $this->assertTrue($annual['comparisons']['market_reset']['consumption_effect_ignored']);
        $this->assertSame(9, $annual['comparisons']['market_reset']['complete_estimate_count']);
        $this->assertSame(3, $annual['comparisons']['market_reset']['base_only_count']);
        $this->assertNotNull(collect($annual['rows'])->firstWhere('segment_key', 'market_reset'));
        $this->assertContains('market_reset', $annual['chart']['segment_keys']);
        $this->get('/sahkosopimus/kannattaako-maaraaikainen')
            ->assertOk()
            ->assertSeeText('3 sopimuksen kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi siksi olla suurempi tai pienempi.');
    }

    public function test_quarterly_uses_base_prices_when_consumption_effect_is_unknown(): void
    {
        $this->seedAnnualCore();
        $quarterly = $this->annualStatistic('2026-06-15', 'quarterly', 660, 720, 790, 12, estimateMethods: ['recurring_forward_curve_shift' => 12]);

        $service = app(ContractMarketInsightService::class);
        $clean = $service->fixedTermArticle()['annual_comparison'];
        $this->assertSame('complete', $clean['comparisons']['quarterly']['state']);
        $this->assertContains('quarterly', $clean['chart']['segment_keys']);

        $quarterly->update(['basis_counts' => ['estimate_method' => ['recurring_forward_curve_shift' => 8, 'hybrid_base_only' => 4]]]);
        Cache::flush();
        $incomplete = $service->fixedTermArticle()['annual_comparison'];

        $this->assertSame('complete', $incomplete['comparisons']['quarterly']['state']);
        $this->assertTrue($incomplete['comparisons']['quarterly']['consumption_effect_ignored']);
        $this->assertSame(8, $incomplete['comparisons']['quarterly']['complete_estimate_count']);
        $this->assertSame(4, $incomplete['comparisons']['quarterly']['base_only_count']);
        $this->assertContains('quarterly', $incomplete['chart']['segment_keys']);
        $this->get('/sahkosopimus/kannattaako-maaraaikainen')
            ->assertOk()
            ->assertSeeText('4 sopimuksen kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi siksi olla suurempi tai pienempi.')
            ->assertSeeText('720 € vuodessa');

        $quarterly->update(['basis_counts' => null]);
        Cache::flush();
        $missingMethodEvidence = $service->fixedTermArticle()['annual_comparison'];
        $this->assertSame('unavailable', $missingMethodEvidence['comparisons']['quarterly']['state']);
        $this->assertNotContains('quarterly', $missingMethodEvidence['chart']['segment_keys']);
    }

    public function test_hybrid_uses_the_base_price_distribution_with_an_explicit_zero_effect_assumption(): void
    {
        $this->seedAnnualCore();
        $this->annualStatistic('2026-06-15', 'hybrid', 9800, 9876, 9900, 44, estimateMethods: ['none' => 44]);

        $annual = app(ContractMarketInsightService::class)->fixedTermArticle()['annual_comparison'];

        $this->assertSame('complete', $annual['comparisons']['hybrid']['state']);
        $this->assertTrue($annual['comparisons']['hybrid']['consumption_effect_ignored']);
        $this->assertSame(44, $annual['comparisons']['hybrid']['base_only_count']);
        $this->assertNotNull(collect($annual['rows'])->firstWhere('segment_key', 'hybrid'));
        $this->assertContains('hybrid', $annual['chart']['segment_keys']);
        $this->assertContains(9876.0, $annual['chart']['medians']);
        $this->get('/sahkosopimus/kannattaako-maaraaikainen')
            ->assertOk()
            ->assertSeeText('9 876 € vuodessa')
            ->assertSeeText('Vuosikustannus perustuu perushintaan, ja kulutusvaikutus oletetaan nollaksi.');
    }

    public function test_history_is_bounded_weekly_and_uses_one_median_only_chart_payload(): void
    {
        $this->unitStatistic('2025-05-01', 'fixed_term_6', 2, 3, 4, 20, 'observed_seller_data');
        foreach ([6, 12, 24] as $duration) {
            $segment = "fixed_term_{$duration}";
            $this->unitStatistic('2026-05-04', $segment, 7, 8, 9, 20, 'observed_seller_data');
            $this->unitStatistic('2026-05-04', $segment, 17, 18, 19, 30);
            $this->unitStatistic('2026-05-05', $segment, 19, 20, 21, 40);
        }

        $history = app(ContractMarketInsightService::class)->fixedTermArticle()['history'];
        $sixMonth = collect($history['series'])->firstWhere('duration_months', 6);

        $this->assertSame('2025-05-05', $history['start_date']);
        $this->assertSame('2026-05-05', $history['end_date']);
        $this->assertArrayNotHasKey('ticks', $history);
        $this->assertArrayNotHasKey('scale_min', $history);
        $this->assertSame([6, 12, 24], array_column($history['chart']['datasets'], 'duration_months'));
        $this->assertSame(['6 kk', '12 kk', '24 kk'], array_column($history['chart']['datasets'], 'label'));
        $this->assertEqualsWithDelta(19.0, $sixMonth['points'][0]['median'], 0.0001);
        $this->assertSame(35, $sixMonth['points'][0]['contract_count']);
        foreach ($history['chart']['datasets'] as $dataset) {
            $this->assertArrayHasKey('values', $dataset);
            $this->assertArrayNotHasKey('p20', $dataset);
            $this->assertArrayNotHasKey('p80', $dataset);
        }
    }

    public function test_feature_off_and_empty_data_states_fail_closed_without_breaking_fixed_term_comparison(): void
    {
        foreach ([6, 12, 24] as $duration) {
            $this->unitStatistic('2026-05-20', "fixed_term_{$duration}", 7, 8, 9, 10, 'observed_seller_data');
        }
        $this->annualStatistic('2026-05-20', 'fixed_term_12', 600, 700, 800, 10, pricingBasis: 'observed_seller_data');
        $this->annualStatistic('2026-05-20', 'spot', 500, 600, 700, 10, pricingBasis: 'observed_seller_data');
        $this->annualStatistic('2026-05-20', 'open_ended', 650, 750, 850, 10, pricingBasis: 'observed_seller_data');

        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        Cache::flush();

        $service = app(ContractMarketInsightService::class);
        $article = $service->fixedTermArticle();
        $this->assertSame([], $article['current']);
        $this->assertSame([], $article['annual_comparison']);
        $this->assertSame([6, 12, 24], array_column($service->fixedTermComparison()['rows'], 'duration_months'));

        $this->get('/sahkosopimus/kannattaako-maaraaikainen')
            ->assertOk()
            ->assertSeeText('Vuosikustannusten vertailu ei ole juuri nyt saatavilla')
            ->assertSeeText('Saman päivän energiahintoja ei ole juuri nyt saatavilla')
            ->assertSeeText('30 päivän ennustetta ei ole juuri nyt saatavilla');
    }

    public function test_forecast_keeps_model_basis_and_history_continuity_rules(): void
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

    private function seedAnnualCore(string $date = '2026-06-15'): void
    {
        $this->annualStatistic($date, 'fixed_term_12', 650, 700, 760, 15, estimateMethods: ['none' => 15]);
        $this->annualStatistic($date, 'spot', 560, 620, 690, 25, estimateMethods: ['forward_curve_spot' => 25]);
        $this->annualStatistic($date, 'open_ended', 720, 780, 850, 18, estimateMethods: ['supplier_adjusted_forward_curve_shift' => 18]);
    }

    private function seedCurrentAndHistory(): void
    {
        foreach ([
            'open_ended' => [8.0, 9.0, 10.0, 14],
            'fixed_term_6' => [9.2, 10.2, 11.2, 12],
            'fixed_term_12' => [7.9, 8.9, 9.9, 12],
            'fixed_term_24' => [7.4, 8.4, 9.4, 12],
        ] as $segment => [$p20, $median, $p80, $count]) {
            $this->unitStatistic('2026-06-15', $segment, $p20, $median, $p80, $count);
        }
        foreach ([6, 12, 24] as $duration) {
            $this->unitStatistic('2026-06-08', "fixed_term_{$duration}", 8.0, 9.0, 10.0, 12, 'observed_seller_data');
        }
    }

    private function unitStatistic(
        string $date,
        string $segment,
        float $p20,
        float $median,
        float $p80,
        int $contractCount,
        string $pricingBasis = 'canonical_calculation',
    ): ContractPriceDailyStatistic {
        return ContractPriceDailyStatistic::create([
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

    /** @param array<string,int> $estimateMethods */
    private function annualStatistic(
        string $date,
        string $segment,
        float $p20,
        float $median,
        float $p80,
        int $contractCount,
        string $pricingBasis = 'canonical_calculation',
        string $methodVersion = AnnualCostMethodVersion::AsOf->value,
        array $estimateMethods = ['flat' => 10],
    ): ContractPriceDailyStatistic {
        return ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => 'annual_cost',
            'pricing_basis' => $pricingBasis,
            'method_version' => $methodVersion,
            'calculation_basis' => 'canonical_outcome',
            'estimate_basis' => array_key_first($estimateMethods),
            'compatibility_key' => 'stored-'.$segment,
            'basis_counts' => ['estimate_method' => $estimateMethods],
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
    ): void {
        foreach (['p20' => 8.0, 'median' => 9.0, 'p80' => 10.0] as $quantile => $currentPrice) {
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
                'confidence' => 'low',
                'direction' => $forecastChange < 0 ? 'slightly_falling' : 'slightly_rising',
                'consumer_signal' => 'neutral',
                'contract_count' => 25,
                'model_version' => $modelVersion,
                'source_metadata' => ['current_retail_pricing_basis' => $pricingBasis],
            ]);
        }
    }
}
