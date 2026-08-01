<?php

namespace Tests\Feature;

use App\Livewire\ContractsList;
use App\Livewire\SahkosopimusIndex;
use App\Livewire\SeoContractsList;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\ContractCard\Enums\PricingBucket;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The visible pricing-type filter (`?hintatyyppi=`), which selects contracts by the four
 * `PricingBucket` cases. Multi-select include semantics: no bucket selected shows
 * everything, and all four is the same set as none.
 *
 * The SQL always comes from `PricingCategoryResolver::scopeBucket()`, whose parity with the
 * card band is pinned in `ContractCardPresenterTest`. These tests cover the component state,
 * the query wiring and the legacy `?pricingModelFilter=` mapping.
 */
class PricingBucketFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Testi Energia Oy',
            'name_slug' => 'testi-energia-oy',
            'company_url' => 'https://testi.fi',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $recurringSchedule
     * @param  array<string, mixed>|null  $consumptionEffect
     * @return array<string, mixed>
     */
    private function canonicalAttributes(
        ?array $recurringSchedule = null,
        ?array $consumptionEffect = null,
    ): array {
        return CanonicalPricingFixture::attributes(
            phases: [],
            calculationStatus: CalculationStatus::Exact,
            recurringSchedule: $recurringSchedule,
            consumptionEffect: $consumptionEffect,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createContract(string $id, array $attributes = [], float $generalCents = 6.0): ElectricityContract
    {
        return ElectricityContract::factory()
            ->forCompany($this->company)
            ->active()
            ->withRelationalPrices([
                [
                    'id' => 'pc-gen-'.$id,
                    'price_component_type' => 'General',
                    'price_date' => now()->format('Y-m-d'),
                    'price' => $generalCents,
                    'payment_unit' => 'c/kWh',
                ],
                [
                    'id' => 'pc-mon-'.$id,
                    'price_component_type' => 'Monthly',
                    'price_date' => now()->format('Y-m-d'),
                    'price' => 3.0,
                    'payment_unit' => 'EUR/month',
                ],
            ])
            ->create([
                'id' => $id,
                'name' => 'Sopimus '.$id,
                'contract_type' => 'OpenEnded',
                'pricing_model' => 'FixedPrice',
                'metering' => 'General',
                'target_group' => 'Household',
                'short_description' => null,
                'pricing_name' => null,
                'pricing_has_discounts' => null,
                'consumption_control' => null,
                'pre_billing' => null,
                'available_for_existing_users' => null,
                'delivery_responsibility_product' => null,
                'order_link' => null,
                'product_link' => null,
                'availability_is_national' => true,
                'microproduction_buys' => null,
                ...$this->canonicalAttributes(),
                ...$attributes,
            ]);
    }

    /**
     * One contract per bucket, so every assertion below can name the expected set exactly.
     */
    private function createOnePerBucket(): void
    {
        $this->createContract('c-spot', ['pricing_model' => 'Spot']);
        $this->createContract('c-reset', [
            ...$this->canonicalAttributes(
                recurringSchedule: CanonicalPricingFixture::recurringSchedule(
                    cadence: 'quarterly',
                    currentPeriodStart: null,
                    currentPeriodEnd: null,
                    futurePriceKnown: null,
                ),
            ),
        ]);
        $this->createContract('c-effect', [
            ...$this->canonicalAttributes(
                consumptionEffect: CanonicalPricingFixture::consumptionEffect(
                    appliesTo: 'base_contract',
                    cadence: 'none',
                    expectedCentsPerKwh: null,
                    typicalMinCentsPerKwh: null,
                    typicalMaxCentsPerKwh: null,
                    hardMinCentsPerKwh: null,
                    hardMaxCentsPerKwh: null,
                    uncapped: null,
                ),
            ),
        ]);
        $this->createContract('c-fixed');
    }

    /**
     * @return array<int, string>
     */
    private function listedIds($component): array
    {
        return collect($component->viewData('contracts')->items())
            ->pluck('id')->sort()->values()->all();
    }

    // ---------------------------------------------------------------- filtering

    public function test_a_single_bucket_filters_the_listing(): void
    {
        $this->createOnePerBucket();

        foreach ([
            'porssisahko' => ['c-spot'],
            'paivittyva' => ['c-reset'],
            'kulutusvaikutus' => ['c-effect'],
            'kiintea' => ['c-fixed'],
        ] as $bucket => $expected) {
            $component = Livewire::test(ContractsList::class)->set('pricingBucketFilter', $bucket);

            $this->assertSame($expected, $this->listedIds($component), "bucket {$bucket} listed the wrong contracts");
        }
    }

    public function test_multiple_buckets_are_a_union(): void
    {
        $this->createOnePerBucket();

        $component = Livewire::test(ContractsList::class)
            ->set('pricingBucketFilter', 'porssisahko,kiintea');

        $this->assertSame(['c-fixed', 'c-spot'], $this->listedIds($component));
    }

    public function test_no_selection_and_all_four_buckets_are_both_unfiltered(): void
    {
        $this->createOnePerBucket();
        $all = ['c-effect', 'c-fixed', 'c-reset', 'c-spot'];

        $this->assertSame($all, $this->listedIds(Livewire::test(ContractsList::class)));

        $this->assertSame($all, $this->listedIds(
            Livewire::test(ContractsList::class)
                ->set('pricingBucketFilter', 'porssisahko,paivittyva,kulutusvaikutus,kiintea')
        ));
    }

    public function test_unknown_bucket_keys_are_ignored(): void
    {
        $this->createOnePerBucket();

        // A bot-supplied value must degrade to "no constraint", never to an error.
        $component = Livewire::test(ContractsList::class)
            ->set('pricingBucketFilter', 'roskaa,,  ,Spot');

        $this->assertSame([], $component->instance()->selectedPricingBuckets());
        $this->assertSame(['c-effect', 'c-fixed', 'c-reset', 'c-spot'], $this->listedIds($component));

        // A known key beside an unknown one still applies.
        $mixed = Livewire::test(ContractsList::class)
            ->set('pricingBucketFilter', 'roskaa, porssisahko');

        $this->assertSame(['c-spot'], $this->listedIds($mixed));
    }

    public function test_the_url_parameter_is_hintatyyppi(): void
    {
        $this->createOnePerBucket();

        $this->get('/sahkosopimus?hintatyyppi=porssisahko')
            ->assertStatus(200)
            ->assertSee('Sopimus c-spot')
            ->assertDontSee('Sopimus c-fixed');
    }

    // ---------------------------------------------------------------- the toggle action

    public function test_toggling_a_bucket_adds_and_removes_it_and_tracks_the_first_click(): void
    {
        $this->createOnePerBucket();

        $component = Livewire::test(ContractsList::class)
            ->set('page', 3)
            ->call('togglePricingBucket', 'kiintea');

        $this->assertSame('kiintea', $component->get('pricingBucketFilter'));
        $this->assertSame(1, $component->get('page'));
        $component->assertDispatched('track', function (string $name, array $params): bool {
            return $params['eventName'] === 'Contracts Filter Applied'
                && ($params['props']['filter_type'] ?? null) === 'pricing_category'
                && ($params['props']['value'] ?? null) === 'kiintea';
        });

        // A second bucket joins the selection, written in canonical enum order.
        $component->call('togglePricingBucket', 'porssisahko');
        $this->assertSame('porssisahko,kiintea', $component->get('pricingBucketFilter'));

        // Toggling an active bucket off removes it and fires no event.
        $component->call('togglePricingBucket', 'kiintea');
        $this->assertSame('porssisahko', $component->get('pricingBucketFilter'));
        $this->assertSame(['c-spot'], $this->listedIds($component));
    }

    public function test_toggling_an_unknown_bucket_does_nothing(): void
    {
        $component = Livewire::test(ContractsList::class)
            ->set('pricingBucketFilter', 'kiintea')
            ->call('togglePricingBucket', 'roskaa');

        $this->assertSame('kiintea', $component->get('pricingBucketFilter'));
    }

    // ---------------------------------------------------------------- legacy parameter

    public function test_legacy_pricing_model_values_map_onto_buckets_and_stop_applying_twice(): void
    {
        foreach ([
            'Spot' => 'porssisahko',
            'FixedPrice' => 'kiintea',
            'Hybrid' => 'kulutusvaikutus',
        ] as $legacy => $bucket) {
            $component = Livewire::withQueryParams(['pricingModelFilter' => $legacy])
                ->test(ContractsList::class);

            $this->assertSame($bucket, $component->get('pricingBucketFilter'), "{$legacy} mapped to the wrong bucket");
            $this->assertSame('', $component->get('pricingModelFilter'), "{$legacy} was left in place and would apply twice");
        }
    }

    public function test_the_legacy_hybrid_value_now_follows_the_bucket_rules(): void
    {
        // A Hybrid with a quarterly reset is Päivittyvä hinta, not Kulutusvaikutus (market
        // wins). The legacy `pricing_model = Hybrid` filter listed it; the mapped bucket
        // must not, otherwise the card band would contradict the filter that listed it.
        $this->createContract('c-hybrid-reset', [
            'pricing_model' => 'Hybrid',
            ...$this->canonicalAttributes(
                recurringSchedule: CanonicalPricingFixture::recurringSchedule(
                    cadence: 'quarterly',
                    currentPeriodStart: null,
                    currentPeriodEnd: null,
                    futurePriceKnown: null,
                ),
            ),
        ]);
        $this->createContract('c-hybrid-plain', ['pricing_model' => 'Hybrid']);

        $component = Livewire::withQueryParams(['pricingModelFilter' => 'Hybrid'])->test(ContractsList::class);

        $this->assertSame(['c-hybrid-plain'], $this->listedIds($component));
    }

    public function test_legacy_metering_pseudo_types_keep_their_own_behaviour(): void
    {
        $this->createContract('c-time', ['metering' => 'Time']);
        $this->createContract('c-fixed');

        $component = Livewire::withQueryParams(['pricingModelFilter' => 'TimeOfUse'])->test(ContractsList::class);

        $this->assertSame('TimeOfUse', $component->get('pricingModelFilter'));
        $this->assertSame('', $component->get('pricingBucketFilter'));
        $this->assertSame(['c-time'], $this->listedIds($component));
    }

    public function test_an_explicit_hintatyyppi_wins_over_a_legacy_parameter(): void
    {
        $this->createOnePerBucket();

        $component = Livewire::withQueryParams([
            'pricingModelFilter' => 'Spot',
            'hintatyyppi' => 'kiintea',
        ])->test(ContractsList::class);

        // The legacy value is not translated, so both constraints apply and the
        // intersection is empty. The visitor asked for two contradictory things.
        $this->assertSame('kiintea', $component->get('pricingBucketFilter'));
        $this->assertSame('Spot', $component->get('pricingModelFilter'));
        $this->assertSame([], $this->listedIds($component));
    }

    public function test_the_legacy_mapping_also_runs_on_seo_listing_pages(): void
    {
        $this->createOnePerBucket();

        $component = Livewire::withQueryParams(['pricingModelFilter' => 'Spot'])
            ->test(SahkosopimusIndex::class);

        $this->assertSame('porssisahko', $component->get('pricingBucketFilter'));
        $this->assertSame(['c-spot'], $this->listedIds($component));
    }

    // ---------------------------------------------------------------- filter bookkeeping

    public function test_a_bucket_selection_counts_as_an_active_filter(): void
    {
        $component = Livewire::test(ContractsList::class);
        $this->assertFalse($component->instance()->hasActiveFilters());

        $component->set('pricingBucketFilter', 'kiintea');
        $this->assertTrue($component->instance()->hasActiveFilters());

        // All four lists the same contracts as none, but it is still not the canonical
        // default state, so the default-listing prepared-data cache must not serve it.
        $component->set('pricingBucketFilter', 'porssisahko,paivittyva,kulutusvaikutus,kiintea');
        $this->assertTrue($component->instance()->hasActiveFilters());

        $component->set('pricingBucketFilter', 'roskaa');
        $this->assertFalse($component->instance()->hasActiveFilters());
    }

    public function test_reset_filters_clears_the_bucket_selection(): void
    {
        $this->createOnePerBucket();

        $component = Livewire::test(ContractsList::class)
            ->set('pricingBucketFilter', 'porssisahko')
            ->call('resetFilters');

        $this->assertSame('', $component->get('pricingBucketFilter'));
        $this->assertSame(['c-effect', 'c-fixed', 'c-reset', 'c-spot'], $this->listedIds($component));
    }

    // ---------------------------------------------------------------- composition

    public function test_the_filter_composes_with_an_seo_pricing_type_page(): void
    {
        $this->createOnePerBucket();

        // /sahkosopimus/porssisahko already fixes the pricing type; the interactive filter
        // narrows it further (AND), so an incompatible bucket empties the page.
        $spotPage = Livewire::test(SeoContractsList::class, ['pricingType' => 'Spot'])
            ->set('pricingBucketFilter', 'porssisahko');
        $this->assertSame(['c-spot'], $this->listedIds($spotPage));

        $contradiction = Livewire::test(SeoContractsList::class, ['pricingType' => 'Spot'])
            ->set('pricingBucketFilter', 'kiintea');
        $this->assertSame([], $this->listedIds($contradiction));
    }

    public function test_bill_mode_prices_only_the_bucket_filtered_set(): void
    {
        $this->createContract('c-fixed', [], 5.0);
        $this->createContract('c-reset', [
            ...$this->canonicalAttributes(
                recurringSchedule: CanonicalPricingFixture::recurringSchedule(
                    cadence: 'quarterly',
                    currentPeriodStart: null,
                    currentPeriodEnd: null,
                    futurePriceKnown: null,
                ),
            ),
        ], 12.0);

        $component = Livewire::test(SahkosopimusIndex::class)
            ->set('pricingBucketFilter', 'kiintea')
            // 30-day period so period months is exactly 1.
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 300)
            ->set('billTotalEur', 40.00);

        $this->assertTrue($component->instance()->isBillModeActive());
        $this->assertSame(['c-fixed'], $this->listedIds($component));

        // The excluded contract is out of the ranking too: the user plus one contract.
        $this->assertSame(2, $component->instance()->billSummary['total_ranked']);
    }

    // ---------------------------------------------------------------- the visible pill row

    /**
     * The pill row is the whole point of the feature: it must be on every contract listing
     * page without opening anything. Company pages keep their own template and never
     * include the partial, so they are deliberately not in this list. `/` is not here
     * either: it serves the marketing `HomePage` component, not a contract listing. The
     * base `ContractsList` template is covered by the Livewire assertions below.
     */
    public function test_the_pill_row_renders_on_every_contract_listing_page(): void
    {
        $this->createOnePerBucket();

        foreach ([
            '/sahkosopimus',
            '/sahkosopimus/omakotitalo',
            '/sahkosopimus/halvin-sahkosopimus',
        ] as $url) {
            $html = $this->get($url)->assertStatus(200)->getContent();

            foreach (PricingBucket::cases() as $bucket) {
                $this->assertStringContainsString(
                    'data-pricing-bucket-pill="'.$bucket->value.'"',
                    $html,
                    "the {$bucket->value} pill is missing from {$url}"
                );
            }
        }
    }

    public function test_the_pills_are_crawlable_links_on_sahkosopimus_when_no_filter_is_active(): void
    {
        $this->createOnePerBucket();

        $html = $this->get('/sahkosopimus')->getContent();

        foreach ([
            'porssisahko' => '/sahkosopimus/porssisahko',
            'kulutusvaikutus' => '/sahkosopimus/kulutusvaikutus',
            'kiintea' => '/sahkosopimus/kiintea-hinta',
        ] as $key => $seoUrl) {
            $this->assertMatchesRegularExpression(
                '/data-pricing-bucket-pill="'.$key.'"[^>]*href="'.preg_quote($seoUrl, '/').'"/',
                $html,
                "the {$key} pill should link to {$seoUrl}"
            );
        }

        // Päivittyvä hinta owns no canonical SEO page, so it is a toggle in every state.
        $this->assertDoesNotMatchRegularExpression(
            '/data-pricing-bucket-pill="paivittyva"[^>]*href=/',
            $html
        );
    }

    public function test_an_active_filter_turns_every_pill_back_into_a_livewire_toggle(): void
    {
        $this->createOnePerBucket();

        // One active filter is enough: filter combinations must never become crawlable URLs.
        $html = $this->get('/sahkosopimus?hintatyyppi=kiintea')->getContent();

        foreach (PricingBucket::cases() as $bucket) {
            $this->assertDoesNotMatchRegularExpression(
                '/data-pricing-bucket-pill="'.$bucket->value.'"[^>]*href=/',
                $html,
                "the {$bucket->value} pill stayed a link while a filter was active"
            );
        }
    }

    public function test_listing_pages_that_do_not_opt_in_render_the_pills_as_toggles(): void
    {
        $this->createOnePerBucket();

        foreach (['/sahkosopimus/omakotitalo', '/sahkosopimus/halvin-sahkosopimus'] as $url) {
            $html = $this->get($url)->getContent();

            foreach (PricingBucket::cases() as $bucket) {
                $this->assertDoesNotMatchRegularExpression(
                    '/data-pricing-bucket-pill="'.$bucket->value.'"[^>]*href=/',
                    $html,
                    "{$url} rendered a crawlable {$bucket->value} pill"
                );
            }
        }

        // The base ContractsList template opts out too.
        Livewire::test(ContractsList::class)
            ->assertSee('data-pricing-bucket-pill="porssisahko"', false)
            ->assertDontSee('href="/sahkosopimus/porssisahko"', false);
    }

    // ---------------------------------------------------------------- accordion scoping

    public function test_a_pill_selection_does_not_open_the_accordion_or_inflate_its_badge(): void
    {
        $component = Livewire::test(ContractsList::class)->set('pricingBucketFilter', 'kiintea');

        // It is still an active filter (it gates "Tyhjennä suodattimet" and the cache),
        // but it is not hosted by the accordion.
        $this->assertTrue($component->instance()->hasActiveFilters());
        $this->assertFalse($component->instance()->hasActiveAccordionFilters());
        $this->assertSame(0, $component->instance()->activeAccordionFilterCount());
        $component->assertSee('x-data="{ filtersOpen: false }"', false);

        // An accordion-hosted filter still opens it and shows its badge.
        $withDuration = Livewire::test(ContractsList::class)
            ->set('pricingBucketFilter', 'kiintea')
            ->set('contractTypeFilter', 'FixedTerm');

        $this->assertSame(1, $withDuration->instance()->activeAccordionFilterCount());
        $withDuration->assertSee('x-data="{ filtersOpen: true }"', false);
    }

    public function test_the_accordion_no_longer_hosts_the_pricing_model_section(): void
    {
        Livewire::test(ContractsList::class)
            ->assertDontSee('setPricingModelFilter(', false)
            ->assertSee('Sopimuksen kesto')
            ->assertSee('Energialähde');
    }

    // ---------------------------------------------------------------- enum coverage

    public function test_every_bucket_case_is_reachable_from_the_filter_state(): void
    {
        foreach (PricingBucket::cases() as $bucket) {
            $component = Livewire::test(ContractsList::class)->set('pricingBucketFilter', $bucket->value);

            $this->assertSame([$bucket], $component->instance()->selectedPricingBuckets());
            $this->assertTrue($component->instance()->isPricingBucketSelected($bucket->value));
        }
    }
}
