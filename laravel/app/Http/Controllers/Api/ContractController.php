<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractCollection;
use App\Http\Resources\ContractResource;
use App\Models\ElectricityContract;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractPriceCalculator $priceCalculator,
    ) {
    }

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
        $query = ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource']);

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

        // Calculate costs for each contract if consumption is provided
        if ($shouldCalculateCosts) {
            $contracts->getCollection()->transform(function ($contract) use ($consumption) {
                $contract->calculated_cost = $this->calculateContractCost($contract, (int) $consumption);
                return $contract;
            });
        }

        // Sort by calculated cost if requested
        if ($request->input('sort') === 'cost' && $shouldCalculateCosts) {
            $sortedCollection = $contracts->getCollection()->sortBy(function ($contract) {
                return $contract->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX;
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
        $contract = ElectricityContract::with([
            'company',
            'priceComponents',
            'electricitySource',
        ])->find($id);

        if (!$contract) {
            return response()->json([
                'error' => 'Contract not found',
            ], 404);
        }

        // Calculate costs if consumption is provided
        $consumption = $request->input('consumption');
        if ($consumption !== null && is_numeric($consumption)) {
            $contract->calculated_cost = $this->calculateContractCost($contract, (int) $consumption);
        }

        return new ContractResource($contract);
    }

    /**
     * Calculate the annual cost for a contract given consumption.
     */
    private function calculateContractCost(ElectricityContract $contract, int $consumption): array
    {
        // Get the latest price components
        $priceComponents = $contract->priceComponents
            ->sortByDesc('price_date')
            ->groupBy('price_component_type')
            ->map(fn ($group) => $group->first())
            ->values()
            ->map(fn ($pc) => [
                'price_component_type' => $pc->price_component_type,
                'price' => $pc->price,
            ])
            ->toArray();

        // Create a simple energy usage object
        $usage = new EnergyUsage(
            total: $consumption,
            basicLiving: $consumption,
        );

        $contractData = [
            'contract_type' => $contract->contract_type,
            'metering' => $contract->metering,
        ];

        $result = $this->priceCalculator->calculate($priceComponents, $contractData, $usage);

        return $result->toArray();
    }
}
