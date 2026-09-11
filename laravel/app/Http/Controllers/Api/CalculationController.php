<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyUsage;
use App\Services\EnergyCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CalculationController extends Controller
{
    public function __construct(
        private readonly ContractPriceCalculator $priceCalculator,
        private readonly EnergyCalculator $energyCalculator,
    ) {}

    /**
     * Calculate the annual electricity cost for a contract.
     *
     * Accepts either:
     * - consumption (int): Total annual kWh consumption
     * - energy_usage (array): Detailed breakdown of energy usage
     */
    public function calculatePrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|string',
            'consumption' => 'required_without:energy_usage|integer|min:0',
            'energy_usage' => 'required_without:consumption|array',
            'energy_usage.total' => [Rule::requiredIf($request->has('energy_usage')), 'integer', 'min:0'],
            'energy_usage.basic_living' => 'sometimes|integer|min:0',
            'energy_usage.room_heating' => 'required_with:energy_usage.heating_electricity_use_by_month|integer|min:0',
            'energy_usage.bathroom_underfloor_heating' => 'sometimes|integer|min:0',
            'energy_usage.water' => 'sometimes|integer|min:0',
            'energy_usage.sauna' => 'sometimes|integer|min:0',
            'energy_usage.electricity_vehicle' => 'sometimes|integer|min:0',
            'energy_usage.cooling' => ['sometimes', 'numeric', 'min:0', function ($attribute, $value, $fail) {
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    $fail('The cooling consumption must be a finite number.');
                }
            }],
            'energy_usage.heating_electricity_use_by_month' => 'sometimes|array:0,1,2,3,4,5,6,7,8,9,10,11|size:12',
            'energy_usage.heating_electricity_use_by_month.*' => ['required', 'numeric', 'min:0', function ($attribute, $value, $fail) {
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    $fail('Each monthly heating weight must be a finite non-negative number.');
                }
            }],
            'spot_price_day' => 'sometimes|numeric',
            'spot_price_night' => 'sometimes|numeric',
        ]);

        // Canonical pricing reads only the published contract JSON. The feature-off
        // branch loads relational components through the legacy model helper below.
        $contract = ElectricityContract::find($validated['contract_id']);

        if (! $contract) {
            return response()->json([
                'error' => 'Contract not found',
            ], 404);
        }

        // Build energy usage object
        if (isset($validated['energy_usage'])) {
            $usage = $this->normalizeEnergyUsage($validated['energy_usage']);
        } else {
            $consumption = $validated['consumption'];
            $usage = new EnergyUsage(
                total: $consumption,
                basicLiving: $consumption,
            );
        }

        $canonicalPricing = app(CanonicalContractPricingService::class);
        if ($canonicalPricing->enabled()) {
            $evaluation = $canonicalPricing->evaluate($contract, $usage);

            $pricing = ContractPricingViewData::fromCanonicalOutcome($evaluation['outcome']);

            return response()->json([
                'data' => $pricing->toArray()
                    + ['pricing_integrity' => $evaluation['integrity']->toArray()],
            ]);
        }

        // Get the latest price components (prefer non-zero prices when duplicates exist)
        $priceComponents = $contract->getLatestPriceComponentsForCalculation();

        $contractData = [
            'contract_type' => $contract->contract_type,
            'pricing_model' => $contract->pricing_model,
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
            'data' => ContractPricingViewData::fromLegacyResult($result)->toArray(),
        ]);
    }

    private function normalizeEnergyUsage(array $data): EnergyUsage
    {
        $components = ['basic_living', 'room_heating', 'bathroom_underfloor_heating', 'water', 'sauna', 'electricity_vehicle', 'cooling'];
        // Only validated snake-case inputs can reach the DTO's alias-aware factory.
        $data = array_intersect_key($data, array_flip([...$components, 'total', 'heating_electricity_use_by_month']));
        $sum = array_sum(array_intersect_key($data, array_flip($components)));
        if ($sum > $data['total']) {
            throw ValidationException::withMessages([
                'energy_usage.total' => 'The consumption breakdown must not exceed energy_usage.total.',
            ]);
        }

        $data['basic_living'] = ($data['basic_living'] ?? 0) + ($data['total'] - $sum);
        if (isset($data['heating_electricity_use_by_month'])) {
            $weights = $data['heating_electricity_use_by_month'];
            $weightTotal = array_sum($weights);
            if (! is_finite((float) $weightTotal) || ($data['room_heating'] > 0 && $weightTotal <= 0)) {
                throw ValidationException::withMessages([
                    'energy_usage.heating_electricity_use_by_month' => 'Monthly heating weights must have a finite positive sum when room_heating is positive.',
                ]);
            }
            ksort($weights);
            $data['heating_electricity_use_by_month'] = array_map(
                fn ($weight) => $weightTotal > 0 ? ($weight / $weightTotal) * $data['room_heating'] : 0.0,
                $weights,
            );
        }

        return EnergyUsage::fromArray($data);
    }

    /**
     * Estimate annual electricity consumption based on building parameters.
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
