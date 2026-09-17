<?php

namespace Tests\Unit\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\ForwardPremium\ForwardPremiumResolver;
use App\Services\CanonicalPricing\ForwardPremium\PremiumCompatibility;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
use App\Services\CanonicalPricing\ForwardPremium\PremiumObservation;
use App\Services\CanonicalPricing\ForwardPremium\PremiumTarget;
use App\Services\CanonicalPricing\ForwardPremium\PremiumVatBasis;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumObservationProxyTest extends TestCase
{
    public function test_inside_delivery_vintage_requires_explicit_proxy_and_retains_real_observation_bounds(): void
    {
        $row = $this->observation(true);
        $this->assertSame('2026-06-15', $row->pricePeriodStart->toDateString());
        $this->assertSame('2026-06-01', $row->referenceDeliveryStart->toDateString());
        $estimate = (new ForwardPremiumResolver)->resolve(new PremiumTarget('target', 'target', 'Company', $row->compatibility, CarbonImmutable::parse('2026-07-01')), [$row]);
        $this->assertSame(-1.0, $estimate->premiumsByBucket['energy_general']);
        $this->assertTrue($estimate->lowerConfidence);
        $this->assertContains('observed_pricing_date_reference_period_proxy', $estimate->flags);
        $this->expectException(InvalidArgumentException::class);
        $this->observation(false);
    }

    public function test_proxy_trade_on_pricing_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->observation(true, '2026-06-15');
    }

    public function test_announced_exact_quarter_keeps_observation_and_delivery_dates_separate(): void
    {
        $row = $this->resetObservation(false, '2026-07-01');
        $estimate = (new ForwardPremiumResolver)->resolve(new PremiumTarget('target', 'target', 'Company', $row->compatibility, CarbonImmutable::parse('2026-04-15')), [$row]);
        $this->assertNotNull($estimate);
        $this->assertSame('2026-07-01', $row->referenceDeliveryStart->toDateString());
        $this->assertSame('2026-04-14', $row->observedAt->toDateString());
        $this->assertFalse($row->referencePeriodProxy);
    }

    public function test_announced_non_calendar_reset_proxy_keeps_earlier_observed_pricing_proof(): void
    {
        $row = $this->resetObservation(true, '2026-07-10');
        $this->assertTrue($row->referencePeriodProxy);
        $this->assertSame('2026-04-14', $row->pricingDate->toDateString());
        $model = $this->resetObservation(true, '2026-07-10', '2026-04-15');
        $resolver = new ForwardPremiumResolver;
        $this->assertNull($resolver->resolve(new PremiumTarget('target', 'target', 'Company', $model->compatibility, CarbonImmutable::parse('2026-04-14')), [$model]));
        $this->assertNotNull($resolver->resolve(new PremiumTarget('target', 'target', 'Company', $model->compatibility, CarbonImmutable::parse('2026-04-15')), [$model]));
        $this->expectException(InvalidArgumentException::class);
        $this->resetObservation(true, '2026-07-10', '2026-07-11');
    }

    public function test_reset_proxy_rejects_trade_on_pricing_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resetObservation(true, '2026-07-10', trade: '2026-04-14');
    }

    private function resetObservation(bool $proxy, string $start, string $pricingDate = '2026-04-14', string $trade = '2026-04-13'): PremiumObservation
    {
        return new PremiumObservation('peer', 'Company', new PremiumCompatibility(PremiumFamily::MarketReset, MeteringType::General, PremiumVatBasis::Included, [ComponentType::EnergyGeneral], 'quarterly'),
            ['energy_general' => 3.0], 'retail-energy-8', CarbonImmutable::parse('2026-04-14'), CarbonImmutable::parse($trade),
            CarbonImmutable::parse($start), CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-09-30'),
            'announced reset', pricingDate: CarbonImmutable::parse($pricingDate), referencePeriodProxy: $proxy);
    }

    private function observation(bool $proxy, string $trade = '2026-06-14'): PremiumObservation
    {
        return new PremiumObservation('peer', 'Company', new PremiumCompatibility(PremiumFamily::SupplierAdjusted, MeteringType::General, PremiumVatBasis::Included, [ComponentType::EnergyGeneral]),
            ['energy_general' => -1.0], 'retail-energy-8', CarbonImmutable::parse('2026-06-20'), CarbonImmutable::parse($trade),
            CarbonImmutable::parse('2026-06-15'), CarbonImmutable::parse('2026-06-20'), CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'),
            'observed proxy', pricingDate: CarbonImmutable::parse('2026-06-15'), referencePeriodProxy: $proxy);
    }
}
