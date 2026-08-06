<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted;

use App\Enums\ContractType;
use App\Enums\MeteringType;
use App\Enums\PricingModel;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;

/** Eligibility for one ordinary adjustable open-ended tariff. */
class SupplierAdjustedEligibility
{
    public function candidate(string $contractId, CanonicalContractData $data, ContractContext $context): ?SupplierAdjustedCandidate
    {
        $metering = MeteringType::fromSource($context->metering);
        if (ContractType::fromSource($context->contractType) !== ContractType::OpenEnded
            || PricingModel::fromSource($context->pricingModel) !== PricingModel::FixedPrice
            || ! in_array($metering, [MeteringType::General, MeteringType::Time, MeteringType::Season], true)
            || $data->calculationStatus !== CalculationStatus::Exact
            || $data->structuredPricingStatus !== 'complete'
            || $data->recurringSchedule->present
            || $data->consumptionEffect->present
            || count($data->phases) !== 1) {
            return null;
        }

        $phase = $data->phases[0];
        if ($phase->phaseKind !== PhaseKind::CurrentStructured
            || ! in_array($phase->starts->kind, [
                BoundaryKind::ContractStart,
                BoundaryKind::None,
                BoundaryKind::Unknown,
                BoundaryKind::Date,
            ], true)
            || $phase->ends->kind !== BoundaryKind::None
            || $phase->package !== null) {
            return null;
        }

        $expectedEnergyTypes = match ($metering) {
            MeteringType::General => [ComponentType::EnergyGeneral],
            MeteringType::Time => [ComponentType::EnergyDay, ComponentType::EnergyNight],
            MeteringType::Season => [ComponentType::EnergySeasonalWinter, ComponentType::EnergySeasonalOther],
        };
        $energyAmounts = [];
        $monthlyFees = [];

        foreach ($phase->components as $component) {
            if (! $component->isBilled()
                || $component->priceRole !== PriceRole::Current
                || $component->normalAmount !== null) {
                return null;
            }

            if (in_array($component->type, $expectedEnergyTypes, true)
                && $component->unit === ComponentUnit::CentsPerKwh) {
                $energyAmounts[$component->type->value][] = (float) $component->amount;

                continue;
            }

            if ($component->type === ComponentType::MonthlyFee
                && $component->unit === ComponentUnit::EurPerMonth) {
                $monthlyFees[] = (float) $component->amount;

                continue;
            }

            return null;
        }

        $energyRates = [];
        foreach ($expectedEnergyTypes as $type) {
            $amounts = $energyAmounts[$type->value] ?? [];
            $rate = $this->identicalNonNegativeValue($amounts);
            if ($rate === null) {
                return null;
            }
            $energyRates[$type->value] = $rate;
        }

        $monthlyFee = 0.0;
        foreach ($monthlyFees as $fee) {
            if (! is_finite($fee) || $fee < 0) {
                return null;
            }
            $monthlyFee = max($monthlyFee, $fee);
        }

        $representativeRate = match ($metering) {
            MeteringType::General => $energyRates[ComponentType::EnergyGeneral->value],
            MeteringType::Time => (
                $energyRates[ComponentType::EnergyDay->value] * 15
                + $energyRates[ComponentType::EnergyNight->value] * 9
            ) / 24,
            MeteringType::Season => (
                $energyRates[ComponentType::EnergySeasonalWinter->value] * 5
                + $energyRates[ComponentType::EnergySeasonalOther->value] * 7
            ) / 12,
        };

        return new SupplierAdjustedCandidate($contractId, $representativeRate, $monthlyFee);
    }

    /** @param list<float> $values */
    private function identicalNonNegativeValue(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $value = max($values);
        if (! is_finite($value) || $value < 0) {
            return null;
        }

        foreach ($values as $candidate) {
            if (! is_finite($candidate) || abs($candidate - $value) > 0.0001) {
                return null;
            }
        }

        return $value;
    }
}
