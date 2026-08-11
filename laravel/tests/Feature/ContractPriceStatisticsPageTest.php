<?php

namespace Tests\Feature;

use App\Livewire\ContractPriceStatistics;
use App\Models\ContractPriceDailyStatistic;
use App\Models\SpotPriceAverage;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ContractPriceStatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.enabled', false);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::Legacy->value);
        app()->forgetScopedInstances();
        Cache::flush();
    }

    public function test_page_is_accessible_with_empty_data_and_does_not_leak_admin_instructions(): void
    {
        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('Sähkösopimusten hintakehitys: mitä suomalaiset oikeasti maksavat sähköstä');
        $response->assertSee('Aineiston keruu on käynnissä');
        $response->assertDontSee('php artisan');
    }

    public function test_page_renders_data_when_statistics_exist(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('Sähkösopimusten hintakehitys: mitä suomalaiset oikeasti maksavat sähköstä');
        $response->assertSee('Hinnat sopimustyypeittäin');
        $response->assertSee('Taulukko näyttää kunkin sopimustyypin uusimman saatavilla olevan tyypillisen energiahinnan');
        $response->assertSee('Sopimustyypit, joissa on alle 10 sopimusta, jätetään pois');
        $response->assertSee('Pörssisähkön energiahinta on viimeisen 12 kuukauden toteutunut päiväkeskiarvo + tyypillinen marginaali');
        $response->assertSee('Vaihteluväli näyttää saman 12 kuukauden päivähintojen tavanomaisen vaihtelun');
        $response->assertSee('Trendi näyttää energiahinnan mediaanin kehityksen');
        $response->assertSee('Energiahinnan trendi');
        $response->assertSee('Hintahaarukka');
        $response->assertSee('Taulukko näyttää kunkin sopimustyypin uusimman saatavilla olevan vuosikustannusten jakauman');
        $response->assertSee('Viivat näyttävät kunkin sopimustyypin tyypillisen 12 kuukauden kustannusarvion');
        $response->assertSee('Näin luet kuvaajaa');
        $response->assertSee('Katkos viivassa');
        $response->assertSee('Vertailukelpoista hintaa ei ollut saatavilla tai vuosihinnan arviointitapa muuttui');
        $response->assertSee('Hyppy ei aina tarkoita yleistä hinnanmuutosta');
        $response->assertSee('Viimeinen piste näyttää uusimman saatavilla olevan päivän tilanteen');
        $response->assertDontSee('Yhtenäisellä viivalla olevat pisteet käyttävät samaa vuosiarvion laskutapaa');
        $response->assertDontSee('Halvin');
        $response->assertSee('sisältää energiahinnan sekä perusmaksut 12 kuukaudelta');
        $response->assertSee('Pörssisähkön nykyinen vuosikustannus käyttää tulevan 12 kuukauden tukkumarkkinan ennakkohintoja');
        $response->assertSee('Vuosihinnan trendi');
        $response->assertSee('Sopimusmäärä voi poiketa ylemmästä hintataulukosta');
        $response->assertSee('Sopimustyyppien c/kWh-taulukko ja historialliset kuvaajat näyttävät viimeisen 12 kuukauden toteutuneen päiväkeskiarvon');
        $response->assertSee('Mistä luvut tulevat');
        $response->assertSee('Viittaa tähän');
        $response->assertSee('CC&nbsp;BY&nbsp;4.0', false);
        $response->assertSee('ALV 25,5 %');
    }

    public function test_seller_set_index_payload_render_and_cache_are_consumption_independent(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Cache::flush();
        $this->seedSellerSetIndex('2026-08-11', [8.0, 9.0, 7.0, 6.0], 40, 20);
        $this->seedSellerSetIndex('2026-09-10', [10.0, 11.0, 8.0, 7.0], 44, 22, hybridBase: 5.5);

        $component = Livewire::test(ContractPriceStatistics::class);
        $payload = $component->viewData('sellerSetEnergyPriceIndexPayload');

        $this->assertTrue($payload['has_data']);
        $this->assertSame(10.0, $payload['current']);
        $this->assertSame(8.0, $payload['comparison_value']);
        $this->assertSame(25.0, $payload['delta_30d_pct']);
        $this->assertSame(44, $payload['contract_count']);
        $this->assertSame(22, $payload['supplier_count']);
        $this->assertSame(5.5, $payload['hybrid_base']);
        $this->assertSame(
            ['Kokonaisindeksi', 'Määräaikainen', 'Toistaiseksi voimassa oleva', 'Jaksoittain vaihtuva'],
            array_column($payload['chart']['series'], 'label'),
        );
        $this->assertSame(
            $payload,
            $component->set('consumption', 18000)->viewData('sellerSetEnergyPriceIndexPayload'),
        );

        $component
            ->assertSee('Sähkösopimusten energianhintaindeksi')
            ->assertSee('Pörssisähkö ei ole mukana')
            ->assertSee('vuosikulutuksen valinta vaikuta tähän indeksiin')
            ->assertSee('44')
            ->assertSee('22')
            ->assertSee('Määräaikainen 50 %')
            ->assertSee('Hybridin perushinta, ei mukana indeksissä')
            ->assertSee('Jokainen sähköyhtiö saa sopimusryhmän sisällä saman painon')
            ->assertSee('Pörssi-, aika- ja kausisähkö, energiapaketit ja kulutusvaikutussopimukset eivät ole mukana.');

        $html = $component->html();
        $this->assertLessThan(
            strpos($html, 'Vuosikustannus'),
            strpos($html, 'Sähkösopimusten energianhintaindeksi'),
            'The seller-set index must appear before the annual-cost chart.',
        );

        $this->get('/sahkosopimus/tilastot')->assertSee('10,00');
        ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->where('segment_key', SellerSetEnergyPriceIndexService::SEGMENT_OVERALL)
            ->whereDate('stat_date', '2026-09-10')
            ->update(['avg_value' => 12.0, 'updated_at' => now()->addMinute()]);
        $this->get('/sahkosopimus/tilastot')->assertSee('12,00');
    }

    public function test_seller_set_index_30_day_change_requires_the_exact_date_and_avg_value(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Cache::flush();
        $this->seedSellerSetIndex('2026-08-12', [8.0, 9.0, 7.0, 6.0], 40, 20);
        $this->seedSellerSetIndex('2026-09-10', [10.0, 11.0, 8.0, 7.0], 44, 22);
        ContractPriceDailyStatistic::query()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->update(['median_value' => 999.0]);

        $payload = Livewire::test(ContractPriceStatistics::class)
            ->viewData('sellerSetEnergyPriceIndexPayload');

        $this->assertSame(10.0, $payload['current']);
        $this->assertNull($payload['comparison_value']);
        $this->assertNull($payload['delta_30d_pct']);
        $this->assertNull($payload['comparison_date']);
    }

    public function test_seller_set_index_respects_period_grouping_and_draws_missing_daily_dates_as_gaps(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Cache::flush();
        $this->seedSellerSetIndex('2026-08-11', [8.0, 9.0, 7.0, 6.0], 40, 20);
        $this->seedSellerSetIndex('2026-08-13', [10.0, 11.0, 9.0, 8.0], 44, 22);

        $daily = Livewire::test(ContractPriceStatistics::class)
            ->set('period', 'daily')
            ->viewData('sellerSetEnergyPriceIndexPayload');

        $this->assertCount(3, $daily['chart']['x']);
        $this->assertSame([8.0, null, 10.0], $daily['chart']['series'][0]['values']);
        $this->assertFalse($daily['chart']['showPoints']);
        $this->assertTrue($daily['chart']['showLatestPoint']);

        $weekly = Livewire::test(ContractPriceStatistics::class)
            ->set('period', 'weekly')
            ->viewData('sellerSetEnergyPriceIndexPayload');

        $this->assertCount(1, $weekly['chart']['x']);
        $this->assertSame([9.0], $weekly['chart']['series'][0]['values']);
        $this->assertTrue($weekly['chart']['showPoints']);
        $this->assertFalse($weekly['chart']['showLatestPoint']);
    }

    public function test_seller_set_index_is_hidden_when_canonical_pricing_is_disabled(): void
    {
        $this->seedSellerSetIndex('2026-08-11', [8.0, 9.0, 7.0, 6.0], 40, 20);

        $payload = Livewire::test(ContractPriceStatistics::class)
            ->viewData('sellerSetEnergyPriceIndexPayload');

        $this->assertFalse($payload['has_data']);
        $this->assertSame([], $payload['chart']['x']);
    }

    public function test_reset_category_stays_hidden_until_it_has_30_current_observations(): void
    {
        $this->seedSampleStatistics();
        $this->seedMarketResetStatistics(8);

        $component = Livewire::test(ContractPriceStatistics::class);

        $this->assertNotContains('market_reset', array_column($component->viewData('deepDivePayloads'), 'segment_key'));
        $this->assertNotContains('market_reset', array_column($component->viewData('segmentRows'), 'segment_key'));
        $this->assertNotContains('market_reset', array_column($component->viewData('consumptionRows'), 'segment_key'));
        $component
            ->assertDontSee('Jaksoittain vaihtuvien sähkösopimusten hintakehitys')
            ->assertDontSee('id="paivittyva-hinta"', false);
    }

    public function test_reset_category_returns_with_clear_copy_after_30_current_observations(): void
    {
        $this->seedSampleStatistics();
        $this->seedMarketResetStatistics(30);

        $component = Livewire::test(ContractPriceStatistics::class);

        $this->assertContains('market_reset', array_column($component->viewData('deepDivePayloads'), 'segment_key'));
        $this->assertContains('market_reset', array_column($component->viewData('segmentRows'), 'segment_key'));
        $this->assertContains('market_reset', array_column($component->viewData('consumptionRows'), 'segment_key'));
        $component
            ->assertSee('Jaksoittain vaihtuvien sähkösopimusten hintakehitys')
            ->assertSee('id="paivittyva-hinta"', false)
            ->assertSee('energian hinta pysyy samana yhden jakson ajan')
            ->assertDontSee('Kvartaalisähkösopimusten hintakehitys');
    }

    public function test_dated_quarterly_history_remains_as_its_own_deep_dive(): void
    {
        $this->seedSampleStatistics();
        $this->seedDatedQuarterlyTrend(30);

        $component = Livewire::test(ContractPriceStatistics::class);
        $quarterly = collect($component->viewData('deepDivePayloads'))->firstWhere('segment_key', 'quarterly');

        $this->assertNotNull($quarterly);
        $this->assertFalse($quarterly['is_current']);
        $this->assertNull($quarterly['contract_count']);
        $this->assertSame('2026-04-20', $quarterly['latest_observation_date']);

        $segmentRow = collect($component->viewData('segmentRows'))->firstWhere('segment_key', 'quarterly');
        $consumptionRow = collect($component->viewData('consumptionRows'))->firstWhere('segment_key', 'quarterly');
        $this->assertNotNull($segmentRow);
        $this->assertNotNull($consumptionRow);
        $this->assertFalse($segmentRow['is_current']);
        $this->assertFalse($consumptionRow['is_current']);
        $this->assertSame('2026-04-20', $segmentRow['latest_observation_date']);
        $this->assertSame('2026-04-20', $consumptionRow['latest_observation_date']);
        $this->assertSame(15, $segmentRow['contract_count']);
        $this->assertSame(15, $consumptionRow['contract_count']);
        $component
            ->assertSee('Kvartaalisähkösopimusten hintakehitys')
            ->assertSee('id="kvartaalisahko"', false)
            ->assertSee('Aineisto 20.4.2026 asti')
            ->assertSee('Viimeinen havainto');
    }

    public function test_current_quarterly_rows_continue_the_own_category_after_a_visible_data_gap(): void
    {
        $this->seedSampleStatistics();
        $this->seedDatedQuarterlyTrend(30);
        $this->seedQuarterlyStatistics(contractCount: 15);

        $component = Livewire::test(ContractPriceStatistics::class)->set('period', 'daily');
        $quarterly = collect($component->viewData('deepDivePayloads'))->firstWhere('segment_key', 'quarterly');

        $this->assertNotNull($quarterly);
        $this->assertTrue($quarterly['is_current']);
        $this->assertSame(15, $quarterly['contract_count']);
        $this->assertContains(null, $quarterly['chart']['series'][0]['values']);
        $this->assertContains('quarterly', array_column($component->viewData('segmentRows'), 'segment_key'));
        $this->assertContains('quarterly', array_column($component->viewData('consumptionRows'), 'segment_key'));
    }

    public function test_period_switcher_has_loading_state(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('wire:loading.delay.flex wire:target="setPeriod"', false);
        $response->assertSee('Päivitetään jaksoa');
        $response->assertSee('Päivitetään sopimustyyppejä');
    }

    public function test_consumption_switcher_has_loading_state(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('wire:loading.delay.flex wire:target="setConsumption"', false);
        $response->assertSee('Päivitetään kulutusta');
        $response->assertSee('wire:target="setPeriod,setConsumption"', false);
    }

    public function test_prepared_statistics_payload_is_cached_between_requests(): void
    {
        Cache::flush();
        $this->seedSampleStatistics();

        $this->get('/sahkosopimus/tilastot')->assertStatus(200);

        DB::enableQueryLog();
        $this->get('/sahkosopimus/tilastot')->assertStatus(200);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $fullStatisticReads = $queries->filter(
            fn (string $query) => preg_match('/select\s+\*\s+from\s+[`"]?contract_price_daily_statistics[`"]?/i', $query) === 1,
        );

        $this->assertCount(0, $fullStatisticReads, 'The second request should reuse cached view data instead of loading every daily statistic row.');
    }

    public function test_queued_warm_reuses_one_daily_statistics_collection(): void
    {
        Cache::flush();
        $this->seedSampleStatistics();

        DB::enableQueryLog();

        /** @var ContractPriceStatistics $component */
        $component = app(ContractPriceStatistics::class);
        $component->warmPreparedViewDataCache();

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $dailyStatisticReads = $queries->filter(
            fn (string $query) => preg_match('/select\s+[`"]?stat_date[`"]?.*from\s+[`"]?contract_price_daily_statistics[`"]?/is', $query) === 1,
        );

        $this->assertCount(1, $dailyStatisticReads, 'A direct queued warm should hydrate the daily statistics collection only once.');
    }

    public function test_queued_warm_batches_spot_market_and_latest_statistic_lookups(): void
    {
        Cache::flush();
        $this->seedSampleStatistics();
        $this->seedRisingSpotYearlyAverages();

        DB::enableQueryLog();

        /** @var ContractPriceStatistics $component */
        $component = app(ContractPriceStatistics::class);
        $component->warmPreparedViewDataCache();

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $spotAverageReads = $queries->filter(
            fn (string $query) => str_contains($query, 'from "spot_price_averages"')
                && str_contains($query, '"avg_price_with_tax"'),
        );
        $latestStatisticLookups = $queries->filter(
            fn (string $query) => preg_match('/from\s+[`"]?contract_price_daily_statistics[`"]?\s+where\s+[`"]?segment_key[`"]?\s+=/i', $query) === 1,
        );

        $this->assertLessThanOrEqual(1, $spotAverageReads->count(), 'Spot daily averages should be loaded once and sliced in memory for rolling 12-month windows.');
        $this->assertCount(0, $latestStatisticLookups, 'Latest per-segment rows should come from the already-loaded daily statistics collection.');
    }

    public function test_payload_build_indexes_daily_rows_once_for_reused_slices(): void
    {
        $rows = collect();
        $segments = ['spot', 'fixed_term_12', 'open_ended'];

        foreach (range(0, 39) as $day) {
            $date = Carbon::parse('2026-06-01')->addDays($day)->toDateString();

            foreach ($segments as $segment) {
                foreach (['energy_price', 'monthly_fee'] as $metric) {
                    $rows->push(new CountingContractPriceDailyStatistic([
                        'stat_date' => $date,
                        'segment_key' => $segment,
                        'metric_key' => $metric,
                        'pricing_basis' => 'observed_seller_data',
                        'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
                        'consumption_kwh' => null,
                        'p20_value' => 5.0,
                        'median_value' => 6.0,
                        'p80_value' => 7.0,
                        'contract_count' => 20,
                    ]));
                }

                $rows->push(new CountingContractPriceDailyStatistic([
                    'stat_date' => $date,
                    'segment_key' => $segment,
                    'metric_key' => 'annual_cost',
                    'pricing_basis' => 'observed_seller_data',
                    'method_version' => AnnualCostMethodVersion::Legacy->value,
                    'consumption_kwh' => 5000,
                    'p20_value' => 500.0,
                    'median_value' => 600.0,
                    'p80_value' => 700.0,
                    'contract_count' => 20,
                ]));
            }

            foreach (['spot_margin', 'spot_total_energy_price'] as $metric) {
                $rows->push(new CountingContractPriceDailyStatistic([
                    'stat_date' => $date,
                    'segment_key' => 'spot',
                    'metric_key' => $metric,
                    'pricing_basis' => 'observed_seller_data',
                    'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
                    'consumption_kwh' => null,
                    'p20_value' => 5.0,
                    'median_value' => 6.0,
                    'p80_value' => 7.0,
                    'contract_count' => 20,
                ]));
            }
        }

        CountingContractPriceDailyStatistic::resetReadCounts();

        $component = new class($rows) extends ContractPriceStatistics
        {
            /** @param Collection<int, CountingContractPriceDailyStatistic> $rows */
            public function __construct(private readonly Collection $rows) {}

            public function getDailyStatsProperty(): Collection
            {
                return $this->rows;
            }
        };

        $component->getDeepDivePayloadsProperty();
        $component->getSegmentRowsProperty();
        $component->getConsumptionRowsProperty();
        $component->getCalloutsProperty();

        $rowCount = $rows->count();
        $this->assertLessThanOrEqual($rowCount * 2, CountingContractPriceDailyStatistic::$readCounts['segment_key']);
        $this->assertLessThanOrEqual($rowCount * 2, CountingContractPriceDailyStatistic::$readCounts['metric_key']);
        $this->assertLessThanOrEqual($rowCount * 2, CountingContractPriceDailyStatistic::$readCounts['consumption_kwh']);
    }

    public function test_cached_statistics_payload_invalidates_when_daily_statistics_change(): void
    {
        Cache::flush();
        $this->seedSampleStatistics();

        $initialResponse = $this->get('/sahkosopimus/tilastot');
        $initialResponse->assertStatus(200);
        $this->assertDoesNotMatchRegularExpression('/<span[^>]*>\s*Kvartaalisähkö\s*<\/span>/u', $initialResponse->getContent());

        $this->seedQuarterlyStatistics(contractCount: 10);

        $updatedResponse = $this->get('/sahkosopimus/tilastot');
        $updatedResponse->assertStatus(200);
        $this->assertMatchesRegularExpression('/<span[^>]*>\s*Kvartaalisähkö\s*<\/span>/u', $updatedResponse->getContent());
    }

    public function test_tables_hide_segments_with_fewer_than_ten_contracts(): void
    {
        $this->seedSampleStatistics();

        $this->seedQuarterlyStatistics(contractCount: 9);

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $this->assertDoesNotMatchRegularExpression('/<span[^>]*>\s*Kvartaalisähkö\s*<\/span>/u', $response->getContent());
        $response->assertSee('Pörssisähkö');
    }

    public function test_jsonld_dataset_is_emitted_with_csv_distribution(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertSee('"@type": "Dataset"', false);
        $response->assertSee('"DataDownload"', false);
        $response->assertSee('creativecommons.org/licenses/by/4.0', false);
        $response->assertSee('/sahkosopimus/tilastot.csv', false);
    }

    public function test_consumption_query_param_persists(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot?kulutus=18000');

        $response->assertStatus(200);
        $response->assertSee('aria-pressed="true"', false);
        $response->assertSee('18&nbsp;000&nbsp;kWh', false);
        $response->assertSee('Vuosikustannus 18 000&nbsp;kWh kulutuksella', false);
    }

    public function test_deep_dive_spot_comparisons_use_annual_cost_not_current_cents_per_kwh(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('Vs. pörssisähkö, 5 000 kWh/v');
        $response->assertSee('€/v vs.', false);
        $response->assertDontSee('c/kWh vs.', false);
    }

    public function test_segment_table_shows_spot_as_12_month_average_with_daily_p20_p80_range(): void
    {
        $this->seedSampleStatistics();

        foreach ([
            ['date' => '2026-04-21', 'price' => 4.0],
            ['date' => '2026-04-22', 'price' => 8.0],
            ['date' => '2026-04-23', 'price' => 12.0],
        ] as $row) {
            SpotPriceAverage::create([
                'region' => 'FI',
                'period_type' => SpotPriceAverage::PERIOD_DAILY,
                'period_start' => $row['date'],
                'period_end' => $row['date'],
                'avg_price_without_tax' => $row['price'],
                'avg_price_with_tax' => $row['price'],
                'hours_count' => 24,
            ]);
        }

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('12 kk keskihinta + marginaali');
        $response->assertDontSee('P20–P80:');
    }

    public function test_lead_chart_caption_uses_annual_cost_trend_not_current_spot_cents(): void
    {
        $this->seedCaptionMismatchStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('Pörssisähkö-sopimusten vuosikustannus on noussut 14 % aineiston alusta.');
        $response->assertDontSee('Pörssisähkö-sopimukset ovat halventuneet');
    }

    public function test_spot_callout_uses_same_12_month_average_basis_as_spot_deep_dive(): void
    {
        $this->seedCaptionMismatchStatistics();
        $this->seedRisingSpotYearlyAverages();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertDontSee('Nyt 2,00&nbsp;c/kWh', false);
        $response->assertDontSee('−80 % aineiston alusta');
        $this->assertMatchesRegularExpression('/energiahinta on noussut.*24(?: |\x{00A0})%.*aineiston alusta/su', $response->getContent());
    }

    public function test_page_and_prepared_payload_identify_canonical_latest_values(): void
    {
        config()->set('canonical_pricing.enabled', true);
        Cache::flush();
        $this->seedSampleStatistics();
        $latestDate = ContractPriceDailyStatistic::query()->orderByDesc('stat_date')->firstOrFail()->stat_date->toDateString();
        $updated = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $latestDate)
            ->update(['pricing_basis' => 'canonical_calculation']);
        $this->assertGreaterThan(0, $updated);

        $component = Livewire::test(ContractPriceStatistics::class);

        $component->assertSee('Uusin piste perustuu nyt myynnissä olevien sopimusten ehtoihin');
        $this->assertSame('canonical_calculation', $component->viewData('latestPricingBasis'));
        $this->assertSame('canonical_calculation', $component->viewData('segmentRows')[0]['pricing_basis']);
    }

    public function test_current_statistics_do_not_fall_back_to_a_newer_wrong_basis_date(): void
    {
        config()->set('canonical_pricing.enabled', true);
        Cache::flush();
        $this->seedSampleStatistics();
        $latestDate = Carbon::parse(ContractPriceDailyStatistic::query()->max('stat_date'))->toDateString();
        ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $latestDate)
            ->update(['pricing_basis' => 'canonical_calculation']);

        ContractPriceDailyStatistic::create([
            'stat_date' => Carbon::parse($latestDate)->addDay(),
            'segment_key' => 'spot',
            'metric_key' => 'annual_cost',
            'pricing_basis' => 'observed_seller_data',
            'consumption_kwh' => 5000,
            'min_value' => 9999,
            'p20_value' => 9999,
            'avg_value' => 9999,
            'median_value' => 9999,
            'p80_value' => 9999,
            'max_value' => 9999,
            'contract_count' => 20,
        ]);

        $component = Livewire::test(ContractPriceStatistics::class);

        $this->assertSame('canonical_calculation', $component->viewData('latestPricingBasis'));
        $this->assertNotContains(9999.0, $component->viewData('leadChartPayload')['series'][0]['values']);
    }

    public function test_prepared_cache_key_uses_the_new_provenance_schema_basis_and_annual_method(): void
    {
        config()->set('canonical_pricing.enabled', false);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::Legacy->value);
        app()->forgetScopedInstances();
        $component = app(ContractPriceStatistics::class);
        $method = new \ReflectionMethod($component, 'statisticsViewDataCacheKey');
        $legacyKey = $method->invoke($component);

        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $canonicalKey = $method->invoke(app(ContractPriceStatistics::class));

        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);
        app()->forgetScopedInstances();
        $asOfKey = $method->invoke(app(ContractPriceStatistics::class));

        $this->assertStringStartsWith('contract-price-statistics:view-data:v19:', $legacyKey);
        $this->assertStringStartsWith('contract-price-statistics:view-data:v19:', $canonicalKey);
        $this->assertStringStartsWith('contract-price-statistics:view-data:v19:', $asOfKey);
        $this->assertNotSame($legacyKey, $canonicalKey);
        $this->assertNotSame($canonicalKey, $asOfKey);
    }

    public function test_active_as_of_annual_row_can_use_mixed_evidence_on_latest_unit_date(): void
    {
        $this->seedSampleStatistics();
        $latestDate = Carbon::parse(ContractPriceDailyStatistic::unitStatistics()->max('stat_date'))->toDateString();
        ContractPriceDailyStatistic::create([
            ...$this->statisticValues($latestDate, 'spot', 'annual_cost', 777.0, 20),
            'pricing_basis' => 'mixed_evidence',
            'method_version' => AnnualCostMethodVersion::AsOf->value,
            'calculation_basis' => 'mixed',
            'estimate_basis' => 'mixed',
            'compatibility_key' => 'as-of-current',
            'consumption_kwh' => 5000,
        ]);

        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);
        Cache::flush();
        $component = Livewire::test(ContractPriceStatistics::class);

        $spot = collect($component->viewData('consumptionRows'))->firstWhere('segment_key', 'spot');
        $this->assertSame(777.0, $spot['median']);
        $this->assertSame('mixed_evidence', $spot['pricing_basis']);
        $this->assertNotEmpty($component->viewData('segmentRows'), 'Unit c/kWh rows must remain visible with the AsOf annual method.');
    }

    public function test_daily_and_period_boundary_transitions_create_one_chart_gap(): void
    {
        Cache::flush();

        foreach ([
            ['2026-05-04', 100.0, 'key-a'],
            ['2026-05-05', 110.0, 'key-a'],
            ['2026-05-11', 200.0, 'key-b'],
            ['2026-05-12', 210.0, 'key-b'],
            ['2026-05-18', 220.0, 'key-b'],
        ] as [$date, $value, $key]) {
            ContractPriceDailyStatistic::create([
                ...$this->statisticValues($date, 'spot', 'annual_cost', $value, 20),
                'method_version' => AnnualCostMethodVersion::AsOf->value,
                'compatibility_key' => $key,
                'consumption_kwh' => 5000,
            ]);
        }
        ContractPriceDailyStatistic::create([
            ...$this->statisticValues('2026-05-18', 'spot', 'energy_price', 8.0, 20),
            'consumption_kwh' => null,
        ]);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);

        $daily = Livewire::test(ContractPriceStatistics::class)
            ->set('period', 'daily')
            ->viewData('leadChartPayload');
        $this->assertSame([100.0, 110.0, null, 210.0, 220.0], $daily['series'][0]['values']);

        $weekly = Livewire::test(ContractPriceStatistics::class)->viewData('leadChartPayload');
        $this->assertSame([105.0, null, 220.0], $weekly['series'][0]['values']);
    }

    public function test_dominant_display_regimes_keep_weekly_medians_and_show_the_latest_day_after_a_mixed_week(): void
    {
        Cache::flush();
        config()->set('canonical_pricing.enabled', true);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);
        app()->forgetScopedInstances();

        $rows = [
            ['2026-07-27', 'hybrid', 550.0, 'hybrid-a', ['hybrid_base_only' => 37, 'none' => 1]],
            ['2026-07-28', 'hybrid', 552.0, 'hybrid-b', ['hybrid_base_only' => 38]],
            ['2026-07-27', 'open_ended', 630.0, 'open-a', ['hold_current_supplier_price' => 40, 'supplier_adjusted_spot_seasonal_index' => 2]],
            ['2026-07-28', 'open_ended', 632.0, 'open-b', ['hold_current_supplier_price' => 41]],
        ];

        foreach (Carbon::parse('2026-08-03')->daysUntil('2026-08-09') as $index => $date) {
            $rows[] = [
                $date->toDateString(),
                'hybrid',
                553.0 + $index,
                'hybrid-current-'.$index,
                $index % 2 === 0
                    ? ['hybrid_base_only' => 37, 'none' => 1]
                    : ['hybrid_base_only' => 38],
            ];
            $supplierDominates = $date->gte(Carbon::parse('2026-08-08'));
            $rows[] = [
                $date->toDateString(),
                'open_ended',
                $date->isSameDay('2026-08-09') ? 642.93 : 633.0 + $index,
                'open-current-'.$index,
                $supplierDominates
                    ? ['supplier_adjusted_spot_seasonal_index' => 32, 'hold_current_supplier_price' => 10]
                    : ['hold_current_supplier_price' => 40, 'supplier_adjusted_spot_seasonal_index' => 2],
            ];
        }

        foreach ($rows as [$date, $segment, $value, $storedKey, $estimateMethods]) {
            ContractPriceDailyStatistic::create([
                ...$this->statisticValues($date, $segment, 'annual_cost', $value, $segment === 'hybrid' ? 38 : 42),
                'pricing_basis' => 'canonical_calculation',
                'method_version' => AnnualCostMethodVersion::AsOf->value,
                'compatibility_key' => $storedKey,
                'basis_counts' => ['estimate_method' => $estimateMethods],
                'consumption_kwh' => 5000,
            ]);
        }
        ContractPriceDailyStatistic::create([
            ...$this->statisticValues('2026-08-09', 'spot', 'energy_price', 8.0, 20),
            'pricing_basis' => 'canonical_calculation',
            'consumption_kwh' => null,
        ]);

        $component = Livewire::test(ContractPriceStatistics::class);
        $chart = $component->viewData('leadChartPayload');
        $hybrid = collect($chart['series'])->firstWhere('label', 'Joustosähkö');
        $openEnded = collect($chart['series'])->firstWhere('label', 'Toistaiseksi voimassa oleva');
        $previousWeek = array_search(Carbon::parse('2026-07-27')->startOfWeek()->getTimestamp(), $chart['x'], true);
        $currentWeek = array_search(Carbon::parse('2026-08-03')->startOfWeek()->getTimestamp(), $chart['x'], true);
        $latestDay = array_search(Carbon::parse('2026-08-09')->getTimestamp(), $chart['x'], true);

        $this->assertIsInt($previousWeek);
        $this->assertIsInt($currentWeek);
        $this->assertIsInt($latestDay);
        $this->assertTrue($chart['showPoints']);
        $this->assertFalse($chart['showLatestPoint']);
        $this->assertSame(551.0, $hybrid['values'][$previousWeek]);
        $this->assertSame(556.0, $hybrid['values'][$currentWeek]);
        $this->assertNull($openEnded['values'][$currentWeek]);
        $this->assertSame(642.93, $openEnded['values'][$latestDay]);
        $this->assertNotNull($openEnded['compatibility_keys'][$latestDay]);
        $this->assertNotEmpty($component->viewData('caption'));

        $daily = Livewire::test(ContractPriceStatistics::class)
            ->set('period', 'daily')
            ->viewData('leadChartPayload');
        $this->assertFalse($daily['showPoints']);
        $this->assertTrue($daily['showLatestPoint']);
    }

    public function test_annual_periods_and_captions_do_not_cross_compatibility_keys(): void
    {
        Cache::flush();

        foreach ([
            ['2026-05-04', 100.0, 'key-a'],
            ['2026-05-05', 110.0, 'key-b'],
            ['2026-06-01', 120.0, 'key-a'],
            ['2026-07-06', 180.0, 'key-b'],
        ] as [$date, $value, $key]) {
            ContractPriceDailyStatistic::create([
                ...$this->statisticValues($date, 'spot', 'annual_cost', $value, 20),
                'method_version' => AnnualCostMethodVersion::AsOf->value,
                'compatibility_key' => $key,
                'consumption_kwh' => 5000,
            ]);
        }
        ContractPriceDailyStatistic::create([
            ...$this->statisticValues('2026-07-06', 'spot', 'energy_price', 8.0, 20),
            'consumption_kwh' => null,
        ]);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);

        $weekly = Livewire::test(ContractPriceStatistics::class)->viewData('leadChartPayload');
        $this->assertNull($weekly['series'][0]['values'][0]);
        $this->assertSame([], Livewire::test(ContractPriceStatistics::class)->viewData('caption'));

        $monthly = Livewire::test(ContractPriceStatistics::class)
            ->set('period', 'monthly')
            ->viewData('leadChartPayload');
        $this->assertNull($monthly['series'][0]['values'][0]);
    }

    public function test_csv_endpoint_streams_with_attribution_header_lines(): void
    {
        $this->seedSampleStatistics();
        ContractPriceDailyStatistic::create([
            ...$this->statisticValues('2026-04-29', 'spot', 'annual_cost', 777.0, 20),
            'method_version' => AnnualCostMethodVersion::AsOf->value,
            'calculation_basis' => 'canonical_outcome',
            'estimate_basis' => 'forward_curve',
            'compatibility_key' => 'as-of-audit',
            'basis_counts' => ['forward_curve' => 20],
            'consumption_kwh' => 5000,
        ]);

        $response = $this->get('/sahkosopimus/tilastot.csv');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('Voltikka', $body);
        $this->assertStringContainsString('CC BY 4.0', $body);
        $this->assertStringContainsString('arvonlisäveron 25,5 %', $body);
        $this->assertStringContainsString('pricing_basis=canonical_calculation', $body);
        $this->assertStringContainsString('kaikki menetelmäversiot auditointia varten', $body);
        $this->assertStringContainsString(SellerSetEnergyPriceIndexService::METRIC_KEY.': avg on myyjien suoran yleissähköhinnan indeksi', $body);
        $this->assertStringContainsString('segment_key,metric_key,pricing_basis,method_version,calculation_basis,estimate_basis,compatibility_key,basis_counts,is_active_annual_method', $body);
        $this->assertStringContainsString('spot,annual_cost,observed_seller_data,annual_cost_legacy_v1', $body);
        $this->assertStringContainsString('spot,annual_cost,observed_seller_data,annual_cost_as_of_v1,canonical_outcome,forward_curve,as-of-audit', $body);
        $this->assertStringContainsString('spot,energy_price,observed_seller_data,unit_statistics_v1', $body);
        $this->assertStringContainsString('annual_cost_legacy_v1,,,,,1,5000', $body);
        $this->assertStringContainsString('forward_curve"":20}",0,5000', $body);
    }

    /**
     * @param  array{0:float,1:float,2:float,3:float}  $values Overall, fixed, open-ended, market-reset.
     */
    private function seedSellerSetIndex(
        string $date,
        array $values,
        int $contractCount,
        int $supplierCount,
        ?float $hybridBase = null,
    ): void {
        $segments = [
            SellerSetEnergyPriceIndexService::SEGMENT_OVERALL,
            SellerSetEnergyPriceIndexService::SEGMENT_FIXED_TERM,
            SellerSetEnergyPriceIndexService::SEGMENT_OPEN_ENDED,
            SellerSetEnergyPriceIndexService::SEGMENT_MARKET_RESET,
        ];

        foreach ($segments as $index => $segment) {
            ContractPriceDailyStatistic::create([
                'stat_date' => $date,
                'segment_key' => $segment,
                'metric_key' => SellerSetEnergyPriceIndexService::METRIC_KEY,
                'pricing_basis' => 'canonical_calculation',
                'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
                'calculation_basis' => SellerSetEnergyPriceIndexService::CALCULATION_BASIS,
                'estimate_basis' => SellerSetEnergyPriceIndexService::ESTIMATE_BASIS,
                'compatibility_key' => SellerSetEnergyPriceIndexService::COMPATIBILITY_KEY,
                'basis_counts' => [
                    'contract_count' => $contractCount,
                    'supplier_count' => $supplierCount,
                    'family_weights' => SellerSetEnergyPriceIndexService::FAMILY_WEIGHTS,
                ],
                'consumption_kwh' => null,
                'avg_value' => $values[$index],
                'contract_count' => $contractCount,
            ]);
        }

        if ($hybridBase !== null) {
            ContractPriceDailyStatistic::create([
                'stat_date' => $date,
                'segment_key' => SellerSetEnergyPriceIndexService::SEGMENT_HYBRID_BASE,
                'metric_key' => SellerSetEnergyPriceIndexService::METRIC_KEY,
                'pricing_basis' => 'canonical_calculation',
                'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
                'calculation_basis' => SellerSetEnergyPriceIndexService::CALCULATION_BASIS,
                'estimate_basis' => SellerSetEnergyPriceIndexService::ESTIMATE_BASIS,
                'compatibility_key' => SellerSetEnergyPriceIndexService::COMPATIBILITY_KEY,
                'basis_counts' => [
                    'contract_count' => 5,
                    'supplier_count' => 3,
                    'family_weights' => SellerSetEnergyPriceIndexService::FAMILY_WEIGHTS,
                ],
                'consumption_kwh' => null,
                'avg_value' => $hybridBase,
                'contract_count' => 5,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function statisticValues(string $date, string $segment, string $metric, float $value, int $contractCount): array
    {
        return [
            'stat_date' => $date,
            'segment_key' => $segment,
            'metric_key' => $metric,
            'pricing_basis' => 'observed_seller_data',
            'min_value' => $value,
            'p20_value' => $value,
            'avg_value' => $value,
            'median_value' => $value,
            'p80_value' => $value,
            'max_value' => $value,
            'contract_count' => $contractCount,
        ];
    }

    private function seedRisingSpotYearlyAverages(): void
    {
        for ($date = Carbon::create(2025, 1, 2); $date->lte(Carbon::create(2026, 4, 29)); $date->addDay()) {
            $price = $date->gte(Carbon::create(2026, 1, 1)) ? 8.7 : 5.0;

            SpotPriceAverage::create([
                'region' => 'FI',
                'period_type' => SpotPriceAverage::PERIOD_DAILY,
                'period_start' => $date->toDateString(),
                'period_end' => $date->toDateString(),
                'avg_price_without_tax' => $price,
                'avg_price_with_tax' => $price,
                'hours_count' => 24,
            ]);
        }
    }

    private function seedCaptionMismatchStatistics(): void
    {
        foreach ([
            ['date' => '2026-01-01', 'spot_cents' => 10.0, 'spot_cost' => 350.0, 'fixed_cost' => 500.0],
            ['date' => '2026-04-29', 'spot_cents' => 2.0, 'spot_cost' => 400.0, 'fixed_cost' => 520.0],
        ] as $row) {
            ContractPriceDailyStatistic::create([
                'stat_date' => $row['date'],
                'segment_key' => 'spot',
                'metric_key' => 'spot_total_energy_price',
                'consumption_kwh' => null,
                'min_value' => $row['spot_cents'],
                'p20_value' => $row['spot_cents'],
                'avg_value' => $row['spot_cents'],
                'median_value' => $row['spot_cents'],
                'p80_value' => $row['spot_cents'],
                'max_value' => $row['spot_cents'],
                'contract_count' => 10,
            ]);

            ContractPriceDailyStatistic::create([
                'stat_date' => $row['date'],
                'segment_key' => 'spot',
                'metric_key' => 'annual_cost',
                'consumption_kwh' => 5000,
                'min_value' => $row['spot_cost'],
                'p20_value' => $row['spot_cost'],
                'avg_value' => $row['spot_cost'],
                'median_value' => $row['spot_cost'],
                'p80_value' => $row['spot_cost'],
                'max_value' => $row['spot_cost'],
                'contract_count' => 10,
            ]);

            ContractPriceDailyStatistic::create([
                'stat_date' => $row['date'],
                'segment_key' => 'fixed_term_12',
                'metric_key' => 'energy_price',
                'consumption_kwh' => null,
                'min_value' => 7.0,
                'p20_value' => 7.0,
                'avg_value' => 7.0,
                'median_value' => 7.0,
                'p80_value' => 7.0,
                'max_value' => 7.0,
                'contract_count' => 10,
            ]);

            ContractPriceDailyStatistic::create([
                'stat_date' => $row['date'],
                'segment_key' => 'fixed_term_12',
                'metric_key' => 'annual_cost',
                'consumption_kwh' => 5000,
                'min_value' => $row['fixed_cost'],
                'p20_value' => $row['fixed_cost'],
                'avg_value' => $row['fixed_cost'],
                'median_value' => $row['fixed_cost'],
                'p80_value' => $row['fixed_cost'],
                'max_value' => $row['fixed_cost'],
                'contract_count' => 10,
            ]);
        }
    }

    private function seedQuarterlyStatistics(int $contractCount): void
    {
        foreach (['energy_price', 'monthly_fee'] as $metric) {
            ContractPriceDailyStatistic::create([
                'stat_date' => '2026-04-29',
                'segment_key' => 'quarterly',
                'metric_key' => $metric,
                'consumption_kwh' => null,
                'min_value' => 8.0,
                'p20_value' => 8.0,
                'avg_value' => 8.0,
                'median_value' => 8.0,
                'p80_value' => 8.0,
                'max_value' => 8.0,
                'contract_count' => $contractCount,
            ]);
        }

        ContractPriceDailyStatistic::create([
            'stat_date' => '2026-04-29',
            'segment_key' => 'quarterly',
            'metric_key' => 'annual_cost',
            'consumption_kwh' => 5000,
            'min_value' => 450,
            'p20_value' => 460,
            'avg_value' => 470,
            'median_value' => 470,
            'p80_value' => 480,
            'max_value' => 490,
            'contract_count' => $contractCount,
        ]);
    }

    private function seedDatedQuarterlyTrend(int $days): void
    {
        $end = Carbon::create(2026, 4, 20);

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $end->copy()->subDays($offset)->toDateString();

            ContractPriceDailyStatistic::create([
                'stat_date' => $date,
                'segment_key' => 'quarterly',
                'metric_key' => 'energy_price',
                'consumption_kwh' => null,
                'min_value' => 7.0,
                'p20_value' => 7.2,
                'avg_value' => 7.5,
                'median_value' => 7.4,
                'p80_value' => 7.8,
                'max_value' => 8.0,
                'contract_count' => 15,
            ]);

            ContractPriceDailyStatistic::create([
                'stat_date' => $date,
                'segment_key' => 'quarterly',
                'metric_key' => 'annual_cost',
                'consumption_kwh' => 5000,
                'min_value' => 430,
                'p20_value' => 450,
                'avg_value' => 470,
                'median_value' => 465,
                'p80_value' => 490,
                'max_value' => 510,
                'contract_count' => 15,
            ]);
        }
    }

    private function seedMarketResetStatistics(int $days): void
    {
        $end = Carbon::create(2026, 8, 9)->addDays($days);

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $end->copy()->subDays($offset)->toDateString();

            ContractPriceDailyStatistic::create([
                'stat_date' => $date,
                'segment_key' => 'market_reset',
                'metric_key' => 'energy_price',
                'consumption_kwh' => null,
                'min_value' => 6.8,
                'p20_value' => 7.0,
                'avg_value' => 7.4,
                'median_value' => 7.3,
                'p80_value' => 7.8,
                'max_value' => 8.0,
                'contract_count' => 25,
            ]);

            ContractPriceDailyStatistic::create([
                'stat_date' => $date,
                'segment_key' => 'market_reset',
                'metric_key' => 'annual_cost',
                'consumption_kwh' => 5000,
                'min_value' => 430,
                'p20_value' => 450,
                'avg_value' => 470,
                'median_value' => 465,
                'p80_value' => 490,
                'max_value' => 510,
                'contract_count' => 25,
            ]);
        }
    }

    private function seedSampleStatistics(): void
    {
        $start = Carbon::create(2026, 1, 1);
        $end = Carbon::create(2026, 4, 29);
        $segments = ['spot', 'fixed_term_12', 'open_ended', 'hybrid'];

        for ($date = $start->copy(); $date->lte($end); $date->addDays(7)) {
            foreach ($segments as $i => $segment) {
                ContractPriceDailyStatistic::create([
                    'stat_date' => $date->toDateString(),
                    'segment_key' => $segment,
                    'metric_key' => 'energy_price',
                    'consumption_kwh' => null,
                    'min_value' => 5.0 + $i * 0.4,
                    'p20_value' => 5.4 + $i * 0.4,
                    'avg_value' => 6.2 + $i * 0.4,
                    'median_value' => 6.0 + $i * 0.4,
                    'p80_value' => 7.1 + $i * 0.4,
                    'max_value' => 8.0 + $i * 0.4,
                    'contract_count' => 25 + $i * 3,
                ]);

                ContractPriceDailyStatistic::create([
                    'stat_date' => $date->toDateString(),
                    'segment_key' => $segment,
                    'metric_key' => 'monthly_fee',
                    'consumption_kwh' => null,
                    'min_value' => 1.99,
                    'p20_value' => 2.5,
                    'avg_value' => 3.2,
                    'median_value' => 3.0,
                    'p80_value' => 3.99,
                    'max_value' => 4.5,
                    'contract_count' => 25 + $i * 3,
                ]);

                foreach ([2000, 5000, 18000] as $kwh) {
                    $base = 0.062 * $kwh + 38;
                    ContractPriceDailyStatistic::create([
                        'stat_date' => $date->toDateString(),
                        'segment_key' => $segment,
                        'metric_key' => 'annual_cost',
                        'consumption_kwh' => $kwh,
                        'min_value' => $base * 0.9,
                        'p20_value' => $base * 0.95,
                        'avg_value' => $base + $i * 5,
                        'median_value' => $base + $i * 4,
                        'p80_value' => $base * 1.05,
                        'max_value' => $base * 1.15,
                        'contract_count' => 25 + $i * 3,
                    ]);
                }

                if ($segment === 'spot') {
                    ContractPriceDailyStatistic::create([
                        'stat_date' => $date->toDateString(),
                        'segment_key' => 'spot',
                        'metric_key' => 'spot_margin',
                        'consumption_kwh' => null,
                        'min_value' => 0.2,
                        'p20_value' => 0.4,
                        'avg_value' => 0.6,
                        'median_value' => 0.55,
                        'p80_value' => 0.9,
                        'max_value' => 1.5,
                        'contract_count' => 25,
                    ]);

                    ContractPriceDailyStatistic::create([
                        'stat_date' => $date->toDateString(),
                        'segment_key' => 'spot',
                        'metric_key' => 'spot_total_energy_price',
                        'consumption_kwh' => null,
                        'min_value' => 5.5,
                        'p20_value' => 6.0,
                        'avg_value' => 6.4,
                        'median_value' => 6.3,
                        'p80_value' => 7.0,
                        'max_value' => 8.5,
                        'contract_count' => 25,
                    ]);
                }
            }
        }

    }
}

class CountingContractPriceDailyStatistic extends ContractPriceDailyStatistic
{
    /** @var array<string,int> */
    public static array $readCounts = [
        'segment_key' => 0,
        'metric_key' => 0,
        'consumption_kwh' => 0,
    ];

    public static function resetReadCounts(): void
    {
        self::$readCounts = array_fill_keys(array_keys(self::$readCounts), 0);
    }

    public function getAttribute($key): mixed
    {
        if (is_string($key) && array_key_exists($key, self::$readCounts)) {
            self::$readCounts[$key]++;
        }

        return parent::getAttribute($key);
    }
}
