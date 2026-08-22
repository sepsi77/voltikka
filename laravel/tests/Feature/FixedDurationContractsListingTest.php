<?php

namespace Tests\Feature;

use App\Livewire\SeoContractsList;
use App\Models\Company;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Models\FixedContractPriceForecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class FixedDurationContractsListingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow();
        Cache::flush();
        config()->set('canonical_pricing.enabled', false);
        config()->set('price_forecasting.fixed_term.model_version', 'duration_listing_test');
        app()->forgetScopedInstances();

        $this->company = Company::create([
            'name' => 'Kesto Energia Oy',
            'name_slug' => 'kesto-energia-oy',
            'company_url' => 'https://example.test',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_exact_duration_routes_are_public_and_have_exact_defaults(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $route = Route::getRoutes()->getByName($case['route_name']);

            $this->assertNotNull($route);
            $this->assertSame('FixedTerm', $route->defaults['contractDuration'] ?? null);
            $this->assertSame($case['range'], $route->defaults['fixedTimeRange'] ?? null);

            $this->get($case['path'])
                ->assertOk()
                ->assertSeeText("{$months} kk määräaikainen sähkösopimus: vertaa hinnat")
                ->assertSeeText("Halvin {$months} kk sähkösopimus");
        }
    }

    public function test_each_page_filters_by_exact_structured_duration(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $this->fixedContract("fixed-{$months}", "Oma {$months} kk sopimus", $case['range']);
        }

        ElectricityContract::factory()
            ->forCompany($this->company)
            ->active()
            ->household()
            ->create([
                'id' => 'wrong-contract-type',
                'name' => 'Nimessä 6 kk mutta avoin',
                'contract_type' => 'OpenEnded',
                'fixed_time_range' => 'Fixed6',
            ]);

        foreach ($this->durationCases() as $months => $case) {
            $contracts = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $case['range'],
            ])->viewData('contracts');

            $this->assertSame(["fixed-{$months}"], $contracts->pluck('id')->all());
        }
    }

    public function test_each_page_has_unique_seo_content_canonical_heading_and_discovery_links(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $this->fixedContract("seo-{$months}", "SEO {$months} kk", $case['range']);

            $component = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $case['range'],
            ]);
            $seo = $component->viewData('seoData');

            $this->assertStringStartsWith("{$months} kk määräaikainen sähkösopimus: vertaa hinnat", $seo['title']);
            $this->assertStringEndsWith('| Voltikka', $seo['title']);
            $this->assertStringContainsString("{$months} kk", $seo['description']);
            $this->assertStringContainsString('hintoja ja ehtoja', $seo['description']);
            $this->assertStringContainsString('kehitys ja ennuste', $seo['description']);
            $this->assertStringContainsString('halvin vaihtoehto', $seo['description']);
            $this->assertStringEndsWith($case['path'], $seo['canonical']);
            $this->assertSame("{$months} kk määräaikainen sähkösopimus: vertaa hinnat", $component->viewData('pageHeading'));
            $this->assertStringContainsString("sovitun {$months} kuukauden ajan", $component->viewData('seoIntroText'));
            $this->assertStringContainsString('12 kuukauden vertailukustannuksia', $component->viewData('seoIntroText'));
            $this->assertStringContainsString('tarjottujen energiahintojen kehitystä ja ennustetta', $component->viewData('seoIntroText'));

            $component
                ->assertSeeText("Halvin {$months} kk sähkösopimus")
                ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-6-kk"')
                ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-12-kk"')
                ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-24-kk"');
        }
    }

    public function test_all_fixed_term_pages_render_their_unique_support_heading(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $this->fixedContract("guide-heading-{$months}", "Opas {$months} kk", $case['range']);
        }

        foreach ($this->supportHeadingCases() as $case) {
            $response = $this->get($case['path'])
                ->assertOk()
                ->assertSeeText($case['heading']);

            $this->assertSame(3, substr_count($response->getContent(), 'data-fixed-term-faq-item'));

            foreach ($this->supportHeadingCases() as $otherCase) {
                if ($otherCase['heading'] !== $case['heading']) {
                    $response->assertDontSeeText($otherCase['heading']);
                }
            }
        }
    }

    public function test_six_month_page_shows_winter_end_date_warning(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00', 'Europe/Helsinki'));
        $this->fixedContract('winter-end-fixed-6', 'Talveen päättyvä sopimus', 'Fixed6');

        $component = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed6',
        ]);

        $component
            ->assertSeeText('Milloin nyt alkava 6 kuukauden sopimus päättyy?')
            ->assertSeeText('15.12.2026')
            ->assertSeeHtml('datetime="2026-12-15"')
            ->assertSeeText('Tarkka päivä näkyy tilausvahvistuksessa, koska sopimuskausi alkaa sovittuna päivänä.')
            ->assertSeeText('Uusiminen osuu talveen, jolloin uusi sopimus voi olla kalliimpi.')
            ->assertSeeText('Vertaa seuraava sopimus 1–2 kuukautta ennen nykyisen sopimuksen päättymistä.');

        foreach ([null, 'Fixed12', 'Fixed24'] as $fixedTimeRange) {
            Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $fixedTimeRange,
            ])->assertDontSeeText('Milloin nyt alkava 6 kuukauden sopimus päättyy?');
        }
    }

    public function test_six_month_page_shows_non_winter_end_date_without_warning(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-31 12:00', 'Europe/Helsinki'));
        $this->fixedContract('summer-end-fixed-6', 'Kesään päättyvä sopimus', 'Fixed6');

        Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed6',
        ])
            ->assertSeeText('31.7.2026')
            ->assertSeeHtml('datetime="2026-07-31"')
            ->assertSeeText('Vertaa seuraava sopimus 1–2 kuukautta ennen nykyisen sopimuksen päättymistä.')
            ->assertDontSeeText('Uusiminen osuu talveen');
    }

    public function test_revised_guide_does_not_render_old_or_technical_phrases(): void
    {
        foreach ($this->durationCases() as $months => $case) {
            $this->fixedContract("language-{$months}", "Kielitesti {$months} kk", $case['range']);
        }

        $bannedPhrases = [
            'vuositasoistaa',
            'hinnan toimintaa',
            'Mitä sopimusajan valinta maksaa?',
            'Onko halvin vaihtoehto täysin kiinteä?',
            'Mihin hinnat ovat liikkumassa?',
            'Sitoumus ja vertailuarvio',
            'hinnoittelumekanismi',
            'Tämän vertailun matalin täysin kiinteä mediaani',
            'ennen sivutusta',
        ];

        foreach ($this->supportHeadingCases() as $case) {
            $response = $this->get($case['path'])->assertOk();

            foreach ($bannedPhrases as $phrase) {
                $response->assertDontSeeText($phrase);
            }

            $response->assertSeeText('Mitä sopimuskausi tarkoittaa?');
        }
    }

    public function test_common_date_comparison_adds_personalized_deltas_and_exact_page_label(): void
    {
        $this->fixedContract('comparison-fixed-12', 'Vertailun 12 kk sopimus', 'Fixed12');
        $this->comparisonStatistic('2026-06-15', 6, 7.20, 8.10, 9.40, 11);
        $this->comparisonStatistic('2026-06-15', 12, 7.80, 8.60, 9.90, 12);
        $this->comparisonStatistic('2026-06-15', 24, 8.30, 9.10, 10.40, 13);

        $component = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed12',
        ])->set('consumption', 10000);
        $comparison = $component->viewData('fixedTermComparison');
        $rows = collect($comparison['rows'])->keyBy('duration_months');

        $this->assertSame('2026-06-15', $comparison['date']);
        $this->assertSame('observed_seller_data', $comparison['basis']);
        $this->assertSame(12, $comparison['baseline_duration_months']);
        $this->assertSame(10000, $comparison['selected_consumption_kwh']);
        $this->assertSame(-0.5, $rows[6]['difference_cents_per_kwh']);
        $this->assertSame(-50.0, $rows[6]['annual_energy_cost_difference_eur']);
        $this->assertSame(0.0, $rows[12]['difference_cents_per_kwh']);
        $this->assertSame(0.0, $rows[12]['annual_energy_cost_difference_eur']);
        $this->assertSame(0.5, $rows[24]['difference_cents_per_kwh']);
        $this->assertSame(50.0, $rows[24]['annual_energy_cost_difference_eur']);
        $this->assertSame(7.2, $comparison['scale_min']);
        $this->assertSame(10.4, $comparison['scale_max']);

        $component
            ->assertSeeText('Miten sopimuskausi vaikuttaa hintaan?')
            ->assertSeeText('Täysin kiinteiden sopimusten mediaanihinta on matalin 6 kuukauden sopimuksissa: 8,10 c/kWh.')
            ->assertSeeText('Hinnat on poimittu samalta päivältä')
            ->assertSeeText('Täysin kiinteä mediaani')
            ->assertSeeText('−0,50 c/kWh')
            ->assertSeeText('−50 €')
            ->assertSeeText('+50 €')
            ->assertSeeText('Tällä sivulla')
            ->assertDontSeeText('Vertailukohta')
            ->assertSeeText('Hinnat ovat päivältä 15.6.2026')
            ->assertSeeText('Taulukossa ovat vain täysin kiinteähintaiset sopimukset')
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-6-kk"')
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-12-kk"')
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen-24-kk"');

        $this->assertSame(1, substr_count($component->html(), 'Tällä sivulla'));
    }

    public function test_general_page_uses_12_month_baseline_and_baseline_label(): void
    {
        $this->fixedContract('comparison-general', 'Yleisen sivun sopimus', 'Fixed12');
        $this->comparisonStatistic('2026-06-15', 6, 7.20, 8.10, 9.40, 11);
        $this->comparisonStatistic('2026-06-15', 12, 7.80, 8.60, 9.90, 12);
        $this->comparisonStatistic('2026-06-15', 24, 8.30, 9.10, 10.40, 13);

        $component = Livewire::test(SeoContractsList::class, ['contractDuration' => 'FixedTerm']);

        $this->assertSame(12, $component->viewData('fixedTermComparison')['baseline_duration_months']);
        $component
            ->assertSeeText('Vertailukohta')
            ->assertDontSeeText('Tällä sivulla');
        $this->assertSame(1, substr_count($component->html(), 'Vertailukohta'));
    }

    public function test_comparison_uses_newest_shared_date_and_requires_ten_contracts_per_duration(): void
    {
        foreach ([6, 12, 24] as $months) {
            $this->comparisonStatistic('2026-05-20', $months, 7.0 + $months / 10, 8.0 + $months / 10, 9.0 + $months / 10, 10);
        }
        $this->comparisonStatistic('2026-06-03', 6, 16.0, 17.0, 18.0, 14);
        $this->comparisonStatistic('2026-06-02', 12, 26.0, 27.0, 28.0, 14);
        $this->comparisonStatistic('2026-06-01', 24, 36.0, 37.0, 38.0, 14);

        Livewire::test(SeoContractsList::class, ['contractDuration' => 'FixedTerm'])
            ->assertSeeText('Hinnat ovat päivältä 20.5.2026')
            ->assertDontSeeText('Hinnat ovat päivältä 3.6.2026');

        ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', '2026-05-20')
            ->where('segment_key', 'fixed_term_24')
            ->update(['contract_count' => 9]);
        Cache::flush();

        $withoutCommonData = Livewire::test(SeoContractsList::class, ['contractDuration' => 'FixedTerm'])
            ->assertDontSeeText('Miten sopimuskausi vaikuttaa hintaan?')
            ->assertDontSeeText('Hinnat ovat päivältä 20.5.2026');

        $this->assertSame([], $withoutCommonData->viewData('fixedTermComparison'));
    }

    public function test_mechanism_summary_counts_categories_and_uses_typed_category_minima(): void
    {
        $this->pricedFixedContract('fixed-expensive', 'Kalliimpi kiinteä', 'Fixed12', 8.0, 5.0);
        $this->pricedFixedContract('fixed-cheap', 'Edullisin kiinteä', 'Fixed12', 5.0, 3.0);
        $this->pricedFixedContract('effect-cheap', 'Kulutusvaikutus', 'Fixed12', 4.0, 2.0, 'consumption_effect');
        $this->pricedFixedContract('market-option', 'Markkinahinta', 'Fixed12', 6.0, 1.0, 'market');

        $component = Livewire::test(SeoContractsList::class, ['contractDuration' => 'FixedTerm']);
        $summary = $component->viewData('fixedTermMechanismSummary');
        $groups = collect($summary['groups'])->keyBy('category');

        $this->assertSame('consumption_effect', $summary['cheapest_category']);
        $this->assertSame(2, $groups['fixed']['count']);
        $this->assertSame(1, $groups['consumption_effect']['count']);
        $this->assertSame(1, $groups['market']['count']);
        $this->assertEqualsWithDelta(286.0, $groups['fixed']['lowest_annual_comparison_eur'], 0.01);
        $this->assertEqualsWithDelta(286.0 / 12, $groups['fixed']['monthly_equivalent_eur'], 0.01);
        $this->assertEqualsWithDelta(224.0, $groups['consumption_effect']['lowest_annual_comparison_eur'], 0.01);
        $this->assertEqualsWithDelta(312.0, $groups['market']['lowest_annual_comparison_eur'], 0.01);

        $component
            ->assertSeeText('Onko listan halvin sopimus kiinteähintainen?')
            ->assertSeeText('Pelkkä sopimuskausi ei kerro, onko hinta kiinteä.')
            ->assertSeeText('Listan halvin sopimus on kulutusvaikutuksellinen.')
            ->assertSeeText('Hinta ennen kulutusvaikutusta')
            ->assertSeeText('286 €')
            ->assertSeeText('23,8 €/kk')
            ->assertSeeHtml('href="/sahkosopimus/kiintea-hinta"')
            ->assertSeeHtml('href="/sahkosopimus/kulutusvaikutus"')
            ->assertDontSeeText('Hakua vastaavia sopimuksia')
            ->assertDontSeeText('Matalin 12 kk vertailuarvio');
    }

    public function test_personalized_guide_payloads_are_hidden_in_bill_mode(): void
    {
        $this->pricedFixedContract('bill-summary-fixed', 'Laskutilan sopimus', 'Fixed12', 5.0, 3.0);
        foreach ([6, 12, 24] as $months) {
            $this->comparisonStatistic('2026-06-15', $months, 7.0, 8.0, 9.0, 10);
        }
        $this->unitStatistic('2026-05-01', 12, 8.0);
        $this->unitStatistic('2026-06-01', 12, 8.5);
        $this->forecast('2026-06-01', 12);

        $component = Livewire::test(SeoContractsList::class, ['contractDuration' => 'FixedTerm'])
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 300)
            ->set('billTotalEur', 40.00);

        $this->assertSame([], $component->viewData('fixedTermComparison'));
        $this->assertNull($component->viewData('fixedTermMechanismSummary'));
        $this->assertSame([], $component->viewData('fixedTermMarketDirection'));
        $component
            ->assertDontSeeText('Miten sopimuskausi vaikuttaa hintaan?')
            ->assertDontSeeText('Onko listan halvin sopimus kiinteähintainen?')
            ->assertDontSeeText('Ovatko hinnat nousussa vai laskussa?');
    }

    public function test_exact_duration_guides_include_specific_advice_and_internal_links(): void
    {
        $cases = [
            'Fixed6' => [
                'copy' => 'Sopimuskortin 12 kuukauden vertailussa 6 kuukauden hinta jatkuu laskennallisesti koko vuoden',
                'links' => ['/sahkosopimus/maaraaikainen-12-kk'],
            ],
            'Fixed12' => [
                'copy' => '12 kuukauden sopimus sopii sinulle, jos haluat yhden sopimuksen kaikkien vuodenaikojen yli',
                'links' => ['/sahkosopimus/tilastot'],
            ],
            'Fixed24' => [
                'copy' => 'Sopimuskortin kustannusarvio kattaa 12 kuukautta, mutta sitoumus kestää 24 kuukautta',
                'links' => ['/sahkosopimus/maaraaikainen-12-kk'],
            ],
        ];

        foreach ($this->durationCases() as $months => $durationCase) {
            $this->fixedContract("guide-copy-{$months}", "Ohje {$months} kk", $durationCase['range']);

            $component = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $durationCase['range'],
            ])->assertSeeText($cases[$durationCase['range']]['copy']);

            foreach ($cases[$durationCase['range']]['links'] as $link) {
                $component->assertSeeHtml('href="'.$link.'"');
            }
        }
    }

    public function test_fixed_term_guide_follows_a_contract_and_precedes_related_links(): void
    {
        $this->fixedContract('guide-order', 'Järjestystestin sopimus', 'Fixed12');

        $html = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
        ])->html();

        $contractPosition = strrpos($html, 'Järjestystestin sopimus');
        $guidePosition = strpos($html, 'Määräaikaisen sähkösopimuksen valintaopas');
        $relatedPosition = strpos($html, 'Katso myös');

        $this->assertNotFalse($contractPosition);
        $this->assertNotFalse($guidePosition);
        $this->assertNotFalse($relatedPosition);
        $this->assertLessThan($guidePosition, $contractPosition);
        $this->assertLessThan($relatedPosition, $guidePosition);
    }

    public function test_page_two_does_not_render_the_long_fixed_term_guide(): void
    {
        foreach (range(1, 26) as $index) {
            $this->fixedContract("page-two-{$index}", "Sivun sopimus {$index}", 'Fixed12');
        }

        $this->get('/sahkosopimus/maaraaikainen-12-kk?page=2')
            ->assertOk()
            ->assertDontSeeText('12 kuukauden määräaikainen sähkösopimus käytännössä')
            ->assertDontSeeText('Miten sopimuskausi vaikuttaa hintaan?')
            ->assertDontSeeText('Onko listan halvin sopimus kiinteähintainen?')
            ->assertSeeText('Katso myös');
    }

    public function test_exact_results_heading_remains_visible_in_bill_mode(): void
    {
        $this->fixedContract('bill-fixed-6', 'Bill 6 kk', 'Fixed6');

        Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed6',
        ])
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 300)
            ->set('billTotalEur', 40.00)
            ->assertSeeText('Halvin 6 kk sähkösopimus');
    }

    public function test_each_page_selects_its_matching_unit_trend_and_eligible_forecast(): void
    {
        foreach ([6 => 6.60, 12 => 12.60, 24 => 24.60] as $months => $latestMedian) {
            $this->unitStatistic('2026-05-01', $months, $latestMedian - 0.60);
            $this->unitStatistic('2026-06-01', $months, $latestMedian);
            $this->unitStatistic('2026-06-02', $months, 99.90, 'canonical_calculation');
            $this->forecast('2026-06-01', $months);
            $this->forecast('2026-06-02', $months, 'canonical_calculation');
        }

        foreach ($this->durationCases() as $months => $case) {
            $insight = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $case['range'],
            ])->viewData('marketInsight');

            $this->assertTrue($insight['has_items']);
            $this->assertSame((float) ($months + 0.60), $insight['trend']['latest_value']);
            $this->assertSame("{$months} kk hintakehitys", $insight['trend']['eyebrow']);
            $this->assertSame($case['segment'], $insight['trend']['segment_key']);
            $this->assertSame($months, $insight['trend']['duration_months']);
            $this->assertSame('2026-05-01', $insight['trend']['previous_as_of']);
            $this->assertSame($months, $insight['forecast']['duration_months']);
            $this->assertSame("{$months} kk ennuste", $insight['forecast']['eyebrow']);
            $this->assertSame('2026-06-01', $insight['forecast']['forecast_date']);
            $this->assertSame((float) ($months + 0.60), $insight['forecast']['current_price_cents_per_kwh']);
            $this->assertSame((float) ($months + 0.70), $insight['forecast']['forecast_price_cents_per_kwh']);
            $this->assertSame(0.10, $insight['forecast']['expected_change_cents_per_kwh']);
            $this->assertSame(30, $insight['forecast']['horizon_days']);
            $this->assertSame($months, $insight['forecast']['contract_count']);

            $directionComponent = Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $case['range'],
            ]);
            $direction = $directionComponent->viewData('fixedTermMarketDirection');
            $this->assertSame($months, $direction['duration_months']);
            $this->assertSame('2026-06-01', $direction['trend']['as_of']);
            $this->assertSame('2026-06-01', $direction['forecast']['forecast_date']);
            $this->assertSame(5.0, $direction['forecast']['annual_energy_rate_change_eur']);
            $directionComponent
                ->assertSeeText('Ovatko hinnat nousussa vai laskussa?')
                ->assertSeeText('Mediaani '.number_format($months + 0.60, 2, ',', ' ').' → '.number_format($months + 0.70, 2, ',', ' ').' c/kWh')
                ->assertSeeText('+0,10 c/kWh')
                ->assertSeeText('+5 €/vuosi')
                ->assertSeeText('Kun ennuste on vakaa, valitse kausi sen mukaan')
                ->assertSeeText('Kuukausimaksu ja muut sähkölaskun erät eivät sisälly');
        }

        $generalDirection = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
        ])->viewData('fixedTermMarketDirection');
        $this->assertSame(12, $generalDirection['duration_months']);
        $this->assertSame(12, $generalDirection['forecast']['duration_months']);
    }

    public function test_forecast_tone_gives_a_direct_duration_recommendation(): void
    {
        $cases = [
            6 => ['Fixed6', 'lock_sooner', 'Nousuennuste puoltaa hinnan lukitsemista nyt'],
            12 => ['Fixed12', 'wait_if_flexible', 'Laskuennusteen aikana lyhyt sopimus antaa mahdollisuuden kilpailuttaa hinta pian uudelleen'],
            24 => ['Fixed24', 'neutral', 'Kun ennuste on vakaa, valitse kausi sen mukaan'],
        ];

        foreach ($cases as $months => [$range, $signal, $recommendation]) {
            $this->forecast('2026-06-01', $months, 'observed_seller_data', $signal);

            Livewire::test(SeoContractsList::class, [
                'contractDuration' => 'FixedTerm',
                'fixedTimeRange' => $range,
            ])->assertSeeText($recommendation);
        }
    }

    public function test_market_direction_hides_missing_trend_and_forecast_independently(): void
    {
        $this->fixedContract('trend-only', 'Vain trendi', 'Fixed6');
        $this->unitStatistic('2026-05-01', 6, 6.0);
        $this->unitStatistic('2026-06-01', 6, 6.5);

        $trendOnly = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed6',
        ]);
        $this->assertNotNull($trendOnly->viewData('fixedTermMarketDirection')['trend']);
        $this->assertNull($trendOnly->viewData('fixedTermMarketDirection')['forecast']);
        $trendOnly
            ->assertSeeText('30 päivän toteutunut hintakehitys')
            ->assertDontSeeText('Ennuste 30 päivän päähän');

        $this->fixedContract('forecast-only', 'Vain ennuste', 'Fixed12');
        $this->forecast('2026-06-02', 12);

        $forecastOnly = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed12',
        ]);
        $this->assertNull($forecastOnly->viewData('fixedTermMarketDirection')['trend']);
        $this->assertNotNull($forecastOnly->viewData('fixedTermMarketDirection')['forecast']);
        $forecastOnly
            ->assertDontSeeText('30 päivän toteutunut hintakehitys')
            ->assertSeeText('Ennuste 30 päivän päähän');
    }

    public function test_canonical_exact_trend_uses_matching_observed_history_and_canonical_forecast_provenance(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Cache::flush();

        foreach ([6, 12, 24] as $months) {
            $this->unitStatistic('2026-05-01', $months, (float) $months, 'observed_seller_data');
            $this->unitStatistic('2026-06-01', $months, $months + 1.0, 'canonical_calculation');
            $this->forecast('2026-06-01', $months, 'canonical_calculation');
        }
        $this->forecast('2026-06-02', 6, 'observed_seller_data');

        $insight = Livewire::test(SeoContractsList::class, [
            'contractDuration' => 'FixedTerm',
            'fixedTimeRange' => 'Fixed6',
        ])->viewData('marketInsight');

        $this->assertSame('fixed_term_6', $insight['trend']['segment_key']);
        $this->assertSame(7.0, $insight['trend']['latest_value']);
        $this->assertSame(6.0, $insight['trend']['previous_value']);
        $this->assertSame('canonical_calculation', $insight['trend']['latest_pricing_basis']);
        $this->assertSame('observed_seller_data', $insight['trend']['previous_pricing_basis']);
        $this->assertSame(6, $insight['forecast']['duration_months']);
        $this->assertSame('2026-06-01', $insight['forecast']['forecast_date']);
    }

    /**
     * @return array<int, array{path:string,route_name:string,range:string,segment:string}>
     */
    private function durationCases(): array
    {
        return [
            6 => [
                'path' => '/sahkosopimus/maaraaikainen-6-kk',
                'route_name' => 'seo.duration.maaraaikainen-6-kk',
                'range' => 'Fixed6',
                'segment' => 'fixed_term_6',
            ],
            12 => [
                'path' => '/sahkosopimus/maaraaikainen-12-kk',
                'route_name' => 'seo.duration.maaraaikainen-12-kk',
                'range' => 'Fixed12',
                'segment' => 'fixed_term_12',
            ],
            24 => [
                'path' => '/sahkosopimus/maaraaikainen-24-kk',
                'route_name' => 'seo.duration.maaraaikainen-24-kk',
                'range' => 'Fixed24',
                'segment' => 'fixed_term_24',
            ],
        ];
    }

    /**
     * @return list<array{path:string,heading:string}>
     */
    private function supportHeadingCases(): array
    {
        return [
            [
                'path' => '/sahkosopimus/maaraaikainen',
                'heading' => 'Määräaikaisen sähkösopimuksen valintaopas',
            ],
            [
                'path' => '/sahkosopimus/maaraaikainen-6-kk',
                'heading' => '6 kuukauden määräaikainen sähkösopimus käytännössä',
            ],
            [
                'path' => '/sahkosopimus/maaraaikainen-12-kk',
                'heading' => '12 kuukauden määräaikainen sähkösopimus käytännössä',
            ],
            [
                'path' => '/sahkosopimus/maaraaikainen-24-kk',
                'heading' => '24 kuukauden määräaikainen sähkösopimus käytännössä',
            ],
        ];
    }

    private function fixedContract(string $id, string $name, string $fixedTimeRange): ElectricityContract
    {
        return ElectricityContract::factory()
            ->forCompany($this->company)
            ->fixedTerm()
            ->active()
            ->household()
            ->create([
                'id' => $id,
                'name' => $name,
                'fixed_time_range' => $fixedTimeRange,
            ]);
    }

    private function unitStatistic(
        string $date,
        int $durationMonths,
        float $median,
        string $pricingBasis = 'observed_seller_data',
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => "fixed_term_{$durationMonths}",
            'metric_key' => 'energy_price',
            'pricing_basis' => $pricingBasis,
            'consumption_kwh' => null,
            'median_value' => $median,
            'contract_count' => $durationMonths,
        ]);
    }

    private function comparisonStatistic(
        string $date,
        int $durationMonths,
        float $p20,
        float $median,
        float $p80,
        int $contractCount,
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => "fixed_term_{$durationMonths}",
            'metric_key' => 'energy_price',
            'pricing_basis' => 'observed_seller_data',
            'consumption_kwh' => null,
            'p20_value' => $p20,
            'median_value' => $median,
            'p80_value' => $p80,
            'contract_count' => $contractCount,
        ]);
    }

    private function pricedFixedContract(
        string $id,
        string $name,
        string $fixedTimeRange,
        float $energyPrice,
        float $monthlyFee,
        string $category = 'fixed',
    ): ElectricityContract {
        $factory = ElectricityContract::factory()
            ->forCompany($this->company)
            ->fixedTerm();

        $factory = match ($category) {
            'consumption_effect' => $factory->hybrid(),
            'market' => $factory->reset(),
            default => $factory,
        };

        return $factory
            ->active()
            ->household()
            ->withRelationalPrices([
                [
                    'price_component_type' => 'General',
                    'payment_unit' => 'c/kWh',
                    'price' => $energyPrice,
                    'price_date' => '2026-06-15',
                ],
                [
                    'price_component_type' => 'Monthly',
                    'payment_unit' => 'EUR/month',
                    'price' => $monthlyFee,
                    'price_date' => '2026-06-15',
                ],
            ])
            ->create([
                'id' => $id,
                'name' => $name,
                'contract_type' => 'FixedTerm',
                'fixed_time_range' => $fixedTimeRange,
            ]);
    }

    private function forecast(
        string $date,
        int $durationMonths,
        string $pricingBasis = 'observed_seller_data',
        string $consumerSignal = 'neutral',
    ): void {
        FixedContractPriceForecast::create([
            'forecast_date' => $date,
            'target_date' => CarbonImmutable::parse($date)->addDays(30)->toDateString(),
            'horizon_days' => 30,
            'duration_months' => $durationMonths,
            'target_quantile' => 'median',
            'current_price_cents_per_kwh' => $durationMonths + 0.60,
            'forecast_price_cents_per_kwh' => $durationMonths + 0.70,
            'expected_change_cents_per_kwh' => 0.10,
            'hedge_cost_cents_per_kwh' => $durationMonths - 1.0,
            'retail_premium_cents_per_kwh' => 1.0,
            'normal_retail_premium_cents_per_kwh' => 1.1,
            'fair_price_cents_per_kwh' => $durationMonths + 0.80,
            'gap_cents_per_kwh' => 0.20,
            'futures_trade_date' => CarbonImmutable::parse($date)->subDay()->toDateString(),
            'coverage_quality' => 'all_monthly',
            'confidence' => 'low',
            'direction' => 'slightly_rising',
            'consumer_signal' => $consumerSignal,
            'contract_count' => $durationMonths,
            'model_version' => 'duration_listing_test',
            'source_metadata' => [
                'current_retail_pricing_basis' => $pricingBasis,
            ],
        ]);
    }
}
