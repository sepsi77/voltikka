<?php

namespace App\Services;

use App\Enums\PricingModel;
use App\Models\ElectricityContract;
use App\Services\Caching\ContractPriceCacheLifecycle;
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
    private const PAYLOAD_SCHEMA_VERSION = 2;

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
        $generation = $this->lifecycle->active();
        $cacheKey = $this->getCacheKey($consumption, $generation);
        if (isset($this->cachedCompaniesMemo[$cacheKey])) {
            return $this->cachedCompaniesMemo[$cacheKey];
        }

        $companies = Cache::get($cacheKey);
        if ($companies === null) {
            $companies = $this->buildCachedCompanies($consumption);
            $this->lifecycle->write($generation, $cacheKey, $companies);
        }

        return $this->cachedCompaniesMemo[$cacheKey] = $companies;
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
            'company_list:v%d:s%d:%s:lv%d:%s:%d:g%s:%s',
            ($generation ??= $this->lifecycle->active())['version'],
            self::PAYLOAD_SCHEMA_VERSION,
            CalculatedCostPayloadSchema::cacheMarker(),
            $generation['version'],
            $this->pricingMode->cacheMarker(),
            $consumption,
            $generation['generation'],
            $this->contractListCache->safetyFingerprint(),
        );
    }

    public function buildCachedCompanies(int $consumption, ?ContractMetricSet $cachedMetrics = null): Collection
    {
        $cachedMetrics ??= $this->contractListCache->getCachedMetrics($consumption);
        if ($cachedMetrics === null) {
            throw new InvalidArgumentException('Company pricing requires a supported cached consumption.');
        }

        $contracts = ElectricityContract::query()
            ->active()
            ->with(['company', 'electricitySource'])
            ->whereHas('company')
            ->get();

        $contractsByCompany = $contracts->groupBy('company_name');
        $companies = collect();
        $useCanonical = $this->canonicalPricing->enabled();

        foreach ($contractsByCompany as $allCompanyContracts) {
            $companyContracts = $useCanonical
                ? $allCompanyContracts->filter(function (ElectricityContract $contract) use ($cachedMetrics): bool {
                    $metric = $cachedMetrics->metric($contract->id);

                    return $metric !== null
                        && $metric->isListed()
                        && $metric->pricing()->pricingBasis() === 'canonical';
                })
                : $allCompanyContracts;

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
