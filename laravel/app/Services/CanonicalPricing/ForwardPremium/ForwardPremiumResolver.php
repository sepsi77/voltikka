<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

/** Pure selection over prepared evidence. No model, curve, persistence, or cache access. */
final class ForwardPremiumResolver
{
    /**
     * The reference delivery matches each observation's retail period, NOT a target forecast
     * month. Historical spreads transfer to future months; this class does not price them.
     *
     * @param  iterable<PremiumObservation>  $observations
     */
    public function resolve(PremiumTarget $target, iterable $observations): ?PremiumEstimate
    {
        $byLineage = [];
        foreach ($observations as $observation) {
            if (! $target->compatibility->matches($observation->compatibility)
                || $observation->observedAt->gt($target->asOfDate)
                || $observation->pricingDate?->gt($target->asOfDate)
                || ! $observation->referenceTradeDate->lt($target->asOfDate)) {
                continue;
            }
            $byLineage[$observation->lineageId][] = $observation;
        }

        $flags = [];
        $eligible = [];
        foreach ($byLineage as $rows) {
            $latest = $this->latestConsistent($rows);
            if ($latest === []) {
                $flags[] = 'conflicting_latest_lineage_evidence';
            }
            array_push($eligible, ...$latest);
        }

        $own = array_values(array_filter($eligible, fn (PremiumObservation $row) => $row->lineageId === $target->lineageId
            && $row->companyName === $target->companyName));
        if ($own !== []) {
            return $this->estimate(PremiumSource::OwnLineage, [$own], $flags);
        }

        // One weight per exact retail energy variant within a company. Fees and IDs are absent.
        $byVariant = [];
        foreach ($eligible as $row) {
            $key = json_encode([$row->companyName, $row->energyOfferSignature], JSON_THROW_ON_ERROR);
            $byVariant[$key][] = $row;
        }
        $variants = [];
        foreach ($byVariant as $rows) {
            if (count(array_unique(array_map(fn (PremiumObservation $row) => $row->lineageId, $rows))) > 1) {
                $flags[] = 'equivalent_energy_offers_deduplicated';
            }
            $latest = $this->latestConsistent($rows);
            if ($latest === []) {
                $flags[] = 'conflicting_latest_variant_evidence';
            } else {
                $variants[] = $latest;
            }
        }

        $company = array_values(array_filter($variants, fn (array $rows) => $rows[0]->companyName === $target->companyName));
        if ($company !== []) {
            return $this->estimate(PremiumSource::SameCompany, $company, $flags);
        }

        return $variants === [] ? null : $this->estimate(PremiumSource::Market, $variants, $flags);
    }

    /**
     * Reject the whole newest date if evidence conflicts. Do not resurrect an older price.
     * Keep distinct source provenance for equal evidence, without giving it extra weight.
     *
     * @param  non-empty-list<PremiumObservation>  $rows
     * @return list<PremiumObservation>
     */
    private function latestConsistent(array $rows): array
    {
        $latest = max(array_map(fn (PremiumObservation $row) => $row->observedAt->toDateString(), $rows));
        $rows = array_values(array_filter($rows, fn (PremiumObservation $row) => $row->observedAt->toDateString() === $latest));
        if (count(array_unique(array_map(fn (PremiumObservation $row) => $row->evidenceKey(), $rows))) !== 1) {
            return [];
        }

        $unique = [];
        foreach ($rows as $row) {
            $key = json_encode([$row->lineageId, $row->evidenceKey(), $row->provenance], JSON_THROW_ON_ERROR);
            $unique[$key] = $row;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /** @param non-empty-list<non-empty-list<PremiumObservation>> $variants */
    private function estimate(PremiumSource $source, array $variants, array $flags): PremiumEstimate
    {
        $companies = [];
        $observations = [];
        foreach ($variants as $rows) {
            $row = $rows[0];
            if ($row->referencePeriodProxy) {
                $flags[] = $row->compatibility->family->isReset()
                    ? 'reset_reference_period_proxy' : 'observed_pricing_date_reference_period_proxy';
            }
            foreach ($row->premiumsByBucket as $bucket => $premium) {
                $companies[$row->companyName][$bucket][] = $premium;
            }
            array_push($observations, ...$rows);
        }

        $companyMedians = [];
        foreach ($companies as $buckets) {
            foreach ($buckets as $bucket => $premiums) {
                $companyMedians[$bucket][] = $this->median($premiums);
            }
        }
        $premiums = [];
        foreach ($companyMedians as $bucket => $medians) {
            $premiums[$bucket] = $this->median($medians);
        }
        ksort($premiums, SORT_STRING);
        usort($observations, fn (PremiumObservation $a, PremiumObservation $b) => strcmp(
            json_encode([$a->companyName, $a->lineageId, $a->observedAt->toDateString(), $a->evidenceKey(), $a->provenance], JSON_THROW_ON_ERROR),
            json_encode([$b->companyName, $b->lineageId, $b->observedAt->toDateString(), $b->evidenceKey(), $b->provenance], JSON_THROW_ON_ERROR),
        ));
        if (count($variants) === 1) {
            $flags[] = 'single_independent_energy_variant';
        }
        if (count($companies) === 1) {
            $flags[] = 'single_source_company';
        }
        if ($source !== PremiumSource::OwnLineage) {
            $flags[] = 'transferred_comparable_premium';
        }
        $flags = array_values(array_unique($flags));
        sort($flags, SORT_STRING);

        return new PremiumEstimate($source, $premiums, $observations, count($variants), $flags);
    }

    /** @param non-empty-list<float> $values */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        // Divide first so two finite large values cannot overflow during the mean.
        return count($values) % 2 === 1 ? $values[$middle] : $values[$middle - 1] / 2 + $values[$middle] / 2;
    }
}
