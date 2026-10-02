<?php

namespace App\Services;

use App\Enums\PricingModel;
use App\Models\ElectricityContract;
use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\Caching\ContractPriceCacheUnavailable;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractPricing\ContractMetric;
use App\Services\ContractPricing\ContractMetricSet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class CompanyListCacheService
{
    /**
     * Shape marker for the prepared company payload.
     *
     * The import version and pricing flags do not change on a code-only deploy. Bump this
     * value whenever company metric membership or payload fields change.
     */
    private const PAYLOAD_SCHEMA_VERSION = 3;

    private const DEFAULT_CONSUMPTION = 5000;

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $cachedCompaniesMemo = [];

    public function __construct(
        private readonly ContractListCacheService $contractListCache,
        private readonly CanonicalContractPricingService $canonicalPricing,
        private readonly PricingMode $pricingMode,
        private readonly ContractPriceCacheLifecycle $lifecycle,
    ) {}

    public function getCachedCompanies(int $consumption = self::DEFAULT_CONSUMPTION): Collection
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->readCachedCompanies($consumption);
            } catch (ContractPriceCacheConflict $exception) {
                $this->cachedCompaniesMemo = [];
                $this->contractListCache->resetCalculationState();
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }
    }

    private function readCachedCompanies(int $consumption): Collection
    {
        $generation = $this->lifecycle->active();
        $cacheKey = $this->getCacheKey($consumption, $generation);
        $availability = $this->contractListCache->availabilityFingerprint();
        $memoKey = $cacheKey.':'.$availability;
        if (isset($this->cachedCompaniesMemo[$memoKey])) {
            return $this->cachedCompaniesMemo[$memoKey];
        }

        $custom = ! in_array($consumption, ContractListCacheService::PRESET_CONSUMPTIONS, true);
        // Custom wrappers must never outlive the exact shared handoff or persist forever.
        $companies = $custom ? null : Cache::get($cacheKey);
        if ($companies !== null && ! $companies instanceof Collection) {
            throw new InvalidArgumentException('Cached companies must be a collection payload.');
        }
        // Empty collections have no row on which to store the availability marker.
        if ($companies === null || $companies->isEmpty()
            || $companies->contains(fn (array $company) => ($company['_availability'] ?? null) !== $availability)) {
            // This read owns the two-attempt budget; the list must not retry inside it.
            $metrics = $this->contractListCache->getCachedMetrics($consumption, retryConflicts: false);
            if ($metrics === null) {
                if ($custom) {
                    throw new ContractPriceCacheUnavailable;
                }
                throw new InvalidArgumentException('Company pricing requires a supported cached consumption.');
            }
            $companies = $this->buildCachedCompanies($consumption, $metrics);
            if ($availability !== $this->contractListCache->availabilityFingerprint()) {
                throw ContractPriceCacheConflict::evidenceChanged();
            }
            if ($custom) {
                if ($this->lifecycle->active() !== $generation) {
                    throw ContractPriceCacheConflict::generationChanged();
                }
            } else {
                $this->lifecycle->write($generation, $cacheKey, $companies);
            }
        }

        return $this->cachedCompaniesMemo[$memoKey] = $companies;
    }

    public function warm(): void
    {
        $this->getCachedCompanies();
    }

    public function bumpVersion(): int
    {
        $version = $this->lifecycle->invalidate();
        $this->cachedCompaniesMemo = [];

        return $version;
    }

    public function getVersion(): int
    {
        return $this->lifecycle->active()['version'];
    }

    public function getCacheKey(int $consumption, ?array $generation = null): string
    {
        return sprintf(
            'company_list:v%d:s%d:%s:lv%d:%s:%d:g%s',
            ($generation ??= $this->lifecycle->active())['version'],
            self::PAYLOAD_SCHEMA_VERSION,
            CalculatedCostPayloadSchema::cacheMarker(),
            $generation['version'],
            $this->pricingMode->cacheMarker(),
            $consumption,
            $generation['generation'],
        );
    }

    public function buildCachedCompanies(int $consumption, ?ContractMetricSet $cachedMetrics = null): Collection
    {
        $cachedMetrics ??= $this->contractListCache->getCachedMetrics($consumption);
        if ($cachedMetrics === null) {
            throw new InvalidArgumentException('Company pricing requires a supported cached consumption.');
        }

        $availability = $this->contractListCache->availabilityFingerprint();
        $contracts = ElectricityContract::query()
            ->active()
            ->with(['company', 'electricitySource'])
            ->whereHas('company')
            ->get();

        $contractsByCompany = $contracts->groupBy('company_name');
        $companies = collect();
        $useCanonical = $this->canonicalPricing->enabled();

        foreach ($contractsByCompany as $allCompanyContracts) {
            $companyContracts = $allCompanyContracts->filter(function (ElectricityContract $contract) use ($cachedMetrics, $useCanonical): bool {
                $metric = $cachedMetrics->metric($contract->id);

                return $metric !== null
                    && $metric->isListed()
                    && $metric->pricing()->total() !== null
                    && is_finite($metric->pricing()->total())
                    && (! $useCanonical || $metric->pricing()->pricingBasis() === 'canonical');
            });

            $company = $companyContracts->first()?->company;

            if (! $company) {
                continue;
            }

            $applicableContracts = $companyContracts->filter(function (ElectricityContract $contract) use ($cachedMetrics) {
                $metric = $cachedMetrics->metric($contract->id);
                if ($metric === null) {
                    throw new InvalidArgumentException('Company contract is missing its cached metric.');
                }

                return ! $metric->exceedsConsumptionLimit();
            });

            $priceMetrics = $applicableContracts
                ->mapWithKeys(function (ElectricityContract $contract) use ($cachedMetrics) {
                    $metric = $cachedMetrics->metric($contract->id);
                    if ($metric === null) {
                        throw new InvalidArgumentException('Company contract is missing its cached metric.');
                    }

                    return [$contract->id => $metric];
                });

            $spotContracts = $applicableContracts->filter(
                fn (ElectricityContract $contract) => $contract->pricingModelType() === PricingModel::Spot
            );

            $companies->push([
                '_availability' => $availability,
                'company' => $company,
                'contractCount' => $companyContracts->count(),
                'avgPrice' => $priceMetrics->isNotEmpty()
                    ? $priceMetrics->avg(fn (ContractMetric $metric) => $metric->pricing()->total())
                    : null,
                'lowestPrice' => $priceMetrics->isNotEmpty()
                    ? $priceMetrics->min(fn (ContractMetric $metric) => $metric->pricing()->total())
                    : null,
                'avgEmissions' => $companyContracts->avg(fn (ElectricityContract $contract) => $cachedMetrics->metric($contract->id)?->emissionFactor() ?? 0),
                'lowestEmissions' => $companyContracts->min(fn (ElectricityContract $contract) => $cachedMetrics->metric($contract->id)?->emissionFactor() ?? PHP_FLOAT_MAX),
                'avgRenewable' => $companyContracts->avg(fn (ElectricityContract $contract) => $contract->electricitySource?->renewable_total ?? 0),
                'maxRenewable' => $companyContracts->max(fn (ElectricityContract $contract) => $contract->electricitySource?->renewable_total ?? 0),
                'lowestMonthlyFee' => $priceMetrics
                    ->map(fn (ContractMetric $metric) => $metric->pricing()->monthlyFixedFee())
                    ->filter(fn (?float $fee) => $fee !== null)
                    ->min(),
                'lowestSpotMargin' => $spotContracts
                    ->map(fn (ElectricityContract $contract) => $cachedMetrics->metric($contract->id)?->pricing()->spotPriceMargin())
                    ->filter(fn (?float $margin) => $margin !== null)
                    ->min(),
                'hasSpotContracts' => $spotContracts->isNotEmpty(),
                'hasFullyRenewable' => $companyContracts->contains(fn (ElectricityContract $contract) => $contract->electricitySource && $contract->electricitySource->isFullyRenewable()
                ),
            ]);
        }

        return $companies;
    }
}
