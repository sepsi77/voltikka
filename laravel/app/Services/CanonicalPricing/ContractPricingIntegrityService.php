<?php

namespace App\Services\CanonicalPricing;

use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\IntegrityReasonFamily;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use Carbon\CarbonImmutable;

/**
 * Derives the deterministic public pricing-integrity label from validated canonical facts.
 *
 * Gate: only `misleading_first_12_months === 'detected'` can produce a label. All copy is
 * generated from typed fields and reason codes; the LLM `summary` string is never surfaced.
 *
 * See app/Services/CanonicalPricing/AGENTS.md for the label families and copy rules.
 */
class ContractPricingIntegrityService
{
    private const PROMO_CODES = [
        'structured_matches_intro_only',
        'future_price_omitted',
        'promotion_metadata_missing',
        'future_price_unknown',
    ];

    private const CONFLICT_CODES = [
        'component_mismatch',
        'insufficient_evidence',
        'pricing_model_mismatch',
        'contract_type_mismatch',
        'metering_mismatch',
        'other',
    ];

    public function assess(
        CanonicalContractData $data,
        CanonicalPricingOutcome $outcome,
        ContractContext $context,
    ): ContractPricingIntegrity {
        if ($data->misleadingState->value !== 'detected') {
            return ContractPricingIntegrity::none();
        }

        $promoCodes = array_values(array_intersect($data->issueCodes, self::PROMO_CODES));
        $conflictCodes = array_values(array_intersect($data->issueCodes, self::CONFLICT_CODES));

        // A legitimate recurring market product (monthly/quarterly/seasonal reset) is not deceptive
        // even with a small first-period intro. Its future periods reset with the market like Spot,
        // and the "Arvio" estimate marker already communicates that. Suppress the promo label unless
        // there is an actual structured data conflict.
        if ($data->recurringSchedule->isActiveReset() && $conflictCodes === []) {
            return ContractPricingIntegrity::none();
        }

        // A fixed-term continuation is legitimate; the "6 kk sopimus" term pill already explains it.
        if ($outcome->comparability === ContractComparability::TermPriceOnly
            && $conflictCodes === []
            && $this->onlyContinuationCodes($promoCodes)) {
            return ContractPricingIntegrity::none();
        }

        if ($promoCodes !== []) {
            return $this->promoLabel($data, $outcome, $promoCodes);
        }

        // Detected without a promo signal: a data-integrity problem, shown only on the detail page.
        return $this->dataConflictLabel($conflictCodes ?: ['other']);
    }

    /**
     * @param list<string> $promoCodes
     */
    private function onlyContinuationCodes(array $promoCodes): bool
    {
        return array_diff($promoCodes, ['future_price_omitted', 'future_price_unknown']) === [];
    }

