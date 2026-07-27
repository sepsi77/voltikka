<?php

namespace App\Services\ContractInterpretation;

use App\Models\ActiveContract;
use App\Models\ContractInterpretation;
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
            /** @var ContractInterpretation $lockedInterpretation */
            $lockedInterpretation = ContractInterpretation::query()
                ->lockForUpdate()
                ->findOrFail($interpretation->id);
            /** @var ElectricityContract $contract */
            $contract = ElectricityContract::query()
                ->lockForUpdate()
                ->findOrFail($lockedInterpretation->contract_id);

            $latestSnapshotId = ContractSourceSnapshot::query()
                ->where('contract_id', $contract->id)
                ->orderByDesc('first_observed_at')
                ->orderByDesc('id')
                ->value('id');

            if ((int) $latestSnapshotId !== (int) $lockedInterpretation->source_snapshot_id) {
                $lockedInterpretation->update([
                    'status' => ContractInterpretation::STATUS_SUPERSEDED,
                    'completed_at' => now(),
                    'error' => 'A newer source snapshot exists.',
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

            /** @var ContractSourceSnapshot $sourceSnapshot */
            $sourceSnapshot = $lockedInterpretation->sourceSnapshot()->firstOrFail();
            $canPublishPricing = $this->canPublishSourcePricing($output);
            if ($canPublishPricing) {
                $this->priceComponentWriter->write(
                    [$sourceSnapshot->source_payload],
                    $sourceSnapshot->first_observed_at->toDateString(),
                    [$contract->id],
                );
            }

            $latestObservation = ContractSourceSnapshot::max('last_observed_at');
            if ($canPublishPricing
                && $sourceSnapshot->last_observed_at->toDateTimeString() === $latestObservation) {
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
     * Only publish structured prices when interpretation found no known unsafe omission.
     * Rich corrected phases remain in interpretation JSON until phase calculation exists.
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
        if ($this->isConsumptionEffectOnly($output)) {
            return true;
        }

        return ! in_array(
            $output['source_consistency']['structured_pricing_status'] ?? null,
            ['incomplete', 'conflicting'],
            true,
        ) && ($output['source_consistency']['misleading_first_12_months'] ?? null) !== 'detected'
            && ! in_array(
                $output['calculation']['status'] ?? null,
                ['incomplete', 'unsupported'],
                true,
            );
    }

    /**
     * True when the only thing standing between the source components and publication
     * is an unquantifiable consumption effect.
     *
     * A Hybrid ("joustosähkö"/"kulutusvaikutus") product prices a disclosed base energy
     * rate plus a customer-specific consumption-profile adjustment whose amount the seller
     * almost never publishes. Prompt v17 therefore requires exactly this shape for it:
     * `unsupported_consumption_effect`, `structured_pricing_status = incomplete`, and
     * `calculation.status = unsupported`. That is the *correct* reading of the source, and
     * it means Voltikka cannot compute a 12-month total — but it says nothing bad about the
     * base components themselves, which are complete, disclosed and identical to what the
     * pre-interpretation importer wrote.
     *
     * Without this carve-out the general gate below blocked every Hybrid contract forever:
     * production stopped writing `price_components` for all 49 active Hybrid contracts the
     * day import-time interpretation went live (2026-07-24), which froze their card prices
     * and erased the `hybrid` segment from `/sahkosopimus/tilastot` entirely. The
     * consumption effect is disclosed to the visitor as its own pricing category and card
     * warning, so publishing the base rate is honest; withholding it is what misled.
     *
     * This mirrors validator v4's `recurring_reset_requires_estimate` carve-out: an expected,
     * product-defining reason for an estimate must not be read as unsafe source data. It stays
     * narrow — any *other* issue code, a detected deception, or an `incomplete` calculation
     * (which means facts beyond the effect are missing too) still blocks publication.
     *
     * @param  array<string, mixed>  $output
     */
    private function isConsumptionEffectOnly(array $output): bool
    {
        $consistency = $output['source_consistency'] ?? [];
        $issueCodes = $consistency['issue_codes'] ?? [];

        if (! is_array($issueCodes) || ! in_array('unsupported_consumption_effect', $issueCodes, true)) {
            return false;
        }

        // `structured_matches_description` only records that prose and structured data agree.
        if (array_diff($issueCodes, ['unsupported_consumption_effect', 'structured_matches_description']) !== []) {
            return false;
        }

        // `unsupported` is the effect itself. `incomplete` means other facts are missing too.
        if (($output['calculation']['status'] ?? null) !== 'unsupported') {
            return false;
        }

        // A conflict is a disagreement inside the source, not an unpriced adjustment.
        if (($consistency['structured_pricing_status'] ?? null) === 'conflicting') {
            return false;
        }

        // A deception found beside the effect is a separate, genuine reason to withhold.
        if (($consistency['misleading_first_12_months'] ?? null) === 'detected') {
            return false;
        }

        $effect = $output['pricing']['consumption_effect'] ?? [];

        return ($effect['present'] ?? false) === true
            && in_array($effect['applies_to'] ?? null, ['base_contract', 'both'], true);
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
