<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\ContractStatistics\ContractStatisticsSegmentClassifier;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/sahkosopimus/tilastot` must keep counting the contracts whose source price
 * components the interpretation gate withheld.
 *
 * Those are not contracts Voltikka cannot price — they are the ones whose raw
 * structured price was found untrustworthy (promo-only rows, an omitted later
 * price), which is exactly when canonical pricing is the more reliable figure.
 */
class ContractPriceStatisticsCanonicalSourceTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-27';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DATE.' 09:00:00', 'Europe/Helsinki'));
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        Company::create(['name' => 'Tyyni Energia Oy', 'name_slug' => 'tyyni-energia-oy']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_gated_contract_is_priced_from_canonical_phases_instead_of_being_dropped(): void
    {
        // A promo contract: the structured rows held only the intro price, so the gate
        // withheld them. The canonical phases carry both prices.
        $contract = $this->createContract('promo-1', [
            $this->phase('Aloitushinta', 'introductory', 4.0, $this->boundary('contract_start'), $this->boundary('after_months', '6')),
            $this->phase('Normaalihinta', 'normal', 12.0, $this->boundary('after_months', '6'), $this->boundary('none')),
        ]);

        $result = $this->calculate();

        $this->assertSame(1, $result['snapshots']);
        $snapshot = ContractPriceSnapshot::sole();
        $this->assertSame($contract->id, $snapshot->contract_id);
        $this->assertNotNull($snapshot->annual_cost_5000_kwh);

        // Half a year at 4 c/kWh and half at 12 averages well above the intro price, so the
        // recorded year cannot be the promo price alone.
        $this->assertGreaterThan(5000 * 0.04, (float) $snapshot->annual_cost_5000_kwh);

        // The current unit rate also comes from the typed canonical outcome. It is
        // available even though no relational row exists.
        $this->assertSame(4.0, (float) $snapshot->energy_price_cents_per_kwh);
        $this->assertSame(0.0, (float) $snapshot->monthly_fee_eur);
        $this->assertSame('canonical_calculation', $snapshot->pricing_basis);
    }

    public function test_a_contract_canonical_pricing_refuses_to_total_is_still_skipped(): void
    {
        // Vimpelin Voima's shape: the pre-discount price list is undisclosed, so the
        // continuation phase has no components and canonical declines to price the year.
        $contract = $this->createContract('incomplete-1', [
            $this->phase('Alennettu hinnasto', 'introductory', 5.0, $this->boundary('contract_start'), $this->boundary('after_months', '3')),
            [
                'label' => 'Alennusta edeltänyt hinnasto',
                'phase_kind' => 'continuation',
                'starts' => $this->boundary('after_months', '3'),
                'ends' => $this->boundary('none'),
                'components' => [],
                'evidence' => [],
            ],
        ], calculationStatus: 'incomplete');
        $this->createRelationalComponent($contract, 'General', 5.0);

        $result = $this->calculate();

        $this->assertSame(0, $result['snapshots'], 'An all-null row helps nobody.');
        $this->assertSame(0, ContractPriceSnapshot::count());
    }

    public function test_legacy_calculation_still_requires_relational_components(): void
    {
        // With canonical pricing off there is nothing else to read, and historical
        // backfills always take this path.
        config()->set('canonical_pricing.enabled', false);
        $this->createContract('promo-2', [
            $this->phase('Aloitushinta', 'introductory', 4.0, $this->boundary('contract_start'), $this->boundary('after_months', '6')),
            $this->phase('Normaalihinta', 'normal', 12.0, $this->boundary('after_months', '6'), $this->boundary('none')),
        ]);

        $result = $this->calculate();

        $this->assertSame(0, $result['snapshots']);
    }

    public function test_canonical_current_rate_wins_when_the_relational_rate_conflicts(): void
    {
        $contract = $this->createContract('normal-1', [
            $this->phase('Nykyinen', 'current_structured', 9.0, $this->boundary('contract_start'), $this->boundary('none')),
        ]);
        PriceComponent::create([
            'id' => 'pc-normal-1',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => self::DATE,
            'price' => 3.0,
            'payment_unit' => 'c/kWh',
        ]);

        $this->calculate();

        $this->assertSame(9.0, (float) ContractPriceSnapshot::sole()->energy_price_cents_per_kwh);
    }

    public function test_a_missing_canonical_unit_rate_stays_null_even_when_a_relational_rate_exists(): void
    {
        $contract = $this->createContract('fee-only-1', [
            $this->phaseWithComponents([
                $this->canonicalComponent('monthly_fee', 4.0, 'eur_per_month'),
            ]),
        ]);
        $this->createRelationalComponent($contract, 'General', 8.0);

        $this->calculate();

        $snapshot = ContractPriceSnapshot::sole();
        $this->assertNull($snapshot->energy_price_cents_per_kwh);
        $this->assertSame(4.0, (float) $snapshot->monthly_fee_eur);
        $this->assertSame(48.0, (float) $snapshot->annual_cost_5000_kwh);
    }

    public function test_a_package_keeps_annual_total_and_fee_but_omits_an_all_in_energy_rate_and_offer(): void
    {
        $contract = $this->createContract('package-1', [[
            'label' => 'Kuukausipaketti',
            'phase_kind' => 'current_structured',
            'starts' => $this->boundary('contract_start'),
            'ends' => $this->boundary('none'),
            'components' => [],
            'package' => [
                'monthly_fee_eur' => 21.0,
                'included_kwh' => 150.0,
                'allowance_cadence' => 'monthly',
                'excess_rate_cents_per_kwh' => 16.6,
                'evidence' => [],
            ],
            'evidence' => [],
        ]]);
        $this->createRelationalComponent($contract, 'General', 16.6);

        $this->calculate();

        $snapshot = ContractPriceSnapshot::sole();
        $this->assertNotNull($snapshot->annual_cost_5000_kwh);
        $this->assertNull($snapshot->energy_price_cents_per_kwh);
        $this->assertSame(21.0, (float) $snapshot->monthly_fee_eur);
        $this->assertFalse($snapshot->has_discount);
    }

    public function test_spot_margin_and_total_come_from_the_canonical_outcome(): void
    {
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => '2025-07-28',
            'period_end' => '2026-07-27',
            'avg_price_without_tax' => 6.0,
            'avg_price_with_tax' => 6.0,
            'day_avg_with_tax' => 7.0,
            'night_avg_with_tax' => 3.0,
            'hours_count' => 8760,
        ]);
        $this->future('month', '202607', '2026-06-30', 80.0);
        $this->future('year', '202601', '2026-07-26', 80.0);
        $this->future('year', '202701', '2026-07-26', 80.0);

        $contract = $this->createContract('spot-1', [
            $this->phaseWithComponents([
                $this->canonicalComponent('spot_margin', 0.7),
                $this->canonicalComponent('monthly_fee', 4.0, 'eur_per_month'),
            ]),
        ], calculationStatus: 'estimate_required', pricingModel: 'Spot');
        $this->createRelationalComponent($contract, 'General', 0.2);

        $outcome = app(CanonicalContractPricingService::class)->evaluate(
            $contract,
            new EnergyUsage(total: 5000, basicLiving: 5000),
            startDate: Carbon::parse(self::DATE),
        )['outcome'];
        $this->assertTrue($outcome->isListed(), $outcome->comparability->value);
        $this->assertSame('forward_curve_spot', $outcome->estimateMethod->value);
        $statistics = app(ContractPriceStatisticsService::class);
        $spotEvidenceQueries = 0;
        DB::listen(function ($query) use (&$spotEvidenceQueries): void {
            if (str_contains($query->sql, 'spot_price_averages')) {
                $spotEvidenceQueries++;
            }
        });
        $spotMethod = new \ReflectionMethod($statistics, 'canonicalSpotAssumptions');
        $assumptions = $spotMethod->invoke($statistics, self::DATE);
        $spotMethod->invoke($statistics, self::DATE);
        $this->assertSame(1, $spotEvidenceQueries, 'Rolling evidence must be memoized for all contracts and consumptions.');
        $this->assertSame(7.0, $assumptions->dayAvgWithTax);
        $this->assertSame(3.0, $assumptions->nightAvgWithTax);
        $this->assertSame(6.0, $assumptions->overallAvgWithTax);
        $this->assertSame('2025-07-28', $assumptions->periodStart?->toDateString());
        $this->assertSame('2026-07-27', $assumptions->periodEnd?->toDateString());

        $statistics->calculateForDate(self::DATE, ActiveContract::query()->pluck('id'));

        $snapshot = ContractPriceSnapshot::sole();
        $this->assertSame(0.7, (float) $snapshot->spot_margin_cents_per_kwh);
        $this->assertSame(10.24, (float) $snapshot->spot_total_energy_price_cents_per_kwh);
        $this->assertSame(10.24, (float) $snapshot->energy_price_cents_per_kwh);
        $this->assertTrue($snapshot->includes_spot_price);
    }

    public function test_time_and_season_rates_use_only_the_canonical_current_outcome(): void
    {
        $time = $this->createContract('time-1', [
            $this->phaseWithComponents([
                $this->canonicalComponent('energy_day', 10.0),
                $this->canonicalComponent('energy_night', 2.0),
            ]),
        ], metering: 'Time');
        $season = $this->createContract('season-1', [
            $this->phaseWithComponents([
                $this->canonicalComponent('energy_seasonal_winter', 12.0),
                $this->canonicalComponent('energy_seasonal_other', 6.0),
            ]),
        ], metering: 'Season');
        $this->createRelationalComponent($time, 'General', 1.0);
        $this->createRelationalComponent($season, 'General', 1.0);

        $this->calculate();

        $snapshots = ContractPriceSnapshot::all()->keyBy('contract_id');
        $this->assertEqualsWithDelta(7.0, $snapshots['time-1']->energy_price_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(8.5, $snapshots['season-1']->energy_price_cents_per_kwh, 0.0001);
    }

    public function test_measured_canonical_offer_sets_the_snapshot_offer_flag(): void
    {
        $this->createContract('offer-1', [
            $this->phaseWithComponents([
                $this->canonicalComponent('energy_general', 7.0, normalAmount: 9.0),
            ]),
        ]);

        $this->calculate();

        $this->assertTrue(ContractPriceSnapshot::sole()->has_discount);
    }

    public function test_a_relational_offer_does_not_set_the_canonical_offer_flag(): void
    {
        $contract = $this->createContract('no-offer-1', [
            $this->phaseWithComponents([
                $this->canonicalComponent('energy_general', 7.0),
            ]),
        ]);
        $this->createRelationalComponent($contract, 'General', 3.0);
        PriceComponent::query()
            ->where('electricity_contract_id', $contract->id)
            ->update([
                'has_discount' => true,
                'discount_value' => 2.0,
                'discount_is_percentage' => false,
            ]);

        $this->calculate();

        $this->assertFalse(ContractPriceSnapshot::sole()->has_discount);
    }

    public function test_feature_off_keeps_the_relational_forward_calculation(): void
    {
        config()->set('canonical_pricing.enabled', false);
        $contract = $this->createContract('legacy-1', [
            $this->phase('Nykyinen', 'current_structured', 9.0, $this->boundary('contract_start'), $this->boundary('none')),
        ]);
        $this->createRelationalComponent($contract, 'General', 7.0);
        $this->createRelationalComponent($contract, 'Monthly', 3.0);

        $this->calculate();

        $snapshot = ContractPriceSnapshot::sole();
        $this->assertSame(7.0, (float) $snapshot->energy_price_cents_per_kwh);
        $this->assertSame(3.0, (float) $snapshot->monthly_fee_eur);
        $this->assertSame(386.0, (float) $snapshot->annual_cost_5000_kwh);
        $this->assertSame('observed_seller_data', $snapshot->pricing_basis);
    }

    public function test_each_calculation_date_has_one_basis_and_stale_excluded_snapshots_are_removed(): void
    {
        $contract = $this->createContract('ownership-1', [
            $this->phase('Nykyinen', 'current_structured', 9.0, $this->boundary('contract_start'), $this->boundary('none')),
        ]);
        $this->createRelationalComponent($contract, 'General', 3.0);
        PriceComponent::create([
            'id' => 'pc-ownership-history',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => '2026-07-26',
            'price' => 3.0,
            'payment_unit' => 'c/kWh',
        ]);

        $service = app(ContractPriceStatisticsService::class);
        $service->calculateForDate('2026-07-26', [$contract->id], useCanonical: false);
        $service->calculateForDate(self::DATE, [$contract->id], useCanonical: false);
        $this->assertSame('observed_seller_data', ContractPriceSnapshot::whereDate('snapshot_date', self::DATE)->sole()->pricing_basis);

        $service->calculateForDate(self::DATE, [$contract->id], useCanonical: true);
        $this->assertSame(1, ContractPriceSnapshot::whereDate('snapshot_date', self::DATE)->count());
        $this->assertSame('canonical_calculation', ContractPriceSnapshot::whereDate('snapshot_date', self::DATE)->sole()->pricing_basis);
        $this->assertFalse(ContractPriceDailyStatistic::whereDate('stat_date', self::DATE)
            ->where('pricing_basis', 'observed_seller_data')->exists());
        $this->assertSame(3, ContractPriceAnnualCost::query()
            ->whereDate('snapshot_date', self::DATE)
            ->where('contract_id', $contract->id)
            ->count());

        $contract->update([
            'canonical_pricing' => [
                ...$contract->canonical_pricing,
                'phases' => [[
                    'label' => 'Tuntematon jatko',
                    'phase_kind' => 'continuation',
                    'starts' => $this->boundary('contract_start'),
                    'ends' => $this->boundary('none'),
                    'components' => [],
                    'evidence' => [],
                ]],
            ],
            'canonical_calculation' => ['status' => 'incomplete', 'missing_facts' => ['price'], 'required_assumptions' => []],
        ]);

        $result = $service->calculateForDate(self::DATE, [$contract->id], useCanonical: true);

        $this->assertSame(0, $result['snapshots']);
        $this->assertFalse(ContractPriceSnapshot::whereDate('snapshot_date', self::DATE)->exists());
        $this->assertFalse(ContractPriceAnnualCost::query()
            ->whereDate('snapshot_date', self::DATE)
            ->where('contract_id', $contract->id)
            ->exists());
        $this->assertTrue(ContractPriceSnapshot::whereDate('snapshot_date', '2026-07-26')
            ->where('pricing_basis', 'observed_seller_data')->exists(), 'Other historical dates must stay intact.');
    }

    public function test_feature_off_run_takes_ownership_from_a_canonical_run_on_the_same_date(): void
    {
        $contract = $this->createContract('feature-off-owner-1', [
            $this->phase('Nykyinen', 'current_structured', 9.0, $this->boundary('contract_start'), $this->boundary('none')),
        ]);
        $this->createRelationalComponent($contract, 'General', 3.0);
        $service = app(ContractPriceStatisticsService::class);

        $service->calculateForDate(self::DATE, [$contract->id], useCanonical: true);
        $service->calculateForDate(self::DATE, [$contract->id], useCanonical: false);

        $this->assertSame(1, ContractPriceSnapshot::whereDate('snapshot_date', self::DATE)->count());
        $this->assertSame('observed_seller_data', ContractPriceSnapshot::whereDate('snapshot_date', self::DATE)->sole()->pricing_basis);
        $this->assertFalse(ContractPriceDailyStatistic::whereDate('stat_date', self::DATE)
            ->whereIn('method_version', [
                ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
                \App\Services\ContractStatistics\Enums\AnnualCostMethodVersion::Legacy->value,
            ])
            ->where('pricing_basis', 'canonical_calculation')->exists());
    }

    public function test_historical_mode_keeps_the_observed_relational_rate_and_basis(): void
    {
        $contract = $this->createContract('history-1', [
            $this->phase('Nykyinen', 'current_structured', 9.0, $this->boundary('contract_start'), $this->boundary('none')),
        ]);
        $this->createRelationalComponent($contract, 'General', 3.0);

        app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            [$contract->id],
            useCanonical: false,
        );

        $snapshot = ContractPriceSnapshot::sole();
        $this->assertSame(3.0, (float) $snapshot->energy_price_cents_per_kwh);
        $this->assertSame('observed_seller_data', $snapshot->pricing_basis);
    }

    public function test_canonical_reset_cadences_share_the_market_reset_segment(): void
    {
        foreach (['monthly', 'quarterly', 'seasonal', 'other'] as $cadence) {
            $this->createContract(
                'reset-'.$cadence,
                [$this->phase('Nykyinen', 'current_structured', 8.0, $this->boundary('contract_start'), $this->boundary('none'))],
                calculationStatus: 'estimate_required',
                recurringCadence: $cadence,
            );
        }

        $this->calculate();

        $this->assertSame(
            ['market_reset'],
            ContractPriceSnapshot::query()->distinct()->pluck('segment_key')->all(),
        );
        $this->assertSame(4, ContractPriceSnapshot::count());
    }

    public function test_canonical_segment_precedence_matches_the_shared_pricing_bucket(): void
    {
        $spot = $this->createContract(
            'spot-reset',
            [$this->phaseWithComponents([$this->canonicalComponent('spot_margin', 0.5)])],
            calculationStatus: 'estimate_required',
            pricingModel: 'Spot',
            recurringCadence: 'quarterly',
        );
        $hybrid = $this->createContract(
            'hybrid-reset',
            [$this->phase('Nykyinen', 'current_structured', 8.0, $this->boundary('contract_start'), $this->boundary('none'))],
            calculationStatus: 'unsupported',
            pricingModel: 'Hybrid',
            recurringCadence: 'seasonal',
        );
        $classifier = app(ContractStatisticsSegmentClassifier::class);

        $this->assertSame('spot', $classifier->classify($spot, ContractPriceBasis::CanonicalCalculation));
        $this->assertSame('market_reset', $classifier->classify($hybrid, ContractPriceBasis::CanonicalCalculation));
    }

    public function test_canonical_mode_does_not_use_observed_quarterly_text_without_a_reset_schedule(): void
    {
        $contract = $this->createContract(
            'canonical-text-only-quarterly',
            [$this->phase('Nykyinen', 'current_structured', 8.0, $this->boundary('contract_start'), $this->boundary('none'))],
        );
        $contract->update(['extra_information_fi' => 'Hinta muuttuu neljä kertaa vuodessa.']);
        $classifier = app(ContractStatisticsSegmentClassifier::class);

        $this->assertSame('open_ended', $classifier->classify($contract, ContractPriceBasis::CanonicalCalculation));
        $this->assertSame('quarterly', $classifier->classify($contract, ContractPriceBasis::ObservedSellerData));
    }

    public function test_observed_mode_uses_only_the_old_text_classification(): void
    {
        $canonicalOnly = [];
        foreach (['monthly', 'quarterly', 'seasonal', 'other'] as $cadence) {
            $contract = $this->createContract(
                'observed-canonical-'.$cadence,
                [$this->phase('Nykyinen', 'current_structured', 8.0, $this->boundary('contract_start'), $this->boundary('none'))],
                recurringCadence: $cadence,
            );
            $this->createRelationalComponent($contract, 'General', 8.0);
            $canonicalOnly[] = $contract;
        }

        $textQuarterly = $this->createContract(
            'observed-text-quarterly',
            [$this->phase('Nykyinen', 'current_structured', 8.0, $this->boundary('contract_start'), $this->boundary('none'))],
        );
        $textQuarterly->update(['extra_information_fi' => 'Hinta muuttuu neljä kertaa vuodessa.']);
        $this->createRelationalComponent($textQuarterly, 'General', 8.0);

        app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            [...array_map(fn (ElectricityContract $contract) => $contract->id, $canonicalOnly), $textQuarterly->id],
            useCanonical: false,
        );

        $segments = ContractPriceSnapshot::query()->pluck('segment_key', 'contract_id');
        foreach ($canonicalOnly as $contract) {
            $this->assertSame('open_ended', $segments[$contract->id]);
        }
        $this->assertSame('quarterly', $segments[$textQuarterly->id]);
    }

    public function test_canonical_current_collection_does_not_query_components_and_batches_current_provenance(): void
    {
        $contract = $this->createContract('query-1', [
            $this->phase('Nykyinen', 'current_structured', 9.0, $this->boundary('contract_start'), $this->boundary('none')),
        ]);
        $this->createRelationalComponent($contract, 'General', 3.0);

        DB::enableQueryLog();
        $this->calculate();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(
            0,
            $queries->filter(fn (string $query) => str_contains($query, 'price_components'))->count(),
        );
        $this->assertSame(
            1,
            $queries->filter(fn (string $query) => str_contains($query, 'contract_price_snapshots')
                && str_contains($query, 'contract_source_observations'))->count(),
            'Current snapshot and source-pointer provenance must load in one batch.',
        );
        $this->assertLessThanOrEqual(
            2,
            $queries->filter(fn (string $query) => str_contains($query, 'contract_source_observations'))->count(),
        );
        $this->assertLessThanOrEqual(
            2,
            $queries->filter(fn (string $query) => str_contains($query, 'contract_interpretations'))->count(),
        );
    }

    /**
     * @return array{snapshots:int, statistics:int}
     */
    private function calculate(): array
    {
        return app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            ActiveContract::query()->pluck('id'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     */
    private function createContract(
        string $id,
        array $phases,
        string $calculationStatus = 'exact',
        string $pricingModel = 'FixedPrice',
        string $metering = 'General',
        ?string $recurringCadence = null,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Tyyni Energia Oy',
            'name' => 'Tyyni '.$id,
            'contract_type' => 'OpenEnded',
            'pricing_model' => $pricingModel,
            'metering' => $metering,
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => [
                'phases' => $phases,
                'recurring_schedule' => [
                    'present' => $recurringCadence !== null, 'cadence' => $recurringCadence ?? 'none', 'current_period_start' => null,
                    'current_period_end' => null, 'future_price_known' => $recurringCadence === null ? null : false,
                    'description' => null, 'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false, 'applies_to' => 'unknown', 'cadence' => 'none',
                    'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null, 'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null, 'uncapped' => null,
                    'description' => null, 'evidence' => [],
                ],
            ],
            'canonical_calculation' => ['status' => $calculationStatus, 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'not_detected',
                'structured_pricing_status' => 'complete',
                'issue_codes' => [],
            ],
        ]);

        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    /**
     * @param  array<string, mixed>  $starts
     * @param  array<string, mixed>  $ends
     * @return array<string, mixed>
     */
    private function phase(string $label, string $kind, float $cents, array $starts, array $ends): array
    {
        return [
            'label' => $label,
            'phase_kind' => $kind,
            'starts' => $starts,
            'ends' => $ends,
            'components' => [[
                'component_type' => 'energy_general',
                'amount' => $cents,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'included',
                'price_role' => 'current',
                'source_kind' => 'both',
                'evidence' => [],
            ]],
            'evidence' => [],
        ];
    }

    /** @param list<array<string, mixed>> $components */
    private function phaseWithComponents(array $components): array
    {
        return [
            'label' => 'Nykyinen',
            'phase_kind' => 'current_structured',
            'starts' => $this->boundary('contract_start'),
            'ends' => $this->boundary('none'),
            'components' => $components,
            'evidence' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function canonicalComponent(
        string $type,
        float $amount,
        string $unit = 'cents_per_kwh',
        ?float $normalAmount = null,
    ): array {
        return [
            'component_type' => $type,
            'amount' => $amount,
            'normal_amount' => $normalAmount,
            'unit' => $unit,
            'vat_status' => 'included',
            'price_role' => 'current',
            'source_kind' => 'both',
            'evidence' => [],
        ];
    }

    private function createRelationalComponent(ElectricityContract $contract, string $type, float $price): void
    {
        PriceComponent::create([
            'id' => 'pc-'.$contract->id.'-'.$type,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => $type,
            'price_date' => self::DATE,
            'price' => $price,
            'payment_unit' => $type === 'Monthly' ? 'EurPerMonth' : 'c/kWh',
        ]);
    }

    private function future(string $maturityType, string $maturity, string $tradeDate, float $settlementPrice): void
    {
        ElectricityFuturesEodPrice::create([
            'exchange' => 'EEX',
            'commodity' => 'POWER',
            'pricing' => 'F',
            'product' => 'Base',
            'area' => 'FI',
            'short_code' => match ($maturityType) {
                'month' => 'FNBM',
                'quarter' => 'FNBQ',
                'year' => 'FNBY',
            },
            'maturity' => $maturity,
            'maturity_type' => $maturityType,
            'trade_date' => $tradeDate,
            'settlement_price' => $settlementPrice,
        ]);
    }

    /**
     * @return array{kind:string, value:?string}
     */
    private function boundary(string $kind, ?string $value = null): array
    {
        return ['kind' => $kind, 'value' => $value];
    }
}
