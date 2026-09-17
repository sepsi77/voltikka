<?php

namespace Tests\Unit\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\ForwardPremium\ForwardPremiumResolver;
use App\Services\CanonicalPricing\ForwardPremium\PremiumCompatibility;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
use App\Services\CanonicalPricing\ForwardPremium\PremiumObservation;
use App\Services\CanonicalPricing\ForwardPremium\PremiumSource;
use App\Services\CanonicalPricing\ForwardPremium\PremiumTarget;
use App\Services\CanonicalPricing\ForwardPremium\PremiumVatBasis;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

class ForwardPremiumResolverTest extends TestCase
{
    private function compatibility(array $overrides = []): PremiumCompatibility
    {
        return new PremiumCompatibility(...array_replace([
            'family' => PremiumFamily::SupplierAdjusted,
            'metering' => MeteringType::General,
            'vatBasis' => PremiumVatBasis::Included,
            'buckets' => [ComponentType::EnergyGeneral],
        ], $overrides));
    }

    private function target(?PremiumCompatibility $compatibility = null): PremiumTarget
    {
        return new PremiumTarget('new-contract-id', 'own-root', 'Exact Company Oy', $compatibility ?? $this->compatibility(), CarbonImmutable::parse('2026-09-15'));
    }

    private function observation(array $overrides = []): PremiumObservation
    {
        return new PremiumObservation(...array_replace([
            'lineageId' => 'own-root',
            'companyName' => 'Exact Company Oy',
            'compatibility' => $this->compatibility(),
            'premiumsByBucket' => ['energy_general' => 2.0],
            'energyOfferSignature' => 'full-retail-tariff-signature-A',
            'observedAt' => CarbonImmutable::parse('2026-08-01'),
            'referenceTradeDate' => CarbonImmutable::parse('2026-07-30'),
            'pricePeriodStart' => CarbonImmutable::parse('2026-08-01'),
            'pricePeriodEnd' => CarbonImmutable::parse('2026-08-31'),
            'referenceDeliveryStart' => CarbonImmutable::parse('2026-08-01'),
            'referenceDeliveryEnd' => CarbonImmutable::parse('2026-08-31'),
            'provenance' => 'validated_retail_episode_and_prior_fi_reference',
        ], $overrides));
    }

    public function test_hierarchy_and_sparse_evidence_are_explicit(): void
    {
        $own = $this->observation();
        $company = $this->observation(['lineageId' => 'peer', 'premiumsByBucket' => ['energy_general' => 4.0]]);
        $market = $this->observation(['lineageId' => 'other', 'companyName' => 'Other Oy', 'premiumsByBucket' => ['energy_general' => 8.0]]);
        $resolver = new ForwardPremiumResolver;

        foreach ([[[$market, $company, $own], PremiumSource::OwnLineage, 2.0], [[$market, $company], PremiumSource::SameCompany, 4.0], [[$market], PremiumSource::Market, 8.0]] as [$rows, $source, $premium]) {
            $result = $resolver->resolve($this->target(), $rows);
            $this->assertSame($source, $result->source);
            $this->assertSame(['energy_general' => $premium], $result->premiumsByBucket);
            $this->assertSame(1, $result->independentVariantCount);
            $this->assertSame(1, $result->lineageCount);
            $this->assertSame(1, $result->companyCount);
            $this->assertTrue($result->sparse);
            $this->assertTrue($result->lowerConfidence);
            $this->assertContains('single_independent_energy_variant', $result->flags);
        }
    }

