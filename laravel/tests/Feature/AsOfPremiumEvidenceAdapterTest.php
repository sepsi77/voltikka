<?php

namespace Tests\Feature;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\ForwardPremium\PremiumSource;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\ContractStatistics\AsOfPremiumEvidenceAdapter;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use App\Services\ContractStatistics\HistoricalPriceEpisodeResolver;
use Carbon\CarbonImmutable;
use Database\Factories\Support\CanonicalPricingFixture;
use Mockery;
use Tests\TestCase;

class AsOfPremiumEvidenceAdapterTest extends TestCase
{
    public function test_full_time_and_season_buckets_normalize_vat_and_ignore_fee_and_consumption_masks(): void
    {
        foreach (['Time' => ['energy_day', 'energy_night'], 'Season' => ['energy_seasonal_winter', 'energy_seasonal_other']] as $metering => $buckets) {
            $target = $this->item('target', $metering, $buckets);
            $donor = $this->item('donor', $metering, $buckets, fee: 99, vat: 'excluded');
            $clone = $this->item('clone', $metering, $buckets, fee: 0, vat: 'excluded');
            $adapter = $this->adapter(['target' => null, 'donor' => '2026-06-01', 'clone' => '2026-06-01']);
            $result = $adapter->resolve([$target, $donor, $clone], $this->date());
            $premium = $result['premiums']['target'];
            $this->assertSame(PremiumSource::SameCompany, $premium->source);
            $this->assertSame(1, $premium->independentVariantCount);
            $this->assertContains('equivalent_energy_offers_deduplicated', $premium->flags);
            foreach ($buckets as $index => $bucket) {
                $this->assertEqualsWithDelta((8 + $index) * 1.255 - 4, $premium->premiumsByBucket[$bucket], 0.00001);
            }
        }
    }

    public function test_future_donors_and_same_day_future_or_nonfinite_references_are_not_zero_premiums(): void
    {
        $target = $this->item('target');
        $donor = $this->item('donor');
        foreach (['2026-06-15', '2026-06-16', 'NAN', 'missing'] as $reference) {
            $adapter = $this->adapter(['target' => null, 'donor' => '2026-06-15'], $reference);
            $this->assertNull($adapter->resolve([$target, $donor], $this->date())['premiums']['target']);
        }
        $future = new AsOfAnnualCostEvidence(...[...get_object_vars($donor), 'date' => $this->date()->addDay()]);
        $adapter = $this->adapter(['target' => null]);
        $this->assertNull($adapter->resolve([$target, $future], $this->date())['premiums']['target']);
    }

    public function test_invalid_reference_dates_skip_only_the_bad_donor(): void
    {
        $target = $this->item('target');
        $donor = $this->item('donor');
        foreach (['not-a-date', '2026-02-30', '2026-13-01', 'yesterday', '-1 day', '', '2026-05-31 00:00:00', '2026-05-31T00:00:00Z', '2026-5-31', ' 2026-05-31', "2026-05-31\0"] as $reference) {
            $adapter = $this->adapter(['target' => '2026-06-01', 'donor' => '2026-06-02'], [
                '2026-06-01' => $reference,
                '2026-06-02' => '2026-05-31',
            ]);
            $result = $adapter->resolve([$target, $donor], $this->date());
            $premium = $result['premiums']['target'];
            $this->assertNotNull($premium);
            $this->assertSame(PremiumSource::SameCompany, $premium->source);
            $this->assertSame(['donor'], $premium->sourceLineages);
            $this->assertSame(['2026-05-31'], $premium->referenceTradeDates);
            $this->assertSame(['energy_general' => 4.0], $premium->premiumsByBucket);
        }
    }

    public function test_date_local_resolution_does_not_keep_previous_donors_and_hybrid_is_separate(): void
    {
        $target = $this->item('target');
        $hybrid = $this->item('hybrid', hybrid: true);
        $adapter = $this->adapter(['target' => null, 'hybrid' => '2026-06-01']);
        $result = $adapter->resolve([$target, $hybrid], $this->date());
        $this->assertNull($result['premiums']['target']);
        $this->assertNotNull($result['premiums']['hybrid']);
        $next = new AsOfAnnualCostEvidence(...[...get_object_vars($target), 'date' => $this->date()->addDay()]);
        $this->assertNull($adapter->resolve([$next], $this->date()->addDay())['premiums']['target']);
    }

    private function adapter(array $starts, string|array $reference = '2026-05-31'): AsOfPremiumEvidenceAdapter
    {
        $anchors = Mockery::mock(HistoricalPriceEpisodeResolver::class);
        $anchors->shouldReceive('resolve')->andReturnUsing(function ($date, $candidates) use ($starts) {
            $result = [];
            foreach ($candidates as $id => $candidate) {
                $result[$id] = new PriceEpisodeAnchor(isset($starts[$id]) ? CarbonImmutable::parse($starts[$id], 'Europe/Helsinki') : null, PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun);
            }

            return $result;
        });
        $curve = Mockery::mock(MarketReferenceCurveProvider::class);
        $curve->shouldReceive('referencePrice')->andReturnUsing(function ($bound) use ($reference) {
            $value = is_array($reference) ? $reference[$bound->toDateString()] : $reference;

            return $value === 'missing' ? null : [
                'kind' => 'month', 'trade_date' => $value === 'NAN' ? '2026-05-31' : $value,
                'price_cents_per_kwh' => $value === 'NAN' ? NAN : 4.0,
            ];
        });

        return new AsOfPremiumEvidenceAdapter(app(CanonicalContractPriceCalculator::class), $anchors, $curve);
    }

    private function item(string $id, string $metering = 'General', array $buckets = ['energy_general'], float $fee = 5, string $vat = 'included', bool $hybrid = false): AsOfAnnualCostEvidence
    {
        $attributes = CanonicalPricingFixture::fixedAttributes();
        $components = [];
        foreach ($buckets as $index => $bucket) {
            $component = CanonicalPricingFixture::component(ComponentType::from($bucket), 8 + $index, ComponentUnit::CentsPerKwh);
            $component['vat_status'] = $vat;
            $components[] = $component;
        }
        $components[] = CanonicalPricingFixture::component(ComponentType::MonthlyFee, $fee, ComponentUnit::EurPerMonth);
        $attributes['canonical_pricing']['phases'][0]['components'] = $components;
        if ($hybrid) {
            $attributes['canonical_pricing']['consumption_effect']['present'] = true;
            $attributes['canonical_pricing']['consumption_effect']['applies_to'] = 'base_contract';
        }
        $data = (new CanonicalPricingParser)->parse($attributes['canonical_pricing'], $attributes['canonical_calculation'], $attributes['canonical_source_consistency']);

        return new AsOfAnnualCostEvidence($id, $this->date(), 'Dated Company', 'open_ended', $hybrid ? 'Hybrid' : 'FixedPrice', 'OpenEnded', null, $metering,
            ContractPriceBasis::ObservedSellerData, [], [2000 => false, 5000 => true, 18000 => false], $data, ['historical_episode_id' => 5, 'historical_interpretation_id' => 6],
            ['historical_interpretation_completed_at_2026-08-01T12:00:00+00:00']);
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-06-15', 'Europe/Helsinki');
    }
}
