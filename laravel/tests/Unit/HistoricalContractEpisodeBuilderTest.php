<?php

namespace Tests\Unit;

use App\Services\ContractInterpretation\HistoricalContractEpisodeBuilder;
use App\Services\ContractInterpretation\HistoricalInterpretationFingerprint;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class HistoricalContractEpisodeBuilderTest extends TestCase
{
    public function test_unchanged_thirty_days_make_one_episode_and_manifest_keeps_exact_ids(): void
    {
        $days = [];
        for ($day = 0; $day < 30; $day++) {
            $date = CarbonImmutable::parse('2026-01-01')->addDays($day)->toDateString();
            $days[] = $this->day($date, 7.2, 'snapshot-'.($day + 1), 'component-'.($day + 1));
        }

        $result = app(HistoricalContractEpisodeBuilder::class)->buildFromDays($days);

        $this->assertSame(30, $result['eligible_days']);
        $this->assertCount(1, $result['episodes']);
        $episode = $result['episodes'][0];
        $this->assertSame('2026-01-01', $episode['episode_start']);
        $this->assertSame('2026-01-30', $episode['episode_end']);
        $this->assertArrayNotHasKey('snapshot_ids', $episode['evidence_manifest']);
        $this->assertArrayNotHasKey('component_rows', $episode['evidence_manifest']);
        $this->assertSame('2026-01-01', $episode['evidence_manifest']['target_days'][0]['date']);
        $this->assertSame(1, $episode['evidence_manifest']['target_days'][0]['snapshot_id']);
        $this->assertSame(['component-1|2026-01-01'], $episode['evidence_manifest']['target_days'][0]['component_ids']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $episode['evidence_manifest']['target_days'][0]['economic_digest']);
        $this->assertSame('2026-01-30', $episode['evidence_manifest']['target_days'][29]['date']);
    }

    public function test_order_and_row_ids_do_not_change_semantic_fingerprints_but_change_exact_manifest(): void
    {
        $a = $this->day('2026-01-01', 7.2, 'snapshot-a', 'component-a');
        $a['components'][] = $this->componentRow('second-a', '2026-01-01', 4.500000);
        $b = $this->day('2026-01-01', '7.2000000000', 'snapshot-b', 'component-b');
        $b['snapshots'][0]['id'] = 999;
        $b['components'][] = $this->componentRow('second-b', '2026-01-01', '4.5');
        $b['components'] = array_reverse($b['components']);

        $first = app(HistoricalContractEpisodeBuilder::class)->buildFromDays([$a])['episodes'][0];
        $second = app(HistoricalContractEpisodeBuilder::class)->buildFromDays([$b])['episodes'][0];

        $firstInput = $first['analysis_input'];
        $secondInput = $second['analysis_input'];
        foreach ($firstInput['components'] as &$component) {
            unset($component['id']);
        }
        unset($component);
        foreach ($secondInput['components'] as &$component) {
            unset($component['id']);
        }
        unset($component);
        $this->assertSame($firstInput, $secondInput);
        $this->assertSame($first['evidence_manifest']['text_provenance'], $second['evidence_manifest']['text_provenance']);
        $this->assertSame($first['evidence_manifest']['evidence_grade'], $second['evidence_manifest']['evidence_grade']);
        $this->assertSame($first['evidence_fingerprint'], $second['evidence_fingerprint']);
        $this->assertSame($first['episode_fingerprint'], $second['episode_fingerprint']);
        $this->assertSame($first['analysis_fingerprint'], $second['analysis_fingerprint']);
        $this->assertSame(['4.5', '7.2'], collect($first['analysis_input']['components'])->pluck('price')->sort()->values()->all());
        $this->assertNotSame($first['evidence_manifest'], $second['evidence_manifest']);
        $this->assertNotSame($first['manifest_fingerprint'], $second['manifest_fingerprint']);
    }

    public function test_fingerprints_survive_mysql_style_whole_float_json_normalization(): void
    {
        $fingerprints = app(HistoricalInterpretationFingerprint::class);
        $beforeStorage = [
            'minimum' => 0.0,
            'maximum' => 100000.0,
            'fraction' => 7.25,
            'count' => 12,
        ];
        $afterStorage = json_decode(json_encode($beforeStorage, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $afterStorage['minimum']);
        $this->assertSame(100000, $afterStorage['maximum']);
        $this->assertSame($fingerprints->hash($beforeStorage), $fingerprints->hash($afterStorage));
        $this->assertNotSame(
            $fingerprints->hash($beforeStorage),
            $fingerprints->hash([...$afterStorage, 'fraction' => 7.26]),
        );
    }

    public function test_economic_identity_changes_gaps_and_a_b_a_each_split(): void
    {
        $days = [
            $this->day('2026-01-01', 7.2),
            $this->day('2026-01-02', 8.2),
            $this->day('2026-01-03', 7.2),
            $this->day('2026-01-05', 7.2),
        ];
        $days[2]['snapshots'][0]['pricing_model'] = 'Spot';

        $result = app(HistoricalContractEpisodeBuilder::class)->buildFromDays($days);

        $this->assertCount(4, $result['episodes']);
    }

    public function test_price_unit_discount_and_snapshot_identity_changes_split(): void
    {
        $base = $this->day('2026-01-01', 7.2);
        $unit = $this->day('2026-01-02', 7.2);
        $unit['components'][0]['payment_unit'] = 'EuroPerMonth';
        $discount = $this->day('2026-01-03', 7.2);
        $discount['components'][0]['has_discount'] = true;
        $identity = $this->day('2026-01-04', 7.2);
        $identity['snapshots'][0]['contract_type'] = 'FixedTerm';

        $this->assertCount(4, app(HistoricalContractEpisodeBuilder::class)
            ->buildFromDays([$base, $unit, $discount, $identity])['episodes']);
    }

    private function day(
        string $date,
        float|string $price,
        string $snapshotMarker = 'snapshot',
        string $componentId = 'component',
    ): array {
        return [
            'contract_id' => 'contract-1',
            'date' => $date,
            'snapshots' => [[
                'id' => abs((int) filter_var($snapshotMarker, FILTER_SANITIZE_NUMBER_INT)) ?: 1,
                'company_name' => 'Test Energy',
                'contract_name' => 'Test contract',
                'pricing_model' => 'FixedPrice',
                'contract_type' => 'OpenEnded',
                'fixed_time_range' => null,
                'metering' => 'General',
                'segment_key' => 'fixed_open',
                'pricing_basis' => 'observed_seller_data',
                'has_discount' => false,
                'includes_spot_price' => false,
            ]],
            'components' => [$this->componentRow($componentId, $date, $price)],
        ];
    }

    private function componentRow(string $id, string $date, float|string $price): array
    {
        return [
            'id' => $id,
            'price_date' => $date,
            'price_component_type' => 'General',
            'fuse_size' => null,
            'price' => $price,
            'payment_unit' => 'CentPerKiwattHour',
            'has_discount' => false,
            'discount_value' => null,
            'discount_is_percentage' => null,
            'discount_type' => null,
            'discount_discount_n_first_kwh' => null,
            'discount_discount_n_first_months' => null,
            'discount_discount_until_date' => null,
        ];
    }
}
