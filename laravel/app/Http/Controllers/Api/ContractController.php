<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractCollection;
use App\Http\Resources\ContractResource;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractPriceCalculator $priceCalculator,
        private readonly CanonicalContractPricingService $canonicalPricing,
    ) {}

    /**
     * List all electricity contracts with optional filtering.
     *
     * Query parameters:
     * - contract_type: Filter by contract type (Fixed, Spot, OpenEnded)
     * - metering: Filter by metering type (General, Time, Seasonal)
     * - postcode: Filter by availability in a specific postcode
     * - energy_source: Filter by energy source (renewable, nuclear, fossil_free)
     * - consumption: Annual consumption in kWh (for cost calculation)
     * - sort: Sort by field (cost, name, company)
     * - per_page: Number of results per page (default: 20, max: 100)
     * - page: Page number
     */
    public function index(Request $request)
    {
        $relations = ['company', 'electricitySource'];
        if (! $this->canonicalPricing->enabled()) {
            $relations[] = 'priceComponents';
        }

        $query = ElectricityContract::query()->with($relations);

        // Filter by contract type
        if ($request->has('contract_type')) {
            $query->where('contract_type', $request->input('contract_type'));
        }

        // Filter by metering type
        if ($request->has('metering')) {
            $query->where('metering', $request->input('metering'));
        }

        // Filter by postcode availability
        if ($request->has('postcode')) {
            $postcode = $request->input('postcode');
            $query->where(function ($q) use ($postcode) {
                // National contracts are available everywhere
                $q->where('availability_is_national', true)
                    ->orWhereHas('availabilityPostcodes', function ($pq) use ($postcode) {
                        $pq->where('postcodes.postcode', $postcode);
                    });
            });
        }

        // Filter by energy source
        if ($request->has('energy_source')) {
            $energySource = $request->input('energy_source');
            $query->whereHas('electricitySource', function ($eq) use ($energySource) {
                match ($energySource) {
                    'renewable' => $eq->where('renewable_total', '>=', 100),
                    'nuclear' => $eq->where('nuclear_total', '>', 0),
                    'fossil_free' => $eq->where(function ($q) {
                        $q->whereNull('fossil_total')
                            ->orWhere('fossil_total', '<=', 0);
                    }),
                    default => null,
                };
            });
        }

        // Calculate costs if consumption is provided
        $consumption = $request->input('consumption');
        $shouldCalculateCosts = $consumption !== null && is_numeric($consumption);

        // Get pagination parameters
        $perPage = min((int) $request->input('per_page', 20), 100);

        // Get the contracts
        $contracts = $query->paginate($perPage);

        if ($this->canonicalPricing->enabled()) {
            $this->attachCanonicalPricing(
                $contracts->getCollection(),
                $shouldCalculateCosts ? (int) $consumption : 1,
                $shouldCalculateCosts,
            );
        } elseif ($shouldCalculateCosts) {
            $contracts->getCollection()->each(function (ElectricityContract $contract) use ($consumption) {
                $contract->calculated_cost = $this->calculateLegacyContractCost($contract, (int) $consumption);
            });
        }

        // Sort by calculated cost if requested
        if ($request->input('sort') === 'cost' && $shouldCalculateCosts) {
            $sortedCollection = $contracts->getCollection()->sort(function (ElectricityContract $left, ElectricityContract $right): int {
                $leftTotal = ContractPricingViewData::fromArray($left->calculated_cost)->total();
                $rightTotal = ContractPricingViewData::fromArray($right->calculated_cost)->total();

                if ($leftTotal === null || $rightTotal === null) {
                    return $leftTotal === $rightTotal ? 0 : ($leftTotal === null ? 1 : -1);
                }

                return $leftTotal <=> $rightTotal;
            })->values();
            $contracts->setCollection($sortedCollection);
        }

        return new ContractCollection($contracts);
    }

    /**
     * Get a single contract by ID.
     */
    public function show(Request $request, string $id)
    {
        $relations = ['company', 'electricitySource'];
        if (! $this->canonicalPricing->enabled()) {
            $relations[] = 'priceComponents';
        }

        $contract = ElectricityContract::with($relations)->find($id);

        if (! $contract) {
            return response()->json([
                'error' => 'Contract not found',
            ], 404);
        }

        $consumption = $request->input('consumption');
        $shouldCalculateCosts = $consumption !== null && is_numeric($consumption);

        if ($this->canonicalPricing->enabled()) {
            $this->attachCanonicalPricing(
                new Collection([$contract]),
                $shouldCalculateCosts ? (int) $consumption : 1,
                $shouldCalculateCosts,
            );
        } elseif ($shouldCalculateCosts) {
            $contract->calculated_cost = $this->calculateLegacyContractCost($contract, (int) $consumption);
        }

        return new ContractResource($contract);
    }

    /**
     * Attach one batch of canonical current-pricing results without loading relational prices.
     * A one-kWh internal usage gives the typed unit/offer state when no calculated cost was
     * requested; no total from that internal basis is returned.
     *
     * @param  Collection<int, ElectricityContract>  $contracts
     */
    private function attachCanonicalPricing(Collection $contracts, int $consumption, bool $includeCalculatedCost): void
    {
        $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);
        $metrics = $this->canonicalPricing->metricsForContracts($contracts, $usage);

        $contracts->each(function (ElectricityContract $contract) use ($metrics, $includeCalculatedCost) {
            $metric = $metrics[$contract->id] ?? null;
            if (! $metric instanceof CanonicalContractMetric) {
                throw new \InvalidArgumentException('Canonical metrics are missing contract '.$contract->id.'.');
            }
            $pricing = $metric->pricing();

            $contract->current_pricing = $this->canonicalCurrentPricing($metric);
            $contract->canonical_pricing_has_discounts = $pricing->includesDiscounts();

            if ($includeCalculatedCost) {
                $contract->calculated_cost = $pricing->toArray();
            }
        });
    }

    /**
     * Keep canonical current rates explicit instead of synthesizing relational component rows.
     *
     * @return array<string, mixed>
     */
    private function canonicalCurrentPricing(CanonicalContractMetric $metric): array
    {
        $pricing = $metric->pricing();
        $integrity = $metric->integrity();
        $isAvailable = $metric->isListed() && $pricing->total() !== null;
        $comparability = $metric->comparability()->value;

        return [
            'pricing_basis' => 'canonical',
            'availability' => $isAvailable ? 'available' : 'unavailable',
            'is_listed' => $metric->isListed(),
            'comparability' => $comparability,
            'exclusion_reason' => $metric->isListed() ? null : $comparability,
            'is_estimate' => $pricing->isEstimate(),
            'estimate_method' => $pricing->estimateMethod()?->value,
            'includes_discounts' => $isAvailable && $pricing->includesDiscounts(),
            'monthly_fixed_fee' => $isAvailable ? $pricing->monthlyFixedFee() : null,
            'spot_price_margin' => $isAvailable ? $pricing->spotPriceMargin() : null,
            'general_kwh_price' => $isAvailable ? $pricing->generalKwhPrice() : null,
            'nighttime_kwh_price' => $isAvailable ? $pricing->nighttimeKwhPrice() : null,
            'daytime_kwh_price' => $isAvailable ? $pricing->daytimeKwhPrice() : null,
            'seasonal_winter_day_kwh_price' => $isAvailable ? $pricing->seasonalWinterDayKwhPrice() : null,
            'seasonal_other_kwh_price' => $isAvailable ? $pricing->seasonalOtherKwhPrice() : null,
            'term_months' => $isAvailable ? $pricing->termMonths() : null,
            'energy_package' => $isAvailable ? $pricing->energyPackage()?->toArray() : null,
            'phase_breakdown' => $isAvailable ? array_map(fn ($phase) => $phase->toArray(), $pricing->phases()) : [],
            'consumption_effect' => $isAvailable ? $pricing->consumptionEffect()?->toArray() : null,
            'assumptions' => $isAvailable ? $pricing->assumptions() : [],
            'reset_estimate' => $isAvailable ? $pricing->resetEstimate()?->toArray() : null,
            'supplier_adjusted_estimate' => $isAvailable ? $pricing->supplierAdjustedEstimate()?->toArray() : null,
            'integrity' => $isAvailable ? $integrity->toArray() : [
                'detected' => $integrity->detected,
                'reason_family' => $integrity->reasonFamily->value,
                'issue_codes' => $integrity->issueCodes,
            ],
        ];
    }

    /**
     * Calculate the annual cost from relational components only in feature-off mode.
     */
    private function calculateLegacyContractCost(ElectricityContract $contract, int $consumption): array
    {
        $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);
        $priceComponents = $contract->getLatestPriceComponentsForCalculation();

        $contractData = [
            'contract_type' => $contract->contract_type,
            'pricing_model' => $contract->pricing_model,
            'metering' => $contract->metering,
        ];

        $result = $this->priceCalculator->calculate($priceComponents, $contractData, $usage);

        return ContractPricingViewData::fromLegacyResult($result)->toArray();
    }
}
