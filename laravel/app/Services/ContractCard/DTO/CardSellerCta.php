<?php

namespace App\Services\ContractCard\DTO;

/**
 * The one action a contract page offers: go to the seller.
 *
 * It exists on the view model because the contract detail page used to render its CTA
 * only when `order_link` or `product_link` was set, so a contract carrying neither had a
 * page with no action at all. The fallback ladder guarantees a destination and the label
 * always describes the destination it actually has, so a company-page fallback never
 * promises the seller's order form.
 */
readonly class CardSellerCta
{
    public function __construct(
        public string $url,
        public string $label,
        /** External seller links open in a new tab and need rel="noopener"; internal ones do not. */
        public bool $external,
    ) {
    }
}
