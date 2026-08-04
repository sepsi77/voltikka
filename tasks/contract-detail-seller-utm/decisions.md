# Decisions

- Use the machine-safe campaign value `voltikka_sahkovertailu`. Analytics interfaces can show a friendly campaign name separately.
- Apply UTM parameters only to external seller CTAs on contract detail pages.
- Add attribution in `ContractCardPresenter::sellerCta()` so the hero and sticky mobile CTA use the same URL.
- Apply attribution to the `order_link`, `product_link`, and external `company_url` ladder entries. Do not change the internal Voltikka company-page fallback.
- Preserve existing query parameter segments and fragments. Remove incoming values for the three controlled UTM keys, then append the required deterministic values.
- Regression coverage verifies each external fallback source, replacement of existing UTM values, query and fragment preservation, Blade `&amp;` escaping, and the unmodified internal fallback.
