<?php

namespace App\Services\ContractListing;

use App\Enums\MeteringType;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractCard\Enums\PricingBucket;
use App\Services\ContractCard\PricingCategoryResolver;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContractListingPipeline
{
    public const QUARTERLY_PHRASES = [
        'kvartaali',
        'kolmen kuukauden jaksoissa',
        'kolmen kuukauden jaksolle',
        'kolmen kuukauden välein',
        'neljästi vuodessa',
        'neljä kertaa vuodessa',
    ];

    public function __construct(
        private readonly ContractListCacheService $cache,
        private readonly ContractPriceCalculator $calculator,
        private readonly CO2EmissionsCalculator $emissionsCalculator,
        private readonly CanonicalContractPricingService $canonicalPricing,
    ) {}

    /**
     * Apply query constraints that come from shared interactive state.
     *
     * @param  Builder<ElectricityContract>  $query
     * @param  list<PricingBucket>  $pricingBuckets
     */
    public function applyInteractiveQueryConstraints(
        Builder $query,
        string $contractType,
        array $pricingBuckets,
        string $metering,
        string $postcode,
    ): void {
        if ($contractType !== '') {
            $query->where('contract_type', $contractType);
        }

        if ($pricingBuckets !== []) {
            $query->where(function (Builder $query) use ($pricingBuckets) {
                foreach ($pricingBuckets as $bucket) {
                    $query->orWhere(function (Builder $query) use ($bucket) {
                        PricingCategoryResolver::scopeBucket($query, $bucket);
                    });
                }
            });
        }

        if ($metering !== '') {
            $query->where('metering', $metering);
        }

        if ($postcode !== '') {
            $query->where(function (Builder $query) use ($postcode) {
                $query->where('availability_is_national', true)
                    ->orWhereExists(function ($query) use ($postcode) {
                        $query->select(DB::raw(1))
                            ->from('contract_postcode')
                            ->whereColumn('contract_postcode.contract_id', 'electricity_contracts.id')
                            ->where('contract_postcode.postcode', $postcode);
                    });
            });
        }
    }

    /**
     * Apply a shared listing pseudo-type and report whether the type was handled.
     *
     * @param  Builder<ElectricityContract>  $query
     */
    public function applySharedPricingTypeConstraint(Builder $query, ?string $pricingType): bool
    {
        if ($pricingType === 'Quarterly') {
            $query->where(function (Builder $query) {
                foreach (['name', 'extra_information_fi'] as $column) {
                    foreach (self::QUARTERLY_PHRASES as $phrase) {
                        $query->orWhere($column, 'LIKE', "%{$phrase}%");
                    }
                }
            });

            return true;
        }

        if ($pricingType === 'TimeOfUse') {
            $query->where(function (Builder $query) {
                $query->where('metering', MeteringType::Time->value)
                    ->orWhere('name', 'LIKE', '%aikasähkö%')
                    ->orWhere('name', 'LIKE', '%Aikasähkö%')
                    ->orWhere('extra_information_fi', 'LIKE', '%aikasähkö%');
            });

            return true;
        }

        if ($pricingType === 'Seasonal') {
            $query->where(function (Builder $query) {
                $query->where('metering', MeteringType::Season->value)
                    ->orWhere('name', 'LIKE', '%kausisähkö%')
                    ->orWhere('name', 'LIKE', '%Kausisähkö%')
                    ->orWhere('extra_information_fi', 'LIKE', '%kausisähkö%');
            });

            return true;
        }

        return false;
    }

    public static function matchesQuarterly(?string ...$texts): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter($texts)));

        foreach (self::QUARTERLY_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public function filterInteractiveEnergySources(
        Collection $contracts,
        bool $renewable,
        bool $nuclear,
        bool $fossilFree,
    ): Collection {
        if ($renewable) {
            $contracts = $contracts->filter(function (ElectricityContract $contract) {
                $source = $contract->electricitySource;

                return $source && $source->renewable_total >= 50;
            });
        }

        if ($nuclear) {
            $contracts = $contracts->filter(function (ElectricityContract $contract) {
                $source = $contract->electricitySource;

                return $source && $source->hasNuclear();
            });
        }

        if ($fossilFree) {
            $contracts = $contracts->filter(function (ElectricityContract $contract) {
                $source = $contract->electricitySource;

                return $source && $source->isFossilFree();
            });
        }

        return $contracts;
    }

    public function filterForConsumption(Collection $contracts, int $consumption): Collection
    {
        return $contracts
            ->filter(fn (ElectricityContract $contract) => $contract->isConsumptionInRange($consumption))
            ->values();
    }

    /**
     * Attach annual metrics, remove canonical exclusions, and sort by annual cost.
     * Local cards request legacy component relations, so that path uses the cold
     * calculation once and reuses the same latest-component batch.
     */
    public function enrichAndSortAnnual(
        Collection $contracts,
        int $consumption,
        bool $loadLegacyCardPrices = false,
    ): Collection {
        if (! $loadLegacyCardPrices) {
            $cached = $this->applyCachedMetrics($contracts, $consumption);

            if ($cached !== null) {
                return $cached;
            }
        }

        $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);
        $useCanonical = $this->canonicalPricing->enabled();
        $canonicalMetrics = $useCanonical
            ? $this->canonicalPricing->metricsForContracts($contracts, $usage)
            : [];

        $spotPriceAverage = $useCanonical ? null : SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAverage?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAverage?->night_avg_with_tax;
        $priceComponentsByContractId = $useCanonical
            ? []
            : ElectricityContract::getLatestPriceComponentsForCalculationByContractIds($contracts->pluck('id'));

        $contracts = $contracts->map(function (ElectricityContract $contract) use (
            $canonicalMetrics,
            $consumption,
            $loadLegacyCardPrices,
            $priceComponentsByContractId,
            $spotPriceDay,
            $spotPriceNight,
            $usage,
            $useCanonical,
        ) {
            $contract->emission_factor = $this->emissionsCalculator->calculateEmissionFactor($contract->electricitySource);
            $maxConsumption = $contract->consumption_limitation_max_x_kwh_per_y;
            $contract->exceeds_consumption_limit = $maxConsumption > 0 && $consumption > $maxConsumption;

            if ($useCanonical) {
                $canonical = $canonicalMetrics[$contract->id] ?? null;
                if (! $canonical instanceof CanonicalContractMetric) {
                    throw new InvalidArgumentException('Canonical metrics are missing contract '.$contract->id.'.');
                }

                $contract->calculated_cost = $canonical->pricing()->toArray();
                $contract->pricing_integrity = $canonical->integrity()->toArray();
                $contract->comparability = $canonical->comparability()->value;
                $contract->is_listed = $canonical->isListed();
                $contract->sort_key = $canonical->sortKey();

                return $contract;
            }

            $priceComponents = $priceComponentsByContractId[$contract->id] ?? [];
            if ($loadLegacyCardPrices) {
                $this->setLatestPriceComponentsRelation($contract, $priceComponents);
            }

            $result = $this->calculator->calculate(
                $priceComponents,
                [
                    'contract_type' => $contract->contract_type,
                    'pricing_model' => $contract->pricing_model,
                    'metering' => $contract->metering,
                ],
                $usage,
                $spotPriceDay,
                $spotPriceNight,
            );

            $contract->calculated_cost = ContractPricingViewData::fromLegacyResult($result)->toArray();
            $contract->pricing_integrity = null;
            $contract->comparability = null;
            $contract->is_listed = true;
            $contract->sort_key = $result->totalCost;

            return $contract;
        });

        if ($useCanonical) {
            $contracts = $contracts->filter(fn (ElectricityContract $contract) => $contract->is_listed)->values();
        }

        return $this->sortAnnual($contracts);
    }

    /**
     * Load the page slice with only the latest legacy price components.
     */
    public function loadVisibleContracts(Collection $sorted, int $offset, int $perPage): Collection
    {
        $visibleSummaries = $sorted->slice($offset, $perPage)->values();
        $visibleIds = $visibleSummaries->pluck('id')->all();

        if ($visibleIds === []) {
            return new Collection;
        }

        $contractsById = ElectricityContract::query()
            ->with(['company', 'electricitySource'])
            ->whereIn('id', $visibleIds)
            ->get()
            ->keyBy('id');

        $useCanonical = $this->canonicalPricing->enabled();
        $priceComponentsByContractId = $useCanonical
            ? []
            : ElectricityContract::getLatestPriceComponentsForCalculationByContractIds($visibleIds);

        return $visibleSummaries->map(function (ElectricityContract $summary) use ($contractsById, $priceComponentsByContractId, $useCanonical) {
            /** @var ElectricityContract|null $contract */
            $contract = $contractsById->get($summary->id);

            if ($contract === null) {
                return null;
            }

            if (! $useCanonical) {
                $this->setLatestPriceComponentsRelation($contract, $priceComponentsByContractId[$contract->id] ?? []);
            }

            $contract->calculated_cost = $summary->calculated_cost;
            $contract->emission_factor = $summary->emission_factor;
            $contract->exceeds_consumption_limit = $summary->exceeds_consumption_limit;
            $contract->pricing_integrity = $summary->pricing_integrity ?? null;
            $contract->comparability = $summary->comparability ?? null;

            return $contract;
        })->filter()->values();
    }

    /**
     * @param  array<string, int|string>  $query
     * @param  (callable(Collection): Collection)|null  $transform
     */
    public function paginate(
        Collection $sorted,
        int $page,
        int $perPage,
        string $path,
        array $query = [],
        ?callable $transform = null,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $items = $this->loadVisibleContracts($sorted, ($page - 1) * $perPage, $perPage);

        if ($transform !== null) {
            $items = $transform($items);
        }

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $sorted->count(),
            $perPage,
            $page,
            [
                'path' => $path,
                'pageName' => 'page',
                'query' => $query,
            ],
        );
    }

    private function applyCachedMetrics(Collection $contracts, int $consumption): ?Collection
    {
        $cached = $this->cache->getCachedMetrics($consumption);

        if ($cached === null) {
            return null;
        }

        $contractsById = $contracts->keyBy('id');

        if ($contractsById->keys()->diff(array_keys($cached->metrics()))->isNotEmpty()) {
            return null;
        }

        $sortedContracts = [];

        foreach ($cached->sortedIds() as $contractId) {
            if (! $contractsById->has($contractId)) {
                continue;
            }

            $contract = $contractsById->get($contractId);
            $metric = $cached->metric($contractId);

            if ($metric === null) {
                return null;
            }

            $contract->calculated_cost = $metric->pricing()->toArray();
            $contract->emission_factor = $metric->emissionFactor();
            $contract->exceeds_consumption_limit = $metric->exceedsConsumptionLimit();
            $contract->pricing_integrity = $metric->integrity()?->toArray();
            $contract->comparability = $metric->comparability();
            $sortedContracts[] = $contract;
        }

        return new Collection($sortedContracts);
    }

    private function sortAnnual(Collection $contracts): Collection
    {
        return $contracts->sort(function (ElectricityContract $a, ElectricityContract $b) {
            $aExceeds = $a->exceeds_consumption_limit ? 1 : 0;
            $bExceeds = $b->exceeds_consumption_limit ? 1 : 0;

            if ($aExceeds !== $bExceeds) {
                return $aExceeds <=> $bExceeds;
            }

            if ((! is_int($a->sort_key) && ! is_float($a->sort_key)) || ! is_finite((float) $a->sort_key)) {
                throw new InvalidArgumentException('A listed contract requires a finite sort key.');
            }
            if ((! is_int($b->sort_key) && ! is_float($b->sort_key)) || ! is_finite((float) $b->sort_key)) {
                throw new InvalidArgumentException('A listed contract requires a finite sort key.');
            }

            return $a->sort_key <=> $b->sort_key;
        })->values();
    }

    /** @param array<int, array<string, mixed>> $components */
    private function setLatestPriceComponentsRelation(ElectricityContract $contract, array $components): void
    {
        $contract->setRelation('priceComponents', new Collection(
            array_map(fn (array $component) => new PriceComponent($component), $components),
        ));
    }
}
