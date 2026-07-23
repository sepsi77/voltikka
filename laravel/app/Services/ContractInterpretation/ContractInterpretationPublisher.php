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
     * @param  array<string, mixed>  $output
     */
    private function canPublishSourcePricing(array $output): bool
    {
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
