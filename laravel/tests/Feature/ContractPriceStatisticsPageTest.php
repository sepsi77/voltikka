<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractPriceStatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_accessible_with_empty_data_and_does_not_leak_admin_instructions(): void
    {
        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('Sähkön hintatilastot: mitä suomalaiset oikeasti maksavat');
        $response->assertSee('Aineiston keruu on käynnissä');
        $response->assertDontSee('php artisan');
    }

    public function test_page_renders_data_when_statistics_exist(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('Sähkön hintatilastot: mitä suomalaiset oikeasti maksavat');
        $response->assertSee('Hinnat sopimustyypeittäin');
        $response->assertSee('Taulukko näyttää viimeisimmän keräyspäivän tyypillisen energiahinnan');
        $response->assertSee('Sopimustyypit, joissa on alle 10 sopimusta, jätetään pois');
        $response->assertSee('Pörssisähkön energiahinta on kyseisen päivän pörssin keskihinta + sopimuksen marginaali');
        $response->assertSee('Trendi on vuosikustannuksen kehitys');
        $response->assertSee('Hintahaarukka');
        $response->assertSee('Taulukko näyttää viimeisimmän keräyspäivän vuosikustannusten jakauman');
        $response->assertSee('sisältää energiahinnan sekä perusmaksut 12 kuukaudelta');
        $response->assertSee('Pörssisähkössä vuosikustannus käyttää edeltävän 12 kuukauden pörssin keskihintaa');
        $response->assertSee('Sopimusmäärä voi poiketa ylemmästä hintataulukosta');
        $response->assertSee('Mistä luvut tulevat');
        $response->assertSee('Viittaa tähän');
        $response->assertSee('CC&nbsp;BY&nbsp;4.0', false);
        $response->assertSee('ALV 25,5 %');
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

    public function test_tables_hide_segments_with_fewer_than_ten_contracts(): void
    {
        $this->seedSampleStatistics();

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
                'contract_count' => 9,
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
            'contract_count' => 9,
        ]);

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertDontSee('>Kvartaalisähkö</span>', false);
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

    public function test_lead_chart_caption_uses_annual_cost_trend_not_current_spot_cents(): void
    {
        $this->seedCaptionMismatchStatistics();

        $response = $this->get('/sahkosopimus/tilastot');

        $response->assertStatus(200);
        $response->assertSee('Pörssisähkö-sopimusten vuosikustannus on noussut 14 % aineiston alusta.');
        $response->assertDontSee('Pörssisähkö-sopimukset ovat halventuneet');
    }

    public function test_csv_endpoint_streams_with_attribution_header_lines(): void
    {
        $this->seedSampleStatistics();

        $response = $this->get('/sahkosopimus/tilastot.csv');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('Voltikka', $body);
        $this->assertStringContainsString('CC BY 4.0', $body);
        $this->assertStringContainsString('arvonlisäveron 25,5 %', $body);
        $this->assertStringContainsString('segment_key,metric_key', $body);
        $this->assertStringContainsString('spot,annual_cost,5000', $body);
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
