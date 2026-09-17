<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\DTO\CanonicalComponent;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\DTO\PhaseBoundary;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Energy evidence only. The zero fee is a placeholder, never a billed fee. */
final class CurrentNormalCandidateExtractor
{
    /** The caller must first authorize source rules. Parsing alone is not proof. */
    public function candidate(string $contractId, CanonicalContractData $data, ContractContext $context, CarbonInterface $comparisonDate): ?SupplierAdjustedCandidate
    {
        $metering = MeteringType::fromSource($context->metering);
        if ($metering === null) {
            return null;
        }
        $data = $data->withVatBasis($context->includesVat(), (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255));
        foreach ($data->phases as $phase) {
            if ($phase->package !== null || $phase->hasUncostableComponent()) {
                return null;
            }
            foreach ($phase->billedComponents() as $component) {
                if ($component->type === ComponentType::SpotMargin) {
                    return null;
                }
            }
        }
        $start = CarbonImmutable::parse($comparisonDate->toDateString(), 'Europe/Helsinki')->startOfDay();
        $plan = EnergyRulePlan::build($data, $metering, $start, $start->addMonthsNoOverflow(12), new PhaseTimelineBuilder, null);
        if ($plan === null || $plan->fixedOnly !== [] || ! $plan->normalAvailable
            || ($plan->rates($start)['normal'] ?? null) !== $plan->baseline
            || ! ($plan->rates($start)['normal_reference_current'] ?? false)) {
            return null;
        }
        $components = [];
        foreach ($plan->baseline as $key => $amount) {
            $proved = false;
            foreach ($plan->normalSpans[$key] ?? [] as $span) {
                // A future normal guarantee is not an ordinary monthly-reference donor.
                if ($span['kind'] === EnergyPriceRuleKind::FixedPrice && $span['end']->gt($start)) {
                    return null;
                }
                if ($span['start']->lte($start) && $span['end']->gt($start)) {
                    if ($span['kind'] !== EnergyPriceRuleKind::AdjustableTariff) {
                        return null;
                    }
                    $proved = true;
                }
            }
            if (! $proved) {
                return null;
            }
            $components[] = new CanonicalComponent(ComponentType::from($key), $amount, null, ComponentUnit::CentsPerKwh, PriceRole::Current);
        }
        // Reuse family guards, complete bucket checks and representative weights.
        $ordinary = new PricingPhase('', PhaseKind::CurrentStructured, new PhaseBoundary(BoundaryKind::ContractStart, null), new PhaseBoundary(BoundaryKind::None, null), $components);
        $candidate = (new SupplierAdjustedEligibility(currentNormalEvidence: true))->candidate($contractId, $data->withComparisonEvidence(phases: [$ordinary]), $context, currentBaseHybrid: true);
        if ($candidate === null) {
            return null;
        }

        return new SupplierAdjustedCandidate($contractId, $candidate->currentEnergyPriceCentsPerKwh, 0, $candidate->energyRates, $candidate->metering, $candidate->includesVat, $candidate->pricingMechanism, normalTariffEvidence: true);
    }
}
