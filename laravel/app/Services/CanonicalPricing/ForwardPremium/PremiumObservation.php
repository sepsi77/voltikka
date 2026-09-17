<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Prepared energy-only spreads in normalized c/kWh. No fee or Spot/Hybrid component. */
final readonly class PremiumObservation
{
    /** @var array<string, float> */
    public array $premiumsByBucket;

    public CarbonImmutable $observedAt;

    public CarbonImmutable $referenceTradeDate;

    public CarbonImmutable $pricePeriodStart;

    public CarbonImmutable $pricePeriodEnd;

    public CarbonImmutable $referenceDeliveryStart;

    public CarbonImmutable $referenceDeliveryEnd;

    public ?CarbonImmutable $pricingDate;

    /**
     * Dates are calendar dates; period ends are inclusive. Supplier pricingDate is the
     * observed episode start. Reset pricingDate is the model bound min(period start, as-of);
     * the selector separately checks that both model and retail evidence are available.
     * energyOfferSignature is a caller-verified identity of the complete retail energy offer:
     * named rates and energy terms, without contract IDs, fees, or observation dates.
     * It must not be a hash of the premium alone.
     *
     * @param  array<string, float>  $premiumsByBucket  Canonical ComponentType values as keys.
     */
    public function __construct(
        public string $lineageId,
        public string $companyName,
        public PremiumCompatibility $compatibility,
        array $premiumsByBucket,
        public string $energyOfferSignature,
        CarbonImmutable $observedAt,
        CarbonImmutable $referenceTradeDate,
        CarbonImmutable $pricePeriodStart,
        CarbonImmutable $pricePeriodEnd,
        CarbonImmutable $referenceDeliveryStart,
        CarbonImmutable $referenceDeliveryEnd,
        public string $provenance,
        ?CarbonImmutable $pricingDate = null,
        public bool $referencePeriodProxy = false,
    ) {
        foreach ([$lineageId, $companyName, $energyOfferSignature, $provenance] as $identity) {
            if (trim($identity) === '') {
                throw new InvalidArgumentException('Premium evidence requires nonempty trusted identities and provenance.');
            }
        }
        ksort($premiumsByBucket, SORT_STRING);
        if (array_keys($premiumsByBucket) !== $compatibility->buckets) {
            throw new InvalidArgumentException('Premiums must cover the exact named energy bucket set.');
        }
        foreach ($premiumsByBucket as &$premium) {
            if ((! is_int($premium) && ! is_float($premium)) || ! is_finite((float) $premium)) {
                throw new InvalidArgumentException('Premiums must be finite normalized energy c/kWh.');
            }
            $premium = (float) $premium;
        }
        unset($premium);
        $this->premiumsByBucket = $premiumsByBucket;
        $this->observedAt = $observedAt->startOfDay();
        $this->referenceTradeDate = $referenceTradeDate->startOfDay();
        $this->pricePeriodStart = $pricePeriodStart->startOfDay();
        $this->pricePeriodEnd = $pricePeriodEnd->startOfDay();
        $this->referenceDeliveryStart = $referenceDeliveryStart->startOfDay();
        $this->referenceDeliveryEnd = $referenceDeliveryEnd->startOfDay();
        $pricingDate = $this->pricingDate = $pricingDate?->startOfDay();

        if ($pricingDate !== null && ((! $compatibility->family->isReset() && $pricingDate->gt($this->observedAt))
            || ! $this->referenceTradeDate->lt($pricingDate)
            || ($compatibility->family->isReset() && $pricingDate->gt($this->pricePeriodStart)))) {
            throw new InvalidArgumentException('A supplied pricing date requires earlier trade and observed evidence.');
        }
        if ($referencePeriodProxy && ($pricingDate === null
            || ($compatibility->family->isReset()
                ? ($pricingDate->gt($this->pricePeriodStart)
                    || ! $this->pricePeriodEnd->betweenIncluded($this->referenceDeliveryStart, $this->referenceDeliveryEnd))
                : ! $pricingDate->betweenIncluded($this->referenceDeliveryStart, $this->referenceDeliveryEnd)))) {
            throw new InvalidArgumentException('A reference proxy requires supported pricing and delivery bounds.');
        }
        if ($this->pricePeriodEnd->lt($this->pricePeriodStart)
            || $this->referenceDeliveryEnd->lt($this->referenceDeliveryStart)
            || (! $referencePeriodProxy && (! $this->pricePeriodStart->eq($this->referenceDeliveryStart)
                || ! $this->pricePeriodEnd->eq($this->referenceDeliveryEnd)))
            || ! $this->referenceTradeDate->lt($referencePeriodProxy ? $pricingDate : $this->pricePeriodStart)) {
            throw new InvalidArgumentException('Reference delivery must match its retail period, with an earlier trade date.');
        }
    }

    /** A different provenance alone is not conflicting price evidence. */
    public function evidenceKey(): string
    {
        return json_encode([
            $this->companyName, $this->energyOfferSignature, $this->premiumsByBucket,
            $this->referenceTradeDate->toDateString(),
            $this->pricePeriodStart->toDateString(), $this->pricePeriodEnd->toDateString(),
            $this->referencePeriodProxy, $this->pricingDate?->toDateString(),
            $this->referenceDeliveryStart->toDateString(), $this->referenceDeliveryEnd->toDateString(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
