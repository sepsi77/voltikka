<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElectricityContract;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyUsage;
use App\Services\EnergyCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalculationController extends Controller
{
    public function __construct(
        private readonly ContractPriceCalculator $priceCalculator,
        private readonly EnergyCalculator $energyCalculator,
    ) {
    }

    /**
     * Calculate the annual electricity cost for a contract.
     *
     * Accepts either:
     * - consumption (int): Total annual kWh consumption
     * - energy_usage (array): Detailed breakdown of energy usage
     *
     * @return JsonResponse
     */
    public function calculatePrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|string',
            'consumption' => 'required_without:energy_usage|integer|min:0',
            'energy_usage' => 'required_without:consumption|array',
            'energy_usage.total' => 'required_with:energy_usage|integer|min:0',
            'energy_usage.basic_living' => 'sometimes|integer|min:0',
            'energy_usage.room_heating' => 'sometimes|integer|min:0',
            'energy_usage.bathroom_underfloor_heating' => 'sometimes|integer|min:0',
            'energy_usage.water' => 'sometimes|integer|min:0',
            'energy_usage.sauna' => 'sometimes|integer|min:0',
            'energy_usage.electricity_vehicle' => 'sometimes|integer|min:0',
            'energy_usage.cooling' => 'sometimes|numeric|min:0',
            'energy_usage.heating_electricity_use_by_month' => 'sometimes|array|size:12',
            'spot_price_day' => 'sometimes|numeric',
            'spot_price_night' => 'sometimes|numeric',
        ]);

        // Find the contract
        $contract = ElectricityContract::with(['priceComponents'])->find($validated['contract_id']);

        if (!$contract) {
            return response()->json([
                'error' => 'Contract not found',
            ], 404);
        }

        // Build energy usage object
        if (isset($validated['energy_usage'])) {
            $usage = EnergyUsage::fromArray($validated['energy_usage']);
        } else {
            $consumption = $validated['consumption'];
            $usage = new EnergyUsage(
                total: $consumption,
                basicLiving: $consumption,
            );
        }

        // Get the latest price components (prefer non-zero prices when duplicates exist)
        $priceComponents = $contract->priceComponents
            ->sortByDesc('price_date')
            ->groupBy('price_component_type')
            ->map(fn ($group) => $group->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $group->sortByDesc('price_date')->first())
            ->values()
            ->map(fn ($pc) => [
                'price_component_type' => $pc->price_component_type,
                'price' => $pc->price,
            ])
            ->toArray();

        $contractData = [
            'contract_type' => $contract->contract_type,
            'metering' => $contract->metering,
        ];

        $spotPriceDay = $validated['spot_price_day'] ?? null;
        $spotPriceNight = $validated['spot_price_night'] ?? null;

        $result = $this->priceCalculator->calculate(
            $priceComponents,
            $contractData,
            $usage,
            $spotPriceDay,
            $spotPriceNight,
        );

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }

    /**
     * Estimate annual electricity consumption based on building parameters.
     *
     * @return JsonResponse
     */
    public function estimateConsumption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'living_area' => 'required|integer|min:1',
            'num_people' => 'required|integer|min:1',
            'building_type' => 'sometimes|string|in:detached_house,apartment,row_house',
            'heating_method' => 'sometimes|string|in:electricity,air_to_water_heat_pump,ground_heat_pump,district_heating,oil,fireplace,pellets,other',
            'supplementary_heating' => 'sometimes|string|in:heat_pump,exhaust_air_heat_pump,fireplace',
            'building_energy_efficiency' => 'sometimes|string|in:passive,low_energy,2010,2000,1990,1980,1970,1960,older',
            'building_region' => 'sometimes|string|in:south,central,north',
            'electric_vehicle_kms_per_month' => 'sometimes|integer|min:0',
            'bathroom_heating_area' => 'sometimes|integer|min:0',
            'sauna_usage_per_week' => 'sometimes|integer|min:0',
            'sauna_is_always_on_type' => 'sometimes|boolean',
            'external_heating' => 'sometimes|boolean',
            'external_heating_water' => 'sometimes|boolean',
            'cooling' => 'sometimes|boolean',
        ]);

        $calculatorRequest = EnergyCalculatorRequest::fromArray($validated);
        $result = $this->energyCalculator->estimate($calculatorRequest);

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }
}
