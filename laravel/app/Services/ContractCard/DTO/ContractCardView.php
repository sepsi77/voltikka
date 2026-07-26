<?php

namespace App\Services\ContractCard\DTO;

use App\Models\Company;
use App\Services\ContractCard\Enums\PricingCategory;

/**
 * Everything both contract-card templates need, derived once on the server.
 *
 * Before this existed, `contract-card.blade.php` and `featured-contract-card.blade.php`
 * each carried ~120 lines of the same derivation, and the featured card (the #1 slot on
 * the homepage, /sahkosopimus, every SEO listing and /halvin-sahkosopimus) had silently
 * drifted: no integrity pill, no market-reset figures, no estimate marker. Keep every new
 * card fact here rather than in Blade.
 */
readonly class ContractCardView
{
    /**
     * @param list<string> $metaParts Company, then duration, then optional metering words.
     * @param list<CardReceiptLine> $receiptLines At most three rows, fee last.
     * @param list<CardFooterItem> $warnings At most two, priority ordered.
     * @param list<CardFooterItem> $facts Promotion and energy source.
     */
    public function __construct(
        public PricingCategory $category,
        public CardTypeBand $band,
        public string $detailUrl,
        public ?Company $company,
        public string $companyName,
        public string $contractName,
        public array $metaParts,
        public array $receiptLines,
        public ?float $totalCost,
        public ?float $monthlyCost,
        public ?CardEstimate $estimate,
        public array $warnings,
        public array $facts,
        public ?float $discountSavings,
        public ?float $baseTotalCost,
        public bool $exceedsConsumptionLimit,
        /** The seller link. Cards link to the detail page instead; the detail page uses this. */
        public ?CardSellerCta $sellerCta = null,
    ) {
    }

    /**
     * Whether the annual total is a labelled estimate. Drives the Arvio chip.
     *
     * Suppressed in bill mode by the presenter, because there the headline figure is the
     * billing-period cost, which keeps its own "laskutusjaksollasi" disclosure.
     */
    public function isEstimate(): bool
    {
        return $this->estimate !== null;
    }

    /**
     * @return list<CardFooterItem>
     */
    public function footerItems(): array
    {
        return [...$this->warnings, ...$this->facts];
    }

    public function hasFooter(): bool
    {
        return $this->warnings !== [] || $this->facts !== [];
    }
}
