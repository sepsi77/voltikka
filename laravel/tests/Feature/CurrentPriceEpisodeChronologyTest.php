<?php

namespace Tests\Feature;

use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentPriceEpisodeChronologyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.episodes', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::setDefaultConnection('episodes');
        DB::statement('CREATE TABLE contract_price_snapshots (contract_id TEXT, snapshot_date TEXT, pricing_basis TEXT, energy_price_cents_per_kwh REAL, monthly_fee_eur REAL, metering TEXT, pricing_model TEXT, contract_type TEXT)');
        DB::statement('CREATE TABLE electricity_contracts (id TEXT PRIMARY KEY, replaced_by_contract_id TEXT, current_source_observation_id INTEGER, published_interpretation_id INTEGER)');
        DB::statement('CREATE TABLE contract_source_observations (id INTEGER, contract_id TEXT, source_snapshot_id INTEGER, first_observed_at TEXT, last_observed_at TEXT)');
        DB::statement('CREATE TABLE contract_source_snapshots (id INTEGER, contract_id TEXT, source_payload TEXT)');
        DB::statement('CREATE TABLE price_components (id TEXT, electricity_contract_id TEXT, price_date TEXT, price_component_type TEXT, price REAL, payment_unit TEXT, has_discount INTEGER, discount_value REAL, discount_is_percentage INTEGER, discount_type TEXT, discount_discount_n_first_kwh REAL, discount_discount_n_first_months INTEGER, discount_discount_until_date TEXT)');
        DB::statement('CREATE TABLE contract_interpretations (id INTEGER, contract_id TEXT, source_snapshot_id INTEGER, analysis_source_observation_id INTEGER, status TEXT, output TEXT, validation_errors TEXT, completed_at TEXT)');
    }

    public function test_recurring_price_uses_latest_episode_across_both_bases(): void
    {
        foreach ([['2026-06-01', 8], ['2026-07-01', 10], ['2026-08-01', 8], ['2026-08-02', 8]] as [$date, $rate]) {
            $this->snapshot('a', $date, $rate);
        }
        $anchor = $this->resolve(['a'])['a'];
        $this->assertSame('2026-08-01', $anchor->startedAt?->toDateString());
        $this->assertContains('prior_energy_evidence_changed_unknown_or_conflicting', $anchor->flags);
    }

    public function test_daily_duplicates_are_deterministic_with_local_observed_precedence(): void
    {
        foreach (['forward', 'reverse'] as $id) {
            $rows = [
                ['2026-08-01', 8, 'observed_seller_data'],
                ['2026-08-01', 10, 'canonical_calculation'],
                ['2026-08-02', 8, 'canonical_calculation'],
                ['2026-08-02', null, 'observed_seller_data'],
                ['2026-08-03', 8, 'canonical_calculation'],
            ];
            foreach ($id === 'forward' ? $rows : array_reverse($rows) as [$date, $rate, $basis]) {
                $this->snapshot($id, $date, $rate, $basis);
            }
        }
        foreach ($this->resolve(['forward', 'reverse']) as $anchor) {
            $this->assertSame('2026-08-01', $anchor->startedAt?->toDateString());
            $this->assertSame(PriceEpisodeEvidenceBasis::CanonicalSnapshotRun, $anchor->evidenceBasis);
        }
    }

    public function test_missing_days_are_uncertain_not_a_known_repricing(): void
    {
        $this->snapshot('gap', '2026-08-01', 8);
        $this->snapshot('gap', '2026-08-03', 8);
        $anchor = $this->resolve(['gap'])['gap'];
        $this->assertSame('2026-08-01', $anchor->startedAt?->toDateString());
        $this->assertContains('price_episode_observation_gap', $anchor->flags);
        $this->assertContains('price_episode_left_censored', $anchor->flags);
        $this->assertContains('price_episode_right_observation_gap', $anchor->flags);
    }

    public function test_unknown_and_conflicting_latest_evidence_cannot_reuse_an_old_anchor(): void
    {
        foreach (['unknown', 'conflict'] as $id) {
            $this->snapshot($id, '2026-07-01', 8);
            $this->snapshot($id, '2026-08-01', $id === 'unknown' ? null : 8);
        }
        $this->snapshot('conflict', '2026-08-01', 10);
        foreach ($this->resolve(['unknown', 'conflict']) as $anchor) {
            $this->assertNull($anchor->startedAt);
            $this->assertContains('prior_energy_evidence_changed_unknown_or_conflicting', $anchor->flags);
        }
    }

    public function test_lineage_and_fee_changes_preserve_anchor_with_cycles_and_one_batch(): void
    {
        foreach (['old', 'branch', 'new', 'self'] as $id) {
            $this->snapshot($id, $id === 'new' ? '2026-08-02' : '2026-08-01', 8);
        }
        DB::table('electricity_contracts')->whereIn('id', ['old', 'branch'])->update(['replaced_by_contract_id' => 'new']);
        DB::table('electricity_contracts')->where('id', 'new')->update(['replaced_by_contract_id' => 'old']);
        DB::table('electricity_contracts')->where('id', 'self')->update(['replaced_by_contract_id' => 'self']);
        DB::table('contract_price_snapshots')->where('contract_id', 'new')->update(['monthly_fee_eur' => 99]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $anchors = $this->resolve(['new', 'old', 'self', 'absent']);
        $this->assertCount(4, DB::getQueryLog());
        foreach (['new', 'old', 'self'] as $id) {
            $this->assertSame('2026-08-01', $anchors[$id]->startedAt?->toDateString());
        }
        $this->assertNull($anchors['absent']->startedAt);
    }

    public function test_future_snapshots_do_not_change_an_explicit_as_of_anchor(): void
    {
        $this->snapshot('a', '2026-08-01', 8);
        $this->snapshot('a', '2026-09-01', 10);
        $this->assertSame('2026-08-01', $this->resolve(['a'])['a']->startedAt?->toDateString());
    }

    public function test_full_time_rates_detect_same_average_changes_and_source_recurrence(): void
    {
        $this->source('a', 1, '2026-06-01', ['energy_day' => 10, 'energy_night' => 6]);
        $this->source('a', 2, '2026-07-01', ['energy_day' => 9.4, 'energy_night' => 7]);
        $this->source('a', 3, '2026-08-01', ['energy_day' => 10, 'energy_night' => 6]);
        $candidate = new SupplierAdjustedCandidate('a', 8.5, 0, ['energy_day' => 10.0, 'energy_night' => 6.0], 'Time');
        $anchor = (new CurrentPriceEpisodeResolver)->resolve(['a' => $candidate], CarbonImmutable::parse('2026-08-10', 'Europe/Helsinki'))['a'];
        $this->assertSame('2026-08-01', $anchor->startedAt?->toDateString());
        $this->assertSame(PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun, $anchor->evidenceBasis);
        $this->assertContains('prior_energy_evidence_changed_unknown_or_conflicting', $anchor->flags);
    }

    public function test_immutable_fee_only_and_id_only_changes_keep_the_full_energy_anchor(): void
    {
        $rates = ['energy_day' => 10, 'energy_night' => 6];
        $this->source('old', 1, '2026-07-01', $rates, fee: 4);
        $this->source('new', 2, '2026-08-01', $rates, fee: 9);
        DB::table('electricity_contracts')->where('id', 'old')->update(['replaced_by_contract_id' => 'new']);
        $candidate = new SupplierAdjustedCandidate('new', 8.5, 9, $rates, 'Time');
        $anchor = (new CurrentPriceEpisodeResolver)->resolve(['new' => $candidate], CarbonImmutable::parse('2026-08-10', 'Europe/Helsinki'))['new'];
        $this->assertSame('2026-07-01', $anchor->startedAt?->toDateString());
        $this->assertContains('price_episode_observation_gap', $anchor->flags);
    }

    public function test_immutable_fee_phase_lineage_preserves_anchor_but_real_energy_change_breaks_it(): void
    {
        foreach ([['energy_general' => 8], ['energy_day' => 10, 'energy_night' => 6], ['energy_seasonal_winter' => 10, 'energy_seasonal_other' => 6]] as $index => $rates) {
            $old = 'old-'.$index;
            $new = 'new-'.$index;
            $this->source($old, 10 + $index * 2, '2026-06-01', $rates, fee: 4);
            $this->source($new, 11 + $index * 2, '2026-07-01', $rates, fee: 9, feePhases: true);
            DB::table('electricity_contracts')->where('id', $old)->update(['replaced_by_contract_id' => $new]);
            $metering = $index === 0 ? 'General' : ($index === 1 ? 'Time' : 'Season');
            $anchor = $this->resolveTariff($new, $rates, $metering);
            $this->assertSame('2026-06-01', $anchor->startedAt?->toDateString());
            $this->assertSame('canonical_source_observation_run', $anchor->evidenceBasis->value);
            $row = DB::table('contract_interpretations')->where('id', 11 + $index * 2)->first();
            $output = json_decode($row->output, true);
            $output['pricing']['phases'][1]['components'][0]['amount'] += 1;
            DB::table('contract_interpretations')->where('id', $row->id)->update(['output' => json_encode($output)]);
            $this->assertNull($this->resolveTariff($new, $rates, $metering)->startedAt);
        }
    }

    public function test_invalid_immutable_evidence_blocks_snapshot_fallback_and_future_interpretations(): void
    {
        $this->snapshot('a', '2026-06-01', 8);
        $this->source('a', 1, '2026-07-01', ['energy_general' => 8]);
        $this->snapshot('a', '2026-08-01', 8);
        DB::table('contract_interpretations')->update(['completed_at' => '2026-09-01 00:00:00']);
        $this->assertNull($this->resolve(['a'])['a']->startedAt);
        DB::table('contract_interpretations')->update(['completed_at' => '2026-07-01 00:00:00', 'validation_errors' => '["invalid"]']);
        $this->assertNull($this->resolve(['a'])['a']->startedAt);
        DB::table('contract_interpretations')->update(['validation_errors' => '[]']);
        DB::table('electricity_contracts')->update(['current_source_observation_id' => 1, 'published_interpretation_id' => 99]);
        $this->assertNull($this->resolve(['a'])['a']->startedAt);
    }

    public function test_overlapping_conflicting_source_signatures_are_unknown(): void
    {
        $this->source('a', 1, '2026-07-01', ['energy_general' => 8]);
        $this->source('a', 2, '2026-07-02', ['energy_general' => 10]);
        DB::table('contract_source_observations')->update(['last_observed_at' => '2026-08-09 00:00:00']);
        $this->assertNull($this->resolve(['a'])['a']->startedAt);
    }

    public function test_source_vat_normalization_and_continuous_coverage(): void
    {
        $this->source('a', 1, '2026-07-01', ['energy_general' => 8 / 1.255], vat: 'excluded');
        DB::table('contract_source_observations')->update(['last_observed_at' => '2026-08-10 00:00:00']);
        $anchor = $this->resolve(['a'])['a'];
        $this->assertSame('2026-07-01', $anchor->startedAt?->toDateString());
        $this->assertNotContains('price_episode_observation_gap', $anchor->flags);
        $this->assertNotContains('price_episode_right_observation_gap', $anchor->flags);
    }

    public function test_full_signature_ignores_fee_and_key_order_but_preserves_vat_and_mechanism(): void
    {
        $a = new SupplierAdjustedCandidate('a', 8.5, 4, ['energy_day' => 10.0, 'energy_night' => 6.0], 'Time');
        $b = new SupplierAdjustedCandidate('b', 8.5, 9, ['energy_night' => 6.0, 'energy_day' => 10.0], 'Time');
        $this->assertTrue($a->hasSameEnergySignature($b));
        $this->assertFalse($a->hasSameEnergySignature(new SupplierAdjustedCandidate('b', 8.5, 4, ['energy_day' => 9.4, 'energy_night' => 7.0], 'Time')));
        $this->assertFalse($a->hasSameEnergySignature(new SupplierAdjustedCandidate('b', 8.5, 4, $a->energyRates, 'Time', false)));
        $this->assertFalse($a->hasSameEnergySignature(new SupplierAdjustedCandidate('b', 8.5, 4, $a->energyRates, 'Time', true, 'Spot')));
        $this->assertFalse($a->hasSameEnergySignature(new SupplierAdjustedCandidate('b', 8.5, 4, metering: 'Time')));
    }

    public function test_future_source_rows_are_excluded_and_malformed_output_is_missing(): void
    {
        $this->source('a', 1, '2026-07-01', ['energy_general' => 8]);
        $this->source('a', 2, '2026-09-01', ['energy_general' => 10]);
        $this->assertSame('2026-07-01', $this->resolve(['a'])['a']->startedAt?->toDateString());
        DB::table('contract_interpretations')->where('id', 1)->update(['output' => '{"pricing":"invalid","calculation":{},"source_consistency":{}}']);
        $this->assertNull($this->resolve(['a'])['a']->startedAt);
    }

    public function test_empty_batch_does_not_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], (new CurrentPriceEpisodeResolver)->resolve([]));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_observed_full_tariffs_preserve_february_across_missing_days_ids_and_july_sources(): void
    {
        foreach (['Time', 'Season'] as $metering) {
            $rates = $metering === 'Time' ? ['energy_day' => 10, 'energy_night' => 6] : ['energy_seasonal_winter' => 10, 'energy_seasonal_other' => 6];
            $old = 'old-'.$metering;
            $new = 'new-'.$metering;
            foreach (['2026-02-01', '2026-02-11', '2026-02-13', '2026-06-30'] as $date) {
                $this->rawTariff($old, $date, $rates);
            }
            $this->source($new, $metering === 'Time' ? 1 : 2, '2026-07-01', $rates, fee: 99);
            DB::table('electricity_contracts')->where('id', $old)->update(['replaced_by_contract_id' => $new]);
            // Fee metadata cannot affect an energy signature. Identical winter aliases are harmless.
            DB::table('price_components')->insert(['id' => 'fee', 'electricity_contract_id' => $old, 'price_date' => '2026-06-30', 'price_component_type' => 'Monthly', 'price' => 99, 'has_discount' => 1]);
            if ($metering === 'Season') {
                DB::table('price_components')->insert(['id' => 'alias', 'electricity_contract_id' => $old, 'price_date' => '2026-06-30', 'price_component_type' => 'SeasonalWinterDay', 'price' => 10, 'payment_unit' => 'c/kWh']);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $anchor = $this->resolveTariff($new, $rates, $metering);
            $this->assertCount(5, DB::getQueryLog());
            $this->assertSame('2026-02-01', $anchor->startedAt?->toDateString());
            $this->assertSame(PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun, $anchor->evidenceBasis);
            $this->assertContains('observed_full_tariff_energy_evidence', $anchor->flags);
            $this->assertContains('canonical_source_energy_continuation', $anchor->flags);
            $this->assertContains('price_episode_observation_gap', $anchor->flags);
        }
    }

    public function test_multiple_raw_lineages_share_one_query_and_accept_identical_duplicates_and_zero(): void
    {
        $rates = ['energy_day' => 0, 'energy_night' => 6];
        foreach (['a', 'b'] as $id) {
            $this->rawTariff($id, '2026-02-01', $rates);
            DB::table('price_components')->insert(['id' => 'duplicate-'.$id, 'electricity_contract_id' => $id, 'price_date' => '2026-02-01', 'price_component_type' => 'DayTime', 'price' => 0, 'payment_unit' => 'c/kWh']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $candidates = [
            'a' => new SupplierAdjustedCandidate('a', 2.25, 4, $rates, 'Time'),
            'b' => new SupplierAdjustedCandidate('b', 2.25, 99, $rates, 'Time'),
        ];
        $anchors = (new CurrentPriceEpisodeResolver)->resolve($candidates, CarbonImmutable::parse('2026-08-10', 'Europe/Helsinki'));
        $this->assertCount(5, DB::getQueryLog());
        foreach ($anchors as $anchor) {
            $this->assertSame('2026-02-01', $anchor->startedAt?->toDateString());
        }
        $this->assertSame($rates, $candidates['a']->normalizedEnergyRates());
    }

    public function test_raw_time_same_average_change_and_recurrence_break_the_episode(): void
    {
        $rates = ['energy_day' => 10, 'energy_night' => 6];
        $this->rawTariff('a', '2026-02-01', $rates);
        $this->rawTariff('a', '2026-03-01', ['energy_day' => 9.4, 'energy_night' => 7]);
        $this->rawTariff('a', '2026-04-01', $rates);
        $this->source('a', 1, '2026-07-01', $rates);
        $anchor = $this->resolveTariff('a', $rates);
        $this->assertSame('2026-04-01', $anchor->startedAt?->toDateString());
        $this->assertContains('different_observed_energy_signature', $anchor->flags);
    }

    public function test_incomplete_ambiguous_or_wrong_vat_raw_history_is_unknown(): void
    {
        $rates = ['energy_day' => 10, 'energy_night' => 6];
        foreach (['bucket', 'unit', 'unknown-unit', 'promo', 'conflict', 'identity', 'vat', 'negative', 'nonfinite'] as $id) {
            $this->rawTariff($id, '2026-02-01', $rates);
            $this->rawTariff($id, '2026-06-01', $rates);
            $query = DB::table('price_components')->where('electricity_contract_id', $id)->where('price_date', '2026-06-01');
            match ($id) {
                'bucket' => (clone $query)->where('price_component_type', 'NightTime')->delete(),
                'unit' => $query->update(['payment_unit' => null]),
                'unknown-unit' => $query->update(['payment_unit' => 'EUR/kWh']),
                'nonfinite' => $query->update(['price' => 'Infinity']),
                'promo' => $query->update(['has_discount' => 1, 'discount_value' => 1, 'discount_type' => 'TimePeriod']),
                'conflict' => DB::table('price_components')->insert(['electricity_contract_id' => $id, 'price_date' => '2026-06-01', 'price_component_type' => 'DayTime', 'price' => 11, 'payment_unit' => 'c/kWh']),
                'identity' => DB::table('contract_price_snapshots')->where('contract_id', $id)->delete(),
                'negative' => $query->update(['price' => -1]),
                default => null,
            };
            $anchor = $this->resolveTariff($id, $rates, includesVat: $id !== 'vat');
            $this->assertNull($anchor->startedAt, $id);
            $this->assertContains('unknown_or_conflicting_energy_evidence', $anchor->flags);
        }
    }

    public function test_raw_history_cannot_reopen_invalid_missing_or_uncovered_immutable_periods(): void
    {
        $rates = ['energy_day' => 10, 'energy_night' => 6];
        $this->rawTariff('a', '2026-02-01', $rates);
        $this->source('a', 1, '2026-07-01', $rates);
        $this->rawTariff('a', '2026-07-01', $rates);
        $this->rawTariff('a', '2026-08-01', $rates);
        $this->rawTariff('a', '2026-09-01', ['energy_day' => 99, 'energy_night' => 99]);
        $this->assertSame('2026-02-01', $this->resolveTariff('a', $rates)->startedAt?->toDateString());
        DB::table('contract_interpretations')->update(['validation_errors' => '["invalid"]']);
        $this->assertNull($this->resolveTariff('a', $rates)->startedAt);
        DB::table('contract_source_snapshots')->delete();
        $this->assertNull($this->resolveTariff('a', $rates)->startedAt);
    }

    public function test_future_raw_evidence_is_not_a_price_change_and_current_vat_is_not_backcast(): void
    {
        $rates = ['energy_day' => 10, 'energy_night' => 6];
        $this->rawTariff('a', '2026-02-01', $rates);
        $this->rawTariff('a', '2026-09-01', ['energy_day' => 99, 'energy_night' => 99]);
        $this->assertSame('2026-02-01', $this->resolveTariff('a', $rates)->startedAt?->toDateString());
        $this->source('a', 1, '2026-07-01', $rates, vat: 'excluded');
        $normalized = ['energy_day' => 12.55, 'energy_night' => 7.53];
        $anchor = $this->resolveTariff('a', $normalized);
        $this->assertSame('2026-07-01', $anchor->startedAt?->toDateString());
        $this->assertContains('different_observed_energy_signature', $anchor->flags);
    }

    public function test_raw_snapshots_cannot_prove_a_hybrid_base_episode(): void
    {
        foreach (['FixedPrice', 'Hybrid'] as $sourceModel) {
            foreach (['General' => ['energy_general' => 8.0], 'Time' => ['energy_day' => 10.0, 'energy_night' => 6.0], 'Season' => ['energy_seasonal_winter' => 10.0, 'energy_seasonal_other' => 6.0]] as $metering => $rates) {
                $id = $sourceModel.'-'.$metering;
                if ($metering === 'General') {
                    $this->snapshot($id, '2026-02-01', 8, 'observed_seller_data');
                } else {
                    $this->rawTariff($id, '2026-02-01', $rates);
                }
                DB::table('contract_price_snapshots')->where('contract_id', $id)->update(['pricing_model' => $sourceModel, 'contract_type' => 'OpenEnded']);
                $candidate = new SupplierAdjustedCandidate($id, 8, 0, $rates, $metering, true, 'Hybrid');
                $anchor = (new CurrentPriceEpisodeResolver)->resolve([$id => $candidate], CarbonImmutable::parse('2026-08-10', 'Europe/Helsinki'))[$id];
                $this->assertNull($anchor->startedAt, $id);
                $this->assertSame(PriceEpisodeEvidenceBasis::Missing, $anchor->evidenceBasis);
            }
        }
    }

    public function test_inactive_discount_representations_keep_full_tariff_history_through_immutable_transition(): void
    {
        foreach (['Time', 'Season'] as $metering) {
            $rates = $metering === 'Time' ? ['energy_day' => 10, 'energy_night' => 6] : ['energy_seasonal_winter' => 10, 'energy_seasonal_other' => 6];
            $anchors = [];
            foreach (['null', 'zero', 'null-type'] as $index => $representation) {
                $id = $metering.'-'.$representation;
                foreach (['2026-01-21', '2026-07-22'] as $date) {
                    $this->rawTariff($id, $date, $rates);
                }
                if ($representation !== 'null') {
                    DB::table('price_components')->where('electricity_contract_id', $id)->update([
                        'has_discount' => '0', 'discount_type' => $representation === 'zero' ? 'NoDiscount' : null,
                        'discount_value' => '0.00', 'discount_discount_n_first_kwh' => '0',
                        'discount_discount_n_first_months' => '0', 'discount_is_percentage' => '0',
                        'payment_unit' => 'CentPerKiwattHour',
                    ]);
                }
                $this->source($id, ($metering === 'Time' ? 10 : 20) + $index, '2026-07-23', $rates, fee: 99);
                $anchors[] = $this->resolveTariff($id, $rates, $metering);
                $this->assertSame('2026-01-21', $anchors[$index]->startedAt?->toDateString());
                $this->assertContains('canonical_source_energy_continuation', $anchors[$index]->flags);
            }
            $this->assertEquals($anchors[0], $anchors[1]);
            $this->assertEquals($anchors[0], $anchors[2]);
        }
    }

    public function test_inactive_discount_metadata_does_not_hide_an_older_rate_change(): void
    {
        foreach (['Time', 'Season'] as $index => $metering) {
            $rates = $metering === 'Time' ? ['energy_day' => 10, 'energy_night' => 6] : ['energy_seasonal_winter' => 10, 'energy_seasonal_other' => 6];
            $old = 'old-'.$metering;
            $new = 'new-'.$metering;
            $this->rawTariff($old, '2026-01-21', array_map(fn ($rate) => $rate + 1, $rates));
            foreach (['2026-06-15', '2026-07-22'] as $date) {
                $this->rawTariff($new, $date, $rates);
            }
            DB::table('price_components')->whereIn('electricity_contract_id', [$old, $new])->update([
                'has_discount' => 0, 'discount_type' => 'NoDiscount', 'discount_value' => 0,
                'discount_discount_n_first_kwh' => 0, 'discount_discount_n_first_months' => 0, 'discount_is_percentage' => 0,
            ]);
            $this->source($new, $index + 1, '2026-07-23', $rates);
            DB::table('electricity_contracts')->where('id', $old)->update(['replaced_by_contract_id' => $new]);
            $anchor = $this->resolveTariff($new, $rates, $metering);
            $this->assertSame('2026-06-15', $anchor->startedAt?->toDateString());
            $this->assertContains('different_observed_energy_signature', $anchor->flags);
        }
    }

    public function test_residual_or_malformed_discount_metadata_cannot_prove_raw_tariffs(): void
    {
        $rates = ['energy_day' => 10, 'energy_night' => 6];
        $cases = [
            ['has_discount' => 1], ['has_discount' => 'false'], ['has_discount' => 'unknown'],
            ['discount_type' => 'Unknown'], ['discount_type' => ''], ['discount_type' => '0'],
            ['discount_value' => 1], ['discount_value' => -1], ['discount_value' => 'NaN'],
            ['discount_value' => 'Infinity'], ['discount_value' => 'garbage'],
            ['discount_discount_n_first_kwh' => 1], ['discount_discount_n_first_kwh' => 'unknown'],
            ['discount_discount_n_first_months' => 1], ['discount_discount_n_first_months' => 'unknown'],
            ['discount_is_percentage' => 1], ['discount_is_percentage' => 'false'],
            ['discount_discount_until_date' => '2026-07-22'], ['discount_discount_until_date' => '0'],
        ];
        foreach ($cases as $index => $change) {
            $id = 'invalid-'.$index;
            $this->rawTariff($id, '2026-01-21', $rates);
            DB::table('price_components')->where('electricity_contract_id', $id)->update($change);
            $this->assertNull($this->resolveTariff($id, $rates)->startedAt, json_encode($change));
            $this->source($id, $index + 1, '2026-07-23', $rates);
            $this->assertSame('2026-07-23', $this->resolveTariff($id, $rates)->startedAt?->toDateString(), json_encode($change));
        }
    }

    public function test_overlap_end_restarts_on_next_covered_helsinki_day_without_future_leakage(): void
    {
        foreach (['different', 'unknown', 'identical'] as $index => $kind) {
            $old = 'old-'.$kind;
            $new = 'new-'.$kind;
            $this->source($old, $index * 2 + 1, '2026-07-01', ['energy_general' => $kind === 'different' ? 7.85 : 12.92]);
            $this->source($new, $index * 2 + 2, '2026-07-23', ['energy_general' => 12.92]);
            DB::table('electricity_contracts')->where('id', $old)->update(['replaced_by_contract_id' => $new]);
            // 21:30 UTC is already September 9 in Helsinki.
            DB::table('contract_source_observations')->where('contract_id', $old)->update(['last_observed_at' => '2026-09-08 21:30:00']);
            DB::table('contract_source_observations')->where('contract_id', $new)->update(['last_observed_at' => '2026-09-16 00:00:00']);
            if ($kind === 'unknown') {
                DB::table('contract_interpretations')->where('contract_id', $old)->update(['validation_errors' => '["invalid"]']);
            }
            $candidates = [$new => new SupplierAdjustedCandidate($new, 12.92, 0)];
            $resolver = new CurrentPriceEpisodeResolver;
            $before = $resolver->resolve($candidates, CarbonImmutable::parse('2026-09-09', 'Europe/Helsinki'))[$new];
            $after = $resolver->resolve($candidates, CarbonImmutable::parse('2026-09-16', 'Europe/Helsinki'))[$new];
            if ($kind === 'identical') {
                $this->assertSame('2026-07-01', $before->startedAt?->toDateString());
                $this->assertSame('2026-07-01', $after->startedAt?->toDateString());
            } else {
                $this->assertNull($before->startedAt);
                $this->assertSame('2026-09-10', $after->startedAt?->toDateString());
                $this->assertContains('prior_energy_evidence_changed_unknown_or_conflicting', $after->flags);
            }
            $this->assertNotContains('price_episode_observation_gap', $after->flags);
            $this->assertNotContains('price_episode_right_observation_gap', $after->flags);
        }
    }

    public function test_interval_end_does_not_create_observations_in_gaps_or_after_last_coverage(): void
    {
        $this->source('a', 1, '2026-07-01', ['energy_general' => 8]);
        DB::table('contract_source_observations')->update(['last_observed_at' => '2026-07-09 00:00:00']);
        $anchor = $this->resolve(['a'])['a'];
        $this->assertSame('2026-07-01', $anchor->startedAt?->toDateString());
        $this->assertContains('price_episode_right_observation_gap', $anchor->flags);
        $this->assertNotContains('prior_energy_evidence_changed_unknown_or_conflicting', $anchor->flags);
        $this->source('a', 2, '2026-08-01', ['energy_general' => 8]);
        $anchor = $this->resolve(['a'])['a'];
        $this->assertSame('2026-07-01', $anchor->startedAt?->toDateString());
        $this->assertContains('price_episode_observation_gap', $anchor->flags);
        $this->assertNotContains('prior_energy_evidence_changed_unknown_or_conflicting', $anchor->flags);
    }

    private function rawTariff(string $id, string $date, array $rates): void
    {
        $this->snapshot($id, $date, 8.5, 'observed_seller_data');
        $metering = isset($rates['energy_day']) ? 'Time' : 'Season';
        DB::table('contract_price_snapshots')->where('contract_id', $id)->where('snapshot_date', $date)->update(['metering' => $metering, 'contract_type' => 'OpenEnded']);
        foreach ($rates as $type => $rate) {
            $rawType = match ($type) {
                'energy_day' => 'DayTime', 'energy_night' => 'NightTime',
                'energy_seasonal_winter' => 'SeasonalWinter', 'energy_seasonal_other' => 'SeasonalOther',
            };
            DB::table('price_components')->insert(['id' => $id.'-'.$type, 'electricity_contract_id' => $id, 'price_date' => $date, 'price_component_type' => $rawType, 'price' => $rate, 'payment_unit' => 'CentPerKilowattHour']);
        }
    }

    private function resolveTariff(string $id, array $rates, string $metering = 'Time', bool $includesVat = true): PriceEpisodeAnchor
    {
        return (new CurrentPriceEpisodeResolver)->resolve([$id => new SupplierAdjustedCandidate($id, 8.5, 0, $rates, $metering, $includesVat)], CarbonImmutable::parse('2026-08-10', 'Europe/Helsinki'))[$id];
    }

    private function source(string $id, int $key, string $date, array $rates, float $fee = 0, string $vat = 'included', bool $feePhases = false): void
    {
        DB::table('electricity_contracts')->insertOrIgnore(['id' => $id]);
        $components = [];
        foreach ($rates as $type => $amount) {
            $components[] = ['component_type' => $type, 'amount' => $amount, 'normal_amount' => null, 'unit' => 'cents_per_kwh', 'price_role' => 'current', 'vat_status' => $vat];
        }
        $components[] = ['component_type' => 'monthly_fee', 'amount' => $fee, 'unit' => 'eur_per_month', 'price_role' => 'current'];
        $output = [
            'pricing' => ['phases' => [['label' => 'Current', 'phase_kind' => 'current_structured', 'starts' => ['kind' => 'none'], 'ends' => ['kind' => 'none'], 'components' => $components]]],
            'calculation' => ['status' => 'exact'],
            'source_consistency' => ['structured_pricing_status' => 'complete'],
        ];
        if ($feePhases) {
            $normal = $output['pricing']['phases'][0];
            $normal['phase_kind'] = 'normal';
            $normal['starts'] = ['kind' => 'after_months', 'value' => '3'];
            $intro = $output['pricing']['phases'][0];
            $intro['phase_kind'] = 'introductory';
            $intro['ends'] = ['kind' => 'after_months', 'value' => '3'];
            $intro['components'][count($components) - 1]['amount'] = 0;
            $intro['components'][count($components) - 1]['normal_amount'] = $fee;
            $output['pricing']['phases'] = [$intro, $normal];
        }
        DB::table('contract_source_snapshots')->insert(['id' => $key, 'contract_id' => $id, 'source_payload' => json_encode(['Details' => ['PricingModel' => 'FixedPrice', 'ContractType' => 'OpenEnded', 'Metering' => isset($rates['energy_day']) ? 'Time' : (isset($rates['energy_seasonal_winter']) ? 'Season' : 'General'), 'TargetGroup' => 'Household']])]);
        DB::table('contract_source_observations')->insert(['id' => $key, 'contract_id' => $id, 'source_snapshot_id' => $key, 'first_observed_at' => $date.' 00:00:00', 'last_observed_at' => $date.' 00:00:00']);
        DB::table('contract_interpretations')->insert(['id' => $key, 'contract_id' => $id, 'source_snapshot_id' => $key, 'analysis_source_observation_id' => $key, 'status' => 'published', 'output' => json_encode($output), 'validation_errors' => '[]', 'completed_at' => $date.' 00:00:00']);
    }

    private function snapshot(string $id, string $date, ?float $rate, string $basis = 'canonical_calculation'): void
    {
        DB::table('electricity_contracts')->insertOrIgnore(['id' => $id]);
        DB::table('contract_price_snapshots')->insert(['contract_id' => $id, 'snapshot_date' => $date, 'pricing_basis' => $basis, 'energy_price_cents_per_kwh' => $rate, 'monthly_fee_eur' => 0, 'metering' => 'General', 'pricing_model' => 'FixedPrice']);
    }

    private function resolve(array $ids): array
    {
        $candidates = [];
        foreach ($ids as $id) {
            $candidates[$id] = new SupplierAdjustedCandidate($id, 8, 0);
        }

        return (new CurrentPriceEpisodeResolver)->resolve($candidates, CarbonImmutable::parse('2026-08-10', 'Europe/Helsinki'));
    }
}