    public function test_latest_eligible_period_wins_and_historical_months_transfer(): void
    {
        $old = $this->observation();
        $new = $this->observation([
            'observedAt' => CarbonImmutable::parse('2026-09-01'),
            'pricePeriodStart' => CarbonImmutable::parse('2026-09-01'),
            'pricePeriodEnd' => CarbonImmutable::parse('2026-09-30'),
            'referenceDeliveryStart' => CarbonImmutable::parse('2026-09-01'),
            'referenceDeliveryEnd' => CarbonImmutable::parse('2026-09-30'),
            'referenceTradeDate' => CarbonImmutable::parse('2026-08-31'),
            'premiumsByBucket' => ['energy_general' => -1.0],
            'energyOfferSignature' => 'changed-energy-rates',
        ]);
        $future = $this->observation(['observedAt' => CarbonImmutable::parse('2026-09-16')]);
        $result = (new ForwardPremiumResolver)->resolve($this->target(), [$future, $old, $new]);
        $this->assertSame(['energy_general' => -1.0], $result->premiumsByBucket);
        $this->assertSame(1, $result->observationCount);
        $this->assertSame(['2026-08-31'], $result->referenceTradeDates);
        $this->assertSame('2026-09-01', $result->evidenceFrom->toDateString());
        $this->assertSame('2026-09-01', $result->evidenceThrough->toDateString());

        // August evidence is valid for a September target. No target delivery-month equality.
        $this->assertNotNull((new ForwardPremiumResolver)->resolve($this->target(), [$old]));
    }

    public function test_newest_same_day_conflict_excludes_lineage_without_old_price_resurrection(): void
    {
        $older = $this->observation(['observedAt' => CarbonImmutable::parse('2026-07-31')]);
        $one = $this->observation();
        $conflict = $this->observation(['premiumsByBucket' => ['energy_general' => 99.0]]);
        $peer = $this->observation(['lineageId' => 'peer', 'energyOfferSignature' => 'peer-tariff']);
        $resolver = new ForwardPremiumResolver;
        $this->assertNull($resolver->resolve($this->target(), [$older, $one, $conflict]));
        $result = $resolver->resolve($this->target(), [$older, $one, $conflict, $peer]);
        $this->assertSame(PremiumSource::SameCompany, $result->source);
        $this->assertContains('conflicting_latest_lineage_evidence', $result->flags);
        $this->assertEquals($result, $resolver->resolve($this->target(), [$peer, $conflict, $one, $older]));
    }

    public function test_cadence_family_metering_bucket_set_and_vat_must_match(): void
    {
        $monthly = $this->compatibility(['family' => PremiumFamily::MarketReset, 'resetCadence' => 'monthly']);
        $wrong = [
            $this->compatibility(),
            $this->compatibility(['family' => PremiumFamily::MarketReset, 'resetCadence' => 'quarterly']),
            $this->compatibility(['family' => PremiumFamily::MarketReset, 'resetCadence' => 'other']),
            $this->compatibility(['family' => PremiumFamily::MarketReset, 'resetCadence' => 'monthly', 'vatBasis' => PremiumVatBasis::Excluded]),
            $this->compatibility(['family' => PremiumFamily::MarketReset, 'resetCadence' => 'monthly', 'metering' => MeteringType::Time]),
            $this->compatibility(['family' => PremiumFamily::MarketReset, 'resetCadence' => 'monthly', 'buckets' => [ComponentType::EnergyDay]]),
        ];
        foreach ($wrong as $compatibility) {
            $row = $this->observation(['compatibility' => $compatibility, 'premiumsByBucket' => array_fill_keys($compatibility->buckets, 1.0)]);
            $this->assertNull((new ForwardPremiumResolver)->resolve($this->target($monthly), [$row]));
        }
        $this->assertNotNull((new ForwardPremiumResolver)->resolve($this->target($monthly), [$this->observation(['compatibility' => $monthly])]));
    }

    public function test_replacement_rows_and_fee_only_clones_have_one_weight(): void
    {
        $a = $this->observation(['lineageId' => 'peer-a', 'premiumsByBucket' => ['energy_general' => 1.0]]);
        $clone = $this->observation(['lineageId' => 'peer-a-fee-clone', 'premiumsByBucket' => ['energy_general' => 1.0]]);
        $replacementDuplicate = $this->observation(['lineageId' => 'peer-a', 'premiumsByBucket' => ['energy_general' => 1.0], 'provenance' => 'replacement-id-same-energy']);
        $b = $this->observation(['lineageId' => 'peer-b', 'energyOfferSignature' => 'different-retail-energy-offer', 'premiumsByBucket' => ['energy_general' => 9.0]]);
        $result = (new ForwardPremiumResolver)->resolve($this->target(), [$a, $a, $clone, $replacementDuplicate, $b]);
        $this->assertSame(['energy_general' => 5.0], $result->premiumsByBucket);
        $this->assertSame(2, $result->independentVariantCount);
        $this->assertSame(3, $result->lineageCount);
        $this->assertSame(4, $result->observationCount);
        $this->assertContains('equivalent_energy_offers_deduplicated', $result->flags);
    }

