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

/** Narrow first-release eligibility for one ordinary adjustable open-ended General tariff. */
class SupplierAdjustedEligibility
{
    public function candidate(string $contractId, CanonicalContractData $data, ContractContext $context): ?SupplierAdjustedCandidate
    {
        if (ContractType::fromSource($context->contractType) !== ContractType::OpenEnded
            || PricingModel::fromSource($context->pricingModel) !== PricingModel::FixedPrice
            || MeteringType::fromSource($context->metering) !== MeteringType::General
            || $data->calculationStatus !== CalculationStatus::Exact
            || $data->structuredPricingStatus !== 'complete'
            || $data->recurringSchedule->present
            || $data->consumptionEffect->present
            || count($data->phases) !== 1) {
            return null;
        }

        $phase = $data->phases[0];
        if ($phase->phaseKind !== PhaseKind::CurrentStructured
            || ! in_array($phase->starts->kind, [BoundaryKind::ContractStart, BoundaryKind::None], true)
            || $phase->ends->kind !== BoundaryKind::None
            || $phase->package !== null) {
            return null;
        }

        $general = null;
        $monthlyFees = [];

        foreach ($phase->components as $component) {
            if (! $component->isBilled()
                || $component->priceRole !== PriceRole::Current
                || $component->normalAmount !== null) {
                return null;
            }

            if ($component->type === ComponentType::EnergyGeneral
                && $component->unit === ComponentUnit::CentsPerKwh
                && $general === null) {
                $general = $component->amount;
                continue;
            }

            if ($component->type === ComponentType::MonthlyFee
                && $component->unit === ComponentUnit::EurPerMonth) {
                $monthlyFees[] = (float) $component->amount;
                continue;
            }

            return null;
        }

        $monthlyFee = $monthlyFees === [] ? 0.0 : max($monthlyFees);
        foreach ($monthlyFees as $fee) {
            if (abs($fee - $monthlyFee) > 0.0001) {
                return null;
            }
        }

        if ($general === null || ! is_finite($general) || $general < 0 || ! is_finite($monthlyFee) || $monthlyFee < 0) {
            return null;
        }

        return new SupplierAdjustedCandidate($contractId, $general, $monthlyFee);
    }
}
