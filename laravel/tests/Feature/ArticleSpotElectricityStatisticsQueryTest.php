<?php

namespace Tests\Feature;

use App\Livewire\ArticleContractPriceComparisonChart;
use App\Livewire\ArticleSpotSeasonalityChart;
use App\Livewire\ArticleSpotVolatilityChart;
use App\Livewire\ArticleSpotWinRateChart;
use App\Models\SpotPriceAverage;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleSpotElectricityStatisticsQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-27 12:00:00');
        Cache::flush();
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_article_statistics_widgets_read_only_the_trailing_year_and_required_columns(): void
    {
        $this->seedProductionScaleStatistics();

        DB::enableQueryLog();

        $comparison = app(ArticleContractPriceComparisonChart::class)->preparedData;
        $winRate = app(ArticleSpotWinRateChart::class)->chartData;

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $dataReads = $queries->filter(
            fn (string $query) => str_contains($query, 'from "contract_price_daily_statistics"')
                && (str_contains($query, '"median_value"') || str_contains($query, '"p20_value"')),
        )->values();

        $this->assertCount(2, $dataReads);
        foreach ($dataReads as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query));
            $this->assertStringContainsString('"stat_date" between', $query);
            $this->assertStringContainsString('"pricing_basis"', $query);
            $this->assertStringNotContainsString('"min_value"', $query);
            $this->assertStringNotContainsString('"max_value"', $query);
        }

        $this->assertSame('2025-07-27', $comparison['data_window']['from']);
        $this->assertSame('2026-07-27', $comparison['data_window']['to']);
        $this->assertSame(900.0, $comparison['lead_chart']['series'][0]['values'][array_key_last($comparison['lead_chart']['series'][0]['values'])]);
        $this->assertNotContains(9999.0, $comparison['lead_chart']['series'][0]['values']);

        $this->assertSame('27.7.2026', $winRate['to']);
        $this->assertSame(900.0, $winRate['spot'][array_key_last($winRate['spot'])]);
        $this->assertNotContains(9999.0, $winRate['spot']);

        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        Cache::flush();
        $observedComparison = app(ArticleContractPriceComparisonChart::class)->preparedData;

        $this->assertSame('2026-07-28', $observedComparison['data_window']['to']);
        $this->assertSame(5449.5, $observedComparison['lead_chart']['series'][0]['values'][array_key_last($observedComparison['lead_chart']['series'][0]['values'])]);
    }

    public function test_article_route_renders_with_production_scale_statistics(): void
    {
        $this->seedProductionScaleStatistics();
        Cache::flush();

        $response = $this->get('/sahkosopimus/kannattaako-porssisahko');

        $response->assertOk();
        $response->assertSee('Kannattaako pörssisähkö');
        $response->assertSee('Markkinavertailu 2026');
        $response->assertSee('Aineisto: 27.7.2025–27.7.2026');
        $response->assertSee('pörssisähkösopimusten vuosikustannuksen mediaani');
        $response->assertSee('Markkinan mediaani suosii nyt pörssisähköä.');
        $response->assertSee('Mediaani kuvaa sopimustyypin keskitasoa, mutta yksittäinen sopimus voi olla sitä halvempi tai kalliimpi.');
        $response->assertSee('Yksittäisten sopimusten hinnat vaihtelevat, joten markkinoilta voi löytyä mediaania halvempi kiinteä tai pörssisopimus.');
        $response->assertSee('Markkinoilta voi löytyä sopimustyyppinsä mediaania halvempi kiinteä tai pörssisopimus.');
        $response->assertDontSee('sopimuspari');
        $response->assertSee('Nykyiset vuosikustannukset on laskettu ajantasaisista sopimushintatiedoista samalla menetelmällä.');
        $response->assertSee('Sisältö tarkistettu 29.5.2026.');
        $response->assertDontSee('kanonisia laskelmia');
        $response->assertDontSee('contract-type-comparison', false);
        $response->assertDontSee('Laskuri vertaa valitsemaasi pörssisopimusta');
        $response->assertDontSee('Vertaile pörssisähköä omalla kulutuksellasi');
        $response->assertDontSee('Vertailu ja laskuri 2026');

        $content = $response->getContent();
        $marketPosition = strpos($content, 'Markkinatilanne nyt');
        $shortAnswerPosition = strpos($content, 'Lyhyt vastaus');
        $contentsPosition = strpos($content, 'Tässä artikkelissa');

        $this->assertIsInt($marketPosition);
        $this->assertIsInt($shortAnswerPosition);
        $this->assertIsInt($contentsPosition);
        $this->assertLessThan($shortAnswerPosition, $marketPosition);
        $this->assertLessThan($contentsPosition, $shortAnswerPosition);
    }

    public function test_all_evidence_charts_render_accessible_data_tables(): void
    {
        foreach (['spot' => 700, 'fixed_term_12' => 800, 'fixed_term_24' => 850, 'open_ended' => 900, 'hybrid' => 950] as $segment => $value) {
            $this->statisticRowInsert('2026-07-20', $segment, $value);
            $this->statisticRowInsert('2026-07-27', $segment, $value + 10);
        }

        foreach ([
            ['2026-06-01', 4.5, 5.0, 3.5],
            ['2026-07-01', 9.5, null, 8.0],
        ] as [$date, $average, $day, $night]) {
            SpotPriceAverage::create([
                'region' => 'FI',
                'period_type' => 'monthly',
                'period_start' => $date,
                'period_end' => Carbon::parse($date)->endOfMonth()->toDateString(),
                'avg_price_without_tax' => $average / 1.255,
                'avg_price_with_tax' => $average,
                'day_avg_without_tax' => $day === null ? null : $day / 1.255,
                'day_avg_with_tax' => $day,
                'night_avg_without_tax' => $night / 1.255,
                'night_avg_with_tax' => $night,
                'hours_count' => 720,
            ]);
        }

        foreach ([
            ['2026-07-20 00:00:00', -2.0],
            ['2026-07-20 01:00:00', 5.0],
            ['2026-07-27 00:00:00', 20.0],
        ] as [$dateTime, $price]) {
            DB::table('spot_prices_hour')->insert([
                'region' => 'FI',
                'timestamp' => Carbon::parse($dateTime, 'UTC')->timestamp,
                'utc_datetime' => $dateTime,
                'price_without_tax' => $price,
                'vat_rate' => 0.255,
            ]);
        }

        $this->assertAccessibleChartTable(
            ArticleContractPriceComparisonChart::class,
            'Sähkösopimustyyppien viikoittaiset mediaanivuosikustannukset 5 000 kilowattitunnin kulutuksella.',
            'contract-price-comparison-takeaway',
        )->assertSee('Uusin saatavilla oleva viikkotaso:');

        $this->assertAccessibleChartTable(
            ArticleSpotSeasonalityChart::class,
            'Pörssisähkön kuukausittaiset päivä- ja yöhinnat.',
            'seasonality-takeaway',
        )->assertSee('Aineiston halvin kuukausi oli')
            ->assertSee('–');

        $this->assertAccessibleChartTable(
            ArticleSpotWinRateChart::class,
            'Pörssisähkön ja muiden sopimustyyppien edullisen hintatason päiväkohtainen vertailu.',
            'win-rate-takeaway',
        )->assertSee('Pörssisähkön halvemmat vertailupäivät:');

        $this->assertAccessibleChartTable(
            ArticleSpotVolatilityChart::class,
            'Pörssisähkön tuntihintojen viikoittainen vaihtelu.',
            'volatility-takeaway',
        )->assertSee('Viimeisen vuoden toteutuneet tuntihinnat:');
    }

    public function test_article_route_reports_fixed_median_when_it_is_lower(): void
    {
        $rows = [];

        foreach (['spot' => 900, 'fixed_term_12' => 800, 'open_ended' => 950, 'fixed_term_24' => 850, 'hybrid' => 1000] as $segment => $value) {
            $rows[] = $this->statisticRow('2026-07-27', $segment, $value, 'canonical_calculation');
        }

        DB::table('contract_price_daily_statistics')->insert($rows);

        $response = $this->get('/sahkosopimus/kannattaako-porssisahko');

        $response->assertOk();
        $response->assertSee('Markkinan mediaani suosii nyt kiinteää 12 kuukauden sopimusta.');
        $response->assertSee('kiinteiden 12 kuukauden sopimusten vuosikustannuksen mediaani');
        $response->assertDontSee('Markkinan mediaani suosii nyt pörssisähköä.');
        $response->assertSee('Yksittäisten sopimusten hinnat vaihtelevat, joten markkinoilta voi löytyä mediaania halvempi kiinteä tai pörssisopimus.');
        $response->assertDontSee('sopimuspari');
        $response->assertDontSee('contract-type-comparison', false);
    }

    private function assertAccessibleChartTable(string $component, string $caption, string $takeawayId)
    {
        return Livewire::test($component)
            ->assertSee('Näytä tiedot taulukkona')
            ->assertSee($caption)
            ->assertSeeHtml('<thead>')
            ->assertSeeHtml('scope="col"')
            ->assertSeeHtml('scope="row"')
            ->assertSeeHtml('aria-describedby="'.$takeawayId.'"');
    }

    private function statisticRowInsert(string $date, string $segment, float $value): void
    {
        DB::table('contract_price_daily_statistics')->insert(
            $this->statisticRow($date, $segment, $value, 'canonical_calculation'),
        );
    }

    private function seedProductionScaleStatistics(): void
    {
        $segments = ['spot', 'fixed_term_12', 'fixed_term_24', 'open_ended', 'hybrid'];
        $rows = [];

        foreach (CarbonPeriod::create('2023-01-01', '2026-07-26') as $date) {
            foreach ($segments as $index => $segment) {
                $value = 500 + ($index * 50);
                $rows[] = $this->statisticRow($date->toDateString(), $segment, $value, 'observed_seller_data');

                if (count($rows) === 500) {
                    DB::table('contract_price_daily_statistics')->insert($rows);
                    $rows = [];
                }
            }
        }

        foreach ($segments as $index => $segment) {
            $rows[] = $this->statisticRow('2026-07-27', $segment, 900 + ($index * 50), 'canonical_calculation');
            $rows[] = $this->statisticRow('2026-07-28', $segment, 9999, 'observed_seller_data');
        }

        if ($rows !== []) {
            DB::table('contract_price_daily_statistics')->insert($rows);
        }

        $this->assertGreaterThan(6000, DB::table('contract_price_daily_statistics')->count());
    }

    private function statisticRow(string $date, string $segment, float $value, string $pricingBasis): array
    {
        return [
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => 'annual_cost',
            'pricing_basis' => $pricingBasis,
            'consumption_kwh' => 5000,
            'min_value' => $value,
            'p20_value' => $value,
            'avg_value' => $value,
            'median_value' => $value,
            'p80_value' => $value,
            'max_value' => $value,
            'contract_count' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