    public function test_equal_premiums_with_different_retail_signatures_are_not_clones(): void
    {
        $rows = [];
        foreach ([1.0, 1.0, 9.0] as $index => $premium) {
            $rows[] = $this->observation(['lineageId' => 'peer-'.$index, 'energyOfferSignature' => 'full-tariff-'.$index, 'premiumsByBucket' => ['energy_general' => $premium]]);
        }
        $result = (new ForwardPremiumResolver)->resolve($this->target(), $rows);
        $this->assertSame(3, $result->independentVariantCount);
        $this->assertSame(['energy_general' => 1.0], $result->premiumsByBucket);
    }

    public function test_market_uses_company_balanced_bucket_medians_and_is_permutation_invariant(): void
    {
        $compatibility = $this->compatibility(['metering' => MeteringType::Time, 'buckets' => [ComponentType::EnergyNight, ComponentType::EnergyDay]]);
        $rows = [];
        foreach ([['A', 1.0], ['A', 3.0], ['A', 5.0], ['B', 9.0], ['C', 11.0]] as $index => [$company, $premium]) {
            $rows[] = $this->observation([
                'lineageId' => 'peer-'.$index, 'companyName' => $company, 'compatibility' => $compatibility,
                'energyOfferSignature' => 'full-tariff-'.$index,
                'premiumsByBucket' => ['energy_night' => $premium - 2, 'energy_day' => $premium],
            ]);
        }
        $resolver = new ForwardPremiumResolver;
        $result = $resolver->resolve($this->target($compatibility), $rows);
        $this->assertSame(PremiumSource::Market, $result->source);
        $this->assertSame(['energy_day' => 9.0, 'energy_night' => 7.0], $result->premiumsByBucket);
        $this->assertSame(['A', 'B', 'C'], $result->sourceCompanies);
        $this->assertSame(5, $result->independentVariantCount);
        $this->assertFalse($result->sparse);
        $this->assertTrue($result->lowerConfidence);
        foreach ($this->permutations($rows) as $permutation) {
            $this->assertEquals($result, $resolver->resolve($this->target($compatibility), $permutation));
        }
    }

    private function permutations(array $rows): iterable
    {
        if ($rows === []) {
            yield [];
        }
        foreach ($rows as $index => $row) {
            $rest = $rows;
            unset($rest[$index]);
            foreach ($this->permutations(array_values($rest)) as $tail) {
                yield [$row, ...$tail];
            }
        }
    }

    public function test_company_names_are_exact_not_fuzzy_normalized(): void
    {
        $row = $this->observation(['lineageId' => 'peer', 'companyName' => 'exact company oy']);
        $this->assertSame(PremiumSource::Market, (new ForwardPremiumResolver)->resolve($this->target(), [$row])->source);
    }

    public function test_conflicting_clone_evidence_is_not_selected_by_input_order(): void
    {
        $a = $this->observation(['lineageId' => 'a']);
        $b = $this->observation(['lineageId' => 'b', 'premiumsByBucket' => ['energy_general' => 3.0]]);
        $resolver = new ForwardPremiumResolver;
        $this->assertNull($resolver->resolve($this->target(), [$a, $b]));
        $this->assertNull($resolver->resolve($this->target(), [$b, $a]));
    }

    public function test_future_and_same_day_reference_data_are_not_eligible(): void
    {
        foreach (['2026-09-15', '2026-09-16'] as $trade) {
            $row = $this->observation([
                'referenceTradeDate' => CarbonImmutable::parse($trade),
                'pricePeriodStart' => CarbonImmutable::parse('2026-10-01'),
                'pricePeriodEnd' => CarbonImmutable::parse('2026-10-31'),
                'referenceDeliveryStart' => CarbonImmutable::parse('2026-10-01'),
                'referenceDeliveryEnd' => CarbonImmutable::parse('2026-10-31'),
            ]);
            $this->assertNull((new ForwardPremiumResolver)->resolve($this->target(), [$row]));
        }
        $this->assertNull((new ForwardPremiumResolver)->resolve($this->target(), []));
    }

