<?php

namespace App\Services\ContractReplacement;

use App\Models\ElectricityContract;

class ContractReplacementLinker
{
    public function __construct(
        protected ContractReplacementMatcher $matcher,
    ) {
    }

    /**
     * Link currently inactive contracts to their detected replacements.
     *
     * Only persists high-confidence matches, and never overwrites an existing link.
     * This preserves historical chains: A -> B -> C instead of rewriting A -> C.
     *
     * @return array{linked: int, skipped_existing: int, skipped_no_match: int, skipped_not_high: int}
     */
    public function linkHighConfidenceMatches(): array
    {
        $linked = 0;
        $skippedExisting = 0;
        $skippedNoMatch = 0;
        $skippedNotHigh = 0;

        $inactiveContracts = ElectricityContract::query()
            ->whereDoesntHave('activeContract')
            ->whereNull('replaced_by_contract_id')
            ->orderBy('company_name')
            ->orderBy('name')
            ->get();

        foreach ($inactiveContracts as $inactive) {
            if ($inactive->replaced_by_contract_id) {
                $skippedExisting++;
                continue;
            }

            $match = $this->matcher->findBestReplacement($inactive);

            if (! $match) {
                $skippedNoMatch++;
                continue;
            }

            if (($match['confidence'] ?? null) !== 'high') {
                $skippedNotHigh++;
                continue;
            }

            /** @var ElectricityContract $candidate */
            $candidate = $match['candidate'];

            if ($candidate->id === $inactive->id) {
                $skippedNoMatch++;
                continue;
            }

            if ($this->wouldCreateCycle($inactive, $candidate)) {
                $skippedNoMatch++;
                continue;
            }

            $inactive->replaced_by_contract_id = $candidate->id;
            $inactive->save();
            $linked++;
        }

        return [
            'linked' => $linked,
            'skipped_existing' => $skippedExisting,
            'skipped_no_match' => $skippedNoMatch,
            'skipped_not_high' => $skippedNotHigh,
        ];
    }

    protected function wouldCreateCycle(ElectricityContract $inactive, ElectricityContract $candidate): bool
    {
        $seen = [$inactive->id => true];
        $current = $candidate;

        while ($current) {
            if (isset($seen[$current->id])) {
                return true;
            }

            $seen[$current->id] = true;
            $current = $current->replacedBy;
        }

        return false;
    }
}
