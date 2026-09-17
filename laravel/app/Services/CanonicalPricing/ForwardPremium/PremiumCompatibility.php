<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\Enums\ComponentType;
use InvalidArgumentException;

final readonly class PremiumCompatibility
{
    /** @var list<string> Canonical energy component names, in sorted order. */
    public array $buckets;

    /** @param list<ComponentType> $buckets */
    public function __construct(
        public PremiumFamily $family,
        public MeteringType $metering,
        public PremiumVatBasis $vatBasis,
        array $buckets,
        public ?string $resetCadence = null,
    ) {
        if ((! $family->isReset() && $resetCadence !== null)
            || ($family->isReset() && ! in_array($resetCadence, ['monthly', 'quarterly', 'seasonal', 'other'], true))) {
            throw new InvalidArgumentException('Premium family requires an exact supported cadence.');
        }

        $names = [];
        foreach ($buckets as $bucket) {
            if (! $bucket instanceof ComponentType || ! $bucket->isPerKwhEnergy()) {
                throw new InvalidArgumentException('Only named energy buckets are permitted.');
            }
            $names[] = $bucket->value;
        }
        if ($names === [] || count(array_unique($names)) !== count($names)) {
            throw new InvalidArgumentException('Energy buckets must be nonempty and unique.');
        }
        sort($names, SORT_STRING);
        $this->buckets = $names;
    }

    public function matches(self $other): bool
    {
        return $this->family === $other->family
            && $this->resetCadence === $other->resetCadence
            && $this->metering === $other->metering
            && $this->vatBasis === $other->vatBasis
            && $this->buckets === $other->buckets;
    }
}
