<?php

namespace App\Services\ContractInterpretation;

use App\Models\ActiveContract;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractListCacheService;
use Illuminate\Support\Facades\DB;

class ContractInterpretationPublisher
{
    public function __construct(private readonly CanonicalPriceComponentWriter $priceComponentWriter) {}

    public function publish(ContractInterpretation $interpretation): bool
    {
        $published = DB::transaction(function () use ($interpretation): bool {
            // Lock order is contract, pointed observation, pointed snapshot, then
            // interpretation. Every path uses the pointer as the only currentness rule.
            /** @var ElectricityContract $contract */
            $contract = ElectricityContract::query()
                ->lockForUpdate()
                ->findOrFail($interpretation->contract_id);
            $currentObservation = $contract->current_source_observation_id === null
                ? null
                : ContractSourceObservation::query()
                    ->lockForUpdate()
                    ->find($contract->current_source_observation_id);
            $currentSnapshot = $currentObservation === null
                ? null
                : ContractSourceSnapshot::query()
                    ->lockForUpdate()
                    ->find($currentObservation->source_snapshot_id);
            /** @var ContractInterpretation $lockedInterpretation */
            $lockedInterpretation = ContractInterpretation::query()
                ->lockForUpdate()
                ->findOrFail($interpretation->id);

            $isCurrent = $currentObservation !== null
                && $currentSnapshot !== null
                && $currentObservation->contract_id === $contract->id
                && $currentSnapshot->contract_id === $contract->id
                && $lockedInterpretation->contract_id === $contract->id
                && $currentSnapshot->id === $lockedInterpretation->source_snapshot_id;

            if (! $isCurrent) {
                $lockedInterpretation->update([
                    'status' => ContractInterpretation::STATUS_SUPERSEDED,
                    'completed_at' => now(),
                    'error' => 'The interpretation does not match the pointed source observation.',
                ]);

                return false;
            }

            $output = $lockedInterpretation->output ?? [];
            $updates = array_merge($this->canonicalClassification($output), [
                'canonical_pricing' => $output['pricing'] ?? null,
                'canonical_source_consistency' => $output['source_consistency'] ?? null,
                'canonical_calculation' => $output['calculation'] ?? null,
            ]);
            $publishedFields = array_keys($updates);
            $updates['published_interpretation_id'] = $lockedInterpretation->id;
            $contract->fill($updates);
            $contract->save();

            $canPublishPricing = $this->canPublishSourcePricing($output);
            if ($canPublishPricing) {
                $this->priceComponentWriter->write(
                    [$currentSnapshot->source_payload],
                    $currentObservation->first_observed_at->toDateString(),
                    [$contract->id],
                );
            }

            // This only detects that the episode came from the freshest import run.
            // It does not select source currentness; the contract pointer does that.
            $freshestImportObservation = ContractSourceObservation::max('last_observed_at');
            if ($canPublishPricing
                && $currentObservation->last_observed_at->toDateTimeString() === $freshestImportObservation) {
                ActiveContract::firstOrCreate(['id' => $contract->id]);
            }

            $lockedInterpretation->update([
                'status' => ContractInterpretation::STATUS_PUBLISHED,
                'published_fields' => $publishedFields,
                'relational_pricing_published' => $canPublishPricing,
                'published_at' => now(),
                'completed_at' => now(),
                'error' => null,
            ]);

            return true;
        });

        if ($published) {
            app(ContractListCacheService::class)->bumpVersion();
        }

        return $published;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function canonicalClassification(array $output): array
    {
        $classification = $output['classification'] ?? [];
        $consistency = $output['source_consistency'] ?? [];
        $confidence = $output['confidence']['classification'] ?? 'low';
        $updates = [];

        $pricingModel = $classification['primary_pricing_model'] ?? 'Unknown';
        if ($this->canPublishClassification(
            $pricingModel,
            $consistency['recommended_pricing_model'] ?? 'Unknown',
            $consistency['pricing_model_status'] ?? 'uncertain',
            $confidence,
            ['Spot', 'FixedPrice', 'Hybrid'],
        )) {
            $updates['pricing_model'] = $pricingModel;
        }

        $termType = $classification['term_type'] ?? 'Unknown';
        if ($this->canPublishClassification(
            $termType,
            $consistency['recommended_contract_type'] ?? 'Unknown',
            $consistency['contract_type_status'] ?? 'uncertain',
            $confidence,
            ['OpenEnded', 'FixedTerm'],
        )) {
            $updates['contract_type'] = $termType;
        }

        $metering = $classification['metering'] ?? 'Unknown';
        if ($this->canPublishClassification(
            $metering,
            $consistency['recommended_metering'] ?? 'Unknown',
            $consistency['metering_status'] ?? 'uncertain',
            $confidence,
            ['General', 'Time', 'Season'],
        )) {
            $updates['metering'] = $metering;
        }

        $duration = $classification['fixed_duration_months'] ?? null;
        if (isset($updates['contract_type'])) {
            if ($termType === 'FixedTerm' && is_int($duration)) {
                $updates['fixed_time_range'] = $this->fixedTimeRange($duration);
            } elseif ($termType === 'OpenEnded') {
                $updates['fixed_time_range'] = null;
            }
        }

        return $updates;
    }

    /**
     * Decide whether this interpretation lets the source price components be published.
     *
     * The question is deliberately narrow: **can the structured components still be trusted
     * as the contract's current disclosed price?** It is not "can Voltikka compute a
     * 12-month total from them", and it is not "did the model agree with every source
     * field". The structured API data is the baseline and is right in the large majority of
     * cases; interpretation exists to catch the minority where the description or another
     * field gives a concrete reason to doubt it. So the gate blocks on a *named reason*,
     * not on the absence of a clean bill of health.
     *
     * This replaced an earlier rule that refused publication whenever `calculation.status`
     * was `incomplete`/`unsupported` or `structured_pricing_status` was `incomplete`. Those
     * describe derivability and completeness, not trustworthiness, and conflating them was
     * severe: a Hybrid's consumption effect is *always* unquantifiable, so on 2026-07-24 the
     * gate closed permanently on every Hybrid contract, froze 49 contracts' card prices at
     * that day, and erased the `hybrid` segment from `/sahkosopimus/tilastot`. Later review
     * found the remaining blocked Hybrids were held by `pricing_model_mismatch` corrections
     * Voltikka had already accepted and published, and by `insufficient_evidence` meaning
     * only that the seller published no prose to check against. None of it said the prices
     * were wrong. See `tasks/hybrid-relational-pricing-gate/decisions.md`.
     *
     * Public because `contracts:republish-gated-pricing` re-runs this exact decision over
     * already-stored output when the rule is relaxed. `relational_pricing_published` is
     * written once at publication time and read by every later import, so a relaxation
     * reaches an already-published contract only by re-asking this question. A command
     * that restated the rule for itself would drift from the one that gates ingestion,
     * which is the class of bug it exists to clean up.
     *
     * @param  array<string, mixed>  $output
     */
    public function canPublishSourcePricing(array $output): bool
    {
        $consistency = $output['source_consistency'] ?? [];

        // A hidden first-12-month increase is the deception the whole pipeline exists to
        // catch. Never publish components that would present a promo price as the price.
        if (($consistency['misleading_first_12_months'] ?? null) === 'detected') {
            return false;
        }

        // The source contradicting itself leaves no reading of it that can be trusted,
        // whichever component the conflict is in.
        if (($consistency['structured_pricing_status'] ?? null) === 'conflicting') {
            return false;
        }

        $issueCodes = $consistency['issue_codes'] ?? [];

        if (! is_array($issueCodes)) {
            return false;
        }

        foreach ($issueCodes as $code) {
            if (! $this->issueCodeLeavesComponentsTrustworthy($code, $output)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does one issue code leave the structured components usable as the current price?
     *
     * Unknown codes return false. The schema's code list grows, and a code nobody has
     * classified here must not be silently treated as harmless.
     *
     * @param  array<string, mixed>  $output
     */
    private function issueCodeLeavesComponentsTrustworthy(string $code, array $output): bool
    {
        return match ($code) {
            // The components are fine; these describe agreement, an expected estimate, or
            // something outside the base price.
            //   structured_matches_description    — prose and structured data agree
            //   insufficient_evidence             — the seller published no prose to check
            //                                       against; that is thin documentation, not
            //                                       a defect in the numbers
            //   unsupported_consumption_effect    — a joustosähkö product's ± adjustment is
            //                                       never published; the base rate still is
            //   recurring_reset_requires_estimate — the next period's market price cannot be
            //                                       known yet (validator v4 already treats
            //                                       this as expected, not unsafe)
            //   future_price_unknown              — same shape: unknowable, not withheld
            //   optional_fixing_not_in_base_price — applies only if the customer opts in
            'structured_matches_description',
            'insufficient_evidence',
            'unsupported_consumption_effect',
            'recurring_reset_requires_estimate',
            'future_price_unknown',
            'optional_fixing_not_in_base_price' => true,

            // Classification corrections. Which product this is does not change what the
            // seller charges, and a wrong component is reported by component_mismatch.
            'contract_type_mismatch',
            'metering_mismatch' => true,

            // Except this one, because pricing_model decides how a component is *read*: on a
            // Spot contract a 0.4 c/kWh General is the margin, on a FixedPrice contract it is
            // the whole energy price. If the correction publishes, the calculator reads the
            // components correctly and this is benign. If it does not publish (a mismatch
            // below high confidence), the contract keeps a model the interpretation believes
            // is wrong and the same rows would be priced as something they are not.
            'pricing_model_mismatch' => $this->pricingModelCorrectionPublishes($output),

            // Everything left names a concrete reason the components misstate the price:
            // component_mismatch, structured_matches_intro_only, promotion_metadata_missing,
            // future_price_omitted, other — plus any code added to the schema later.
            default => false,
        };
    }

    /**
     * Would the pricing-model correction itself publish? Mirrors `canonicalClassification()`,
     * so the two cannot disagree about whether the contract ends up carrying the corrected
     * model.
     *
     * @param  array<string, mixed>  $output
     */
    private function pricingModelCorrectionPublishes(array $output): bool
    {
        $consistency = $output['source_consistency'] ?? [];

        return $this->canPublishClassification(
            $output['classification']['primary_pricing_model'] ?? 'Unknown',
            $consistency['recommended_pricing_model'] ?? 'Unknown',
            $consistency['pricing_model_status'] ?? 'uncertain',
            $output['confidence']['classification'] ?? 'low',
            ['Spot', 'FixedPrice', 'Hybrid'],
        );
    }

    /**
     * @param  list<string>  $allowedValues
     */
    private function canPublishClassification(
        mixed $actual,
        mixed $recommended,
        mixed $status,
        mixed $confidence,
        array $allowedValues,
    ): bool {
        if (! in_array($actual, $allowedValues, true) || $actual !== $recommended) {
            return false;
        }

        if ($status === 'match') {
            return true;
        }

        return $status === 'mismatch' && $confidence === 'high';
    }

    private function fixedTimeRange(int $months): string
    {
        return match (true) {
            $months < 6 => 'Below6',
            $months === 6 => 'Fixed6',
            $months < 12 => 'Between711',
            $months === 12 => 'Fixed12',
            $months < 24 => 'Between1323',
            $months === 24 => 'Fixed24',
            $months > 24 => 'Over24',
            default => 'Other',
        };
    }
}
