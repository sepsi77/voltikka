<?php

namespace App\Services\DTO;

/**
 * Result of a bill comparison.
 *
 * Rows are sorted ascending by period cost (the most honest ranking, since the
 * user's bill total and every market contract's period cost cover the same date
 * range and consumption). The user's own row is included at its real rank.
 */
class BillComparisonResult
{
    /**
     * @param list<BillComparisonRow> $rows Sorted by periodCostEur ascending.
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $rows,
        public readonly float $userImpliedCentsPerKwh,
        public readonly float $userAnnualCost,
        public readonly int $userRank,
        public readonly int $totalContracts,
        public readonly float $periodSavingEur,
        public readonly float $annualSavingEur,
        public readonly float $monthlySavingEur,
        public readonly ?float $spotAvgCentsPerKwh,
        public readonly bool $hasSpotInTop,
        public readonly ?float $annualKwh,
        public readonly array $warnings = [],
    ) {
    }

    public function isOverpaying(): bool
    {
        return $this->annualSavingEur > 0;
    }

    public function userRow(): ?BillComparisonRow
    {
        foreach ($this->rows as $row) {
            if ($row->isUser) {
                return $row;
            }
        }

        return null;
    }

    public function cheapestRow(): ?BillComparisonRow
    {
        return $this->rows[0] ?? null;
    }
}
