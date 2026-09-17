<?php

namespace App\Services\CanonicalPricing\Support;

use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\DTO\OfferComponentData;
use App\Services\CanonicalPricing\DTO\OfferTermData;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use Carbon\CarbonImmutable;

/** Describes admitted terms only. Billing and net benefit remain in the calculator. */
final class EnergyRuleOfferTerms
{
    /** @return list<OfferTermData> */
    public static function build(CanonicalContractData $data, EnergyRulePlan $plan, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        if (! $plan->normalAvailable) {
            return [];
        }
        $terms = [];
        foreach ($plan->spans as $spans) {
            foreach ($spans as $span) {
                $component = $span['component'];
                if ($component->withoutEnergyOffer() === $component) {
                    continue;
                }
                $rule = $component->energyRule;
                $terms[] = self::term($span['start'], $span['end']->min($windowEnd), $windowStart, $rule->ends->kind, new OfferComponentData(
                    $component->type, $component->unit, $component->amount, $component->normalAmount,
                    $rule->kind, $rule->discountValue, $rule->floorAmount,
                ));
            }
        }
        // Fees retain their own governing phase timing, never the energy guarantee timing.
        $segments = (new PhaseTimelineBuilder)->build($data->phases, $data->recurringSchedule, $windowStart);
        $feeSpans = [];
        foreach ($segments as $segment) {
            if (! $segment->isCovered() || $segment->start->gte($windowEnd)) {
                continue;
            }
            $billed = $data->phases[$segment->phaseIndex]->billedComponents();
            $feeCount = count(array_filter($billed, static fn ($component) => $component->type === ComponentType::MonthlyFee));
            foreach ($billed as $component) {
                if ($component->type->isPerKwhEnergy()) {
                    continue;
                }
                $normal = $component->normalAmount;
                if ($normal === null && $component->priceRole === PriceRole::Introductory) {
                    if ($component->type !== ComponentType::MonthlyFee) {
                        return [];
                    }
                    foreach ($segments as $later) {
                        if (! $later->isCovered() || $later->start->lt($segment->end)
                            || ! in_array($data->phases[$later->phaseIndex]->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation], true)) {
                            continue;
                        }
                        $fees = array_values(array_filter($data->phases[$later->phaseIndex]->billedComponents(), static fn ($fee) => $fee->type === ComponentType::MonthlyFee));
                        if (count($fees) !== 1 || $fees[0]->unit !== ComponentUnit::EurPerMonth) {
                            return [];
                        }
                        $normal = $fees[0]->normalAmount ?? $fees[0]->amount;
                        break;
                    }
                    if ($normal === null) {
                        return [];
                    }
                }
                if ($normal === null || $normal <= $component->amount) {
                    continue;
                }
                if ($component->type !== ComponentType::MonthlyFee || $component->unit !== ComponentUnit::EurPerMonth || $feeCount !== 1) {
                    return [];
                }
                $endKind = $data->phases[$segment->phaseIndex]->ends->kind;
                if (! in_array($endKind, [BoundaryKind::AfterMonths, BoundaryKind::Date, BoundaryKind::PeriodBoundary], true)) {
                    return [];
                }
                $key = spl_object_id($component);
                $feeSpans[$key] ??= ['start' => $segment->start, 'end' => $segment->start, 'component' => $component, 'normal' => $normal, 'end_kind' => $endKind];
                if (! $feeSpans[$key]['end']->equalTo($segment->start) || $feeSpans[$key]['normal'] !== $normal) {
                    return [];
                }
                $feeSpans[$key]['end'] = $segment->end->min($windowEnd);
            }
        }
        foreach ($feeSpans as $span) {
            $component = $span['component'];
            $terms[] = self::term($span['start'], $span['end'], $windowStart, $span['end_kind'], new OfferComponentData(
                $component->type, $component->unit, $component->amount, $span['normal'],
            ));
        }
        // Repeated identical guarantees must not repeat the public offer sentence.
        $unique = [];
        foreach ($terms as $term) {
            $unique[json_encode($term->toArray(), JSON_THROW_ON_ERROR)] = $term;
        }

        return array_values($unique);
    }

    private static function term(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $windowStart, BoundaryKind $endKind, OfferComponentData $component): OfferTermData
    {
        $a = $b = null;
        for ($month = 0; $month <= 12; $month++) {
            $anniversary = $windowStart->addMonthsNoOverflow($month);
            if ($start->equalTo($anniversary)) {
                $a = $month;
            }
            if ($end->equalTo($anniversary)) {
                $b = $month;
            }
        }
        $relative = $endKind === BoundaryKind::AfterMonths && $a !== null && $b !== null && $b > $a;

        return new OfferTermData(
            $relative ? BoundaryKind::AfterMonths : BoundaryKind::Date,
            $start, $end->subDay(), $relative ? $b - $a : null,
            $relative ? $a : null, $relative ? $b : null, $start->equalTo($windowStart), [$component],
        );
    }
}