    /**
     * @param list<string> $promoCodes
     */
    private function promoLabel(CanonicalContractData $data, CanonicalPricingOutcome $outcome, array $promoCodes): ContractPricingIntegrity
    {
        $promoRate = $outcome->generalKwhPrice;
        $normalPhase = $this->firstNormalPhase($data->phases);
        $normalRate = $normalPhase !== null ? $this->generalRate($normalPhase) : null;
        $changeDate = $this->changeDate($data->phases);
        $laterPriceKnown = $outcome->isListed() && $normalRate !== null;

        $impact = null;
        if ($outcome->totalCost !== null && $outcome->structuredOnlyTotal !== null) {
            $impact = round($outcome->totalCost - $outcome->structuredOnlyTotal);
            if ($impact <= 0) {
                $impact = null;
            }
        }

        // Materiality gate for LISTED contracts: only warn when the structured price materially
        // understates the first year (≥ 20 % and ≥ 30 €). A modest step-up — e.g. a 6-month fixed
        // that continues at a similar spot price — is an ordinary product structure, not deception.
        // Excluded contracts (later price unknown) keep their detail-only notice regardless.
        if ($outcome->isListed()) {
            $materiallyUnderstated = $impact !== null
                && $outcome->totalCost !== null
                && $outcome->structuredOnlyTotal !== null
                && $impact >= 30.0
                && $outcome->structuredOnlyTotal <= 0.80 * $outcome->totalCost;

            if (! $materiallyUnderstated) {
                return ContractPricingIntegrity::none();
            }
        }

        $facts = [];
        if ($promoRate !== null && $changeDate !== null) {
            $facts[] = 'Tarjoushinta '.$this->cents($promoRate).' snt/kWh on voimassa '.$this->date($changeDate).' asti.';
        } elseif ($promoRate !== null) {
            $facts[] = 'Tarjoushinta on '.$this->cents($promoRate).' snt/kWh.';
        }

        if ($laterPriceKnown) {
            $facts[] = 'Sen jälkeen energian hinta on '.$this->cents($normalRate).' snt/kWh.';
        } else {
            $facts[] = 'Tarjousjakson jälkeistä hintaa ei ole ilmoitettu rakenteisissa hintatiedoissa.';
        }

        if ($impact !== null) {
            $facts[] = 'Ensimmäisen vuoden kustannus on noin '.number_format($impact, 0, ',', ' ').' € korkeampi kuin pelkkä tarjoushinta antaisi ymmärtää.';
        }

        $cardLabel = null;
        if ($outcome->isListed()) {
            $cardLabel = $changeDate !== null
                ? 'Hinta nousee '.$this->date($changeDate)
                : 'Tarjoushinta ei kata koko vuotta';
        }

        return new ContractPricingIntegrity(
            detected: true,
            reasonFamily: IntegrityReasonFamily::Promo,
            issueCodes: $promoCodes,
            cardLabel: $cardLabel,
            detailHeading: 'Tarjoushinta ei kata koko vuotta',
            detailFacts: $facts,
            changeDate: $changeDate,
            firstYearImpactEur: $impact,
        );
    }

    /**
     * @param list<string> $conflictCodes
     */
    private function dataConflictLabel(array $conflictCodes): ContractPricingIntegrity
    {
        return new ContractPricingIntegrity(
            detected: true,
            reasonFamily: IntegrityReasonFamily::DataConflict,
            issueCodes: $conflictCodes,
            cardLabel: null,
            detailHeading: 'Sopimuksen hintatiedoissa on ristiriitaisuuksia',
            detailFacts: ['Rakenteisissa hintatiedoissa on ristiriita tai puutteita. Tarkista hinta suoraan myyjältä ennen sopimuksen tekemistä.'],
        );
    }

    /**
     * @param list<PricingPhase> $phases
     */
    private function firstNormalPhase(array $phases): ?PricingPhase
    {
        foreach ($phases as $phase) {
            if (in_array($phase->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation, PhaseKind::Future], true)
                && $phase->hasKnownPricing()) {
                return $phase;
            }
        }

        return null;
    }

    private function generalRate(PricingPhase $phase): ?float
    {
        foreach ($phase->billedComponents() as $component) {
            if ($component->type === ComponentType::EnergyGeneral) {
                return $component->amount;
            }
        }

        return null;
    }

    /**
     * The date the promotional price ends / the later price begins, as an ISO string.
     *
     * @param list<PricingPhase> $phases
     */
    private function changeDate(array $phases): ?string
    {
        $normal = $this->firstNormalPhase($phases);
        if ($normal !== null && $normal->starts->kind === BoundaryKind::Date && $normal->starts->value !== null) {
            return $normal->starts->value;
        }

        foreach ($phases as $phase) {
            if ($phase->ends->kind === BoundaryKind::Date && $phase->ends->value !== null) {
                return $phase->ends->value;
            }
        }

        return null;
    }

    private function date(string $iso): string
    {
        try {
            return CarbonImmutable::parse($iso, 'Europe/Helsinki')->format('j.n.Y');
        } catch (\Throwable) {
            return $iso;
        }
    }

    private function cents(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