    #[DataProvider('invalidPremiums')]
    public function test_unknown_and_nonfinite_premiums_are_rejected(mixed $premium): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->observation(['premiumsByBucket' => ['energy_general' => $premium]]);
    }

    public static function invalidPremiums(): array
    {
        return [[NAN], [INF], [-INF], [null], ['2.0']];
    }

    #[DataProvider('invalidDates')]
    public function test_reference_period_and_trade_date_are_validated(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->observation(array_map(fn (string $date) => CarbonImmutable::parse($date), $overrides));
    }

    public static function invalidDates(): array
    {
        return [
            [['referenceTradeDate' => '2026-08-01']],
            [['referenceTradeDate' => '2026-08-02']],
            [['referenceDeliveryStart' => '2026-09-01']],
            [['referenceDeliveryEnd' => '2026-09-30']],
            [['pricePeriodEnd' => '2026-07-01']],
        ];
    }

    public function test_unknown_vat_cannot_enter_typed_evidence(): void
    {
        $this->expectException(TypeError::class);
        $this->compatibility(['vatBasis' => PremiumVatBasis::tryFrom('unknown')]);
    }

    #[DataProvider('forbiddenBuckets')]
    public function test_fees_spot_margins_and_consumption_effects_are_not_energy_evidence(ComponentType $bucket): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compatibility(['buckets' => [$bucket]]);
    }

    public static function forbiddenBuckets(): array
    {
        return [[ComponentType::MonthlyFee], [ComponentType::FlatFee], [ComponentType::SpotMargin], [ComponentType::ConsumptionEffect], [ComponentType::Other]];
    }

    public function test_latest_clone_date_has_one_weight_and_older_clone_does_not_shift_it(): void
    {
        $old = $this->observation(['lineageId' => 'old-clone', 'premiumsByBucket' => ['energy_general' => 1.0]]);
        $latest = $this->observation(['lineageId' => 'new-clone', 'observedAt' => CarbonImmutable::parse('2026-08-02')]);
        $result = (new ForwardPremiumResolver)->resolve($this->target(), [$old, $latest]);
        $this->assertSame(['energy_general' => 2.0], $result->premiumsByBucket);
        $this->assertSame(['new-clone'], $result->sourceLineages);
        $this->assertSame(1, $result->independentVariantCount);
    }

    public function test_same_day_signature_conflict_is_rejected_even_when_premiums_match(): void
    {
        $one = $this->observation();
        $two = $this->observation(['energyOfferSignature' => 'different-energy-terms']);
        $this->assertNull((new ForwardPremiumResolver)->resolve($this->target(), [$one, $two]));
    }

    #[DataProvider('unsupportedFamilies')]
    public function test_spot_fixedterm_hybrid_and_unknown_families_cannot_enter(string $family): void
    {
        $this->expectException(TypeError::class);
        $this->compatibility(['family' => PremiumFamily::tryFrom($family)]);
    }

    public static function unsupportedFamilies(): array
    {
        return [['spot'], ['fixedterm'], ['hybrid'], ['unknown']];
    }

    public function test_unknown_reset_cadence_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compatibility(['family' => PremiumFamily::MarketReset, 'resetCadence' => 'unknown']);
    }

    public function test_missing_bucket_premium_is_not_filled_with_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->observation(['premiumsByBucket' => []]);
    }

    public function test_even_median_does_not_overflow_finite_input(): void
    {
        $rows = [];
        foreach (['a', 'b'] as $lineage) {
            $rows[] = $this->observation(['lineageId' => $lineage, 'energyOfferSignature' => $lineage, 'premiumsByBucket' => ['energy_general' => PHP_FLOAT_MAX]]);
        }
        $this->assertSame(PHP_FLOAT_MAX, (new ForwardPremiumResolver)->resolve($this->target(), $rows)->premiumsByBucket['energy_general']);
    }
}
