<?php

namespace App\Services\CanonicalPricing;

use App\Services\CanonicalPricing\DTO\CanonicalComponent;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\PhaseBoundary;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use Carbon\CarbonImmutable;

/** A narrow safety check, not an intent or marketing classifier. */
class PromotionTermsAssessment
{
    public const INSUFFICIENT = 'insufficient_promotion_terms';

    public function isIncomplete(CanonicalContractData $data, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($data->phases as $phase) {
            if ($phase->package !== null || ! $this->intersects($phase, $start, $end, $data)) {
                continue;
            }
            $scoped = array_filter($phase->billedComponents(), fn ($component) => $this->explicitPromotion($component));
            foreach ($phase->billedComponents() as $component) {
                $sourceCampaign = ($component->type->isPerKwhEnergy() || $component->type === ComponentType::SpotMargin)
                    && in_array($component->amount, $data->sourceCampaignEnergyRates, false)
                    && ! $this->hasExpiredCampaign($component, $phase, $data, $start);
                $promotional = $sourceCampaign || $this->explicitPromotion($component)
                    || ($phase->phaseKind === PhaseKind::Introductory && $scoped === []);
                if (! $promotional) {
                    continue;
                }
                $until = $this->boundary($phase->ends, $start, $data, true);
                if ($until === null || $until->lessThanOrEqualTo($start)) {
                    return true;
                }
                if ($component->normalAmount !== null) {
                    continue;
                }
                if (! $this->hasContinuation($component, $data, $start, $until)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasExpiredCampaign(CanonicalComponent $component, PricingPhase $current, CanonicalContractData $data, CarbonImmutable $start): bool
    {
        // Expiry applies to every mechanism. Require a dated normal continuation,
        // not just an old matching number. A new introduction must still be checked,
        // including an omitted energy/margin promotion beside an explicit fee offer.
        if (! in_array($current->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation], true)
            || $current->starts->kind !== BoundaryKind::Date) {
            return false;
        }
        $normalStart = $this->boundary($current->starts, $start, $data);
        if ($normalStart === null || $normalStart->greaterThan($start)) {
            return false;
        }
        foreach ($data->phases as $phase) {
            if ($phase->ends->kind !== BoundaryKind::Date) {
                continue;
            }
            $until = $this->boundary($phase->ends, $start, $data, true);
            if ($until === null || ! $until->equalTo($normalStart)) {
                continue;
            }
            foreach ($phase->billedComponents() as $expired) {
                if ($expired->type === $component->type && $expired->amount === $component->amount
                    && ($phase->phaseKind === PhaseKind::Introductory || $this->explicitPromotion($expired))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function explicitPromotion(CanonicalComponent $component): bool
    {
        return $component->priceRole === PriceRole::Introductory
            || ($component->normalAmount !== null && $component->amount < $component->normalAmount);
    }

    private function hasContinuation(CanonicalComponent $component, CanonicalContractData $data, CarbonImmutable $start, CarbonImmutable $until): bool
    {
        foreach ($data->phases as $later) {
            if (! in_array($later->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation, PhaseKind::Future, PhaseKind::Introductory], true)) {
                continue;
            }
            $from = $this->boundary($later->starts, $start, $data);
            $end = $this->boundary($later->ends, $start, $data, true);
            if ($from === null || ! $from->equalTo($until)
                || ($later->ends->kind !== BoundaryKind::None && ($end === null || $end->lessThanOrEqualTo($from)))) {
                continue;
            }
            foreach ($later->billedComponents() as $normal) {
                if ($normal->isUncostableWhenBilled()) {
                    continue;
                }
                if (($normal->type === $component->type && $normal->unit === $component->unit)
                    || ($component->type->isPerKwhEnergy() && $normal->type === ComponentType::SpotMargin)) {
                    if ($normal->normalAmount !== null
                        || ($later->phaseKind !== PhaseKind::Introductory && ! $this->explicitPromotion($normal))) {
                        return true;
                    }
                    if ($end !== null && $end->greaterThan($until) && $this->hasContinuation($normal, $data, $start, $end)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function intersects(PricingPhase $phase, CarbonImmutable $start, CarbonImmutable $end, CanonicalContractData $data): bool
    {
        $from = $this->boundary($phase->starts, $start, $data);
        $until = $this->boundary($phase->ends, $start, $data, true);

        return ($from === null || $from->lessThan($end)) && ($until === null || $until->greaterThan($start));
    }

    private function boundary(PhaseBoundary $boundary, CarbonImmutable $start, CanonicalContractData $data, bool $end = false): ?CarbonImmutable
    {
        if ($boundary->kind === BoundaryKind::AfterMonths) {
            return $boundary->afterMonths() !== null ? $start->addMonthsNoOverflow($boundary->afterMonths()) : null;
        }
        if ($boundary->kind === BoundaryKind::ContractStart || (! $end && $boundary->kind === BoundaryKind::None)) {
            return $start;
        }
        $date = $boundary->kind === BoundaryKind::Date ? $boundary->value
            : ($boundary->kind === BoundaryKind::PeriodBoundary ? ($end ? $data->recurringSchedule->currentPeriodEnd : $data->recurringSchedule->currentPeriodStart) : null);
        if ($date === null || $date === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($date, 'Europe/Helsinki')->startOfDay();

            return $end ? $date->addDay() : $date;
        } catch (\Throwable) {
            return null;
        }
    }
}
