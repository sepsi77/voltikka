# Public energy-rule output

## Current audit follow-up

See `audit-fixes.md` for verified local public-copy and estimate-disclosure repairs and the
2766-test combined gate. Premium-reset copy is truthful; real short-term metadata drives public
surfaces, including Hybrid. A quiet term fact does not consume the warning cap. Normal Supplier
fee assumptions follow variation in either real fee timeline; absent actual estimator provenance
stays null. The ContractDetail short-term qualifier now describes published term prices without
a constant-price promise; 183 tests / 788 assertions pass. Video flash/Hybrid disclosure fixes and
bounded 12-PNG QA also pass. Final combined verification passed (2773 tests / 18253 assertions,
Laravel and Remotion checks); the final replay resolves broken anchors. Financial amount acceptance
and release remain open. The report-only cent-comparison correction passes 14 smoke cases and
preserves raw fields: Vaasa real-term benefit stays 5.90 (annual equivalent 11.80), Hehku loses 4.90,
and Aalto 5.95 stays unchanged. See `audit-fixes.md` for the corrected report. Bounded QA is not full-video/feed/phone/compression approval.

## Implemented local boundary

This unit changes public transport and presentation. It does not change default V4 interpretation,
annual-v2, beta or Historical pricing. The later release-boundary unit sets the shared calculated-cost
schema to 19 and updates task status. All core hooks are connected; the manager's fresh combined
LOCAL PHP gate passed. Remotion ESLint, TypeScript, bundle and bounded synthetic visual checks pass; see
`video-output-check.md` and the current acceptance in `final-verification.md`. Producer activation is separate; see `release-plan.md`.

### Core hooks

- `App\Services\CanonicalPricing\Support\EnergyRuleOfferTerms::build(CanonicalContractData $data, EnergyRulePlan $plan, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array` returns `list<OfferTermData>`.
- It uses admitted energy-rule spans and the original governing fee timeline. It does not bill or
  recompute savings. Genuine fixed reductions and positive absolute/percentage operators qualify;
  protection alone, ordinary changes, equal metadata and zero operators do not. Positive percentage
  operators survive current normal zero. Monthly-fee offers keep their own timing. Explicit normal
  metadata is primary; an independently introductory fee can use its exact typed normal continuation.
  Unsupported changed fee components suppress the entire offer. Normal-unavailable plans emit none.
- `EnergyRuleComparison` retains all previous constructor arguments. Optional trailing arguments:
  `?float $annualEquivalentEnergyPrice = null`,
  `SupplierAdjustedEstimate|ResetEstimate|null $actualProjection = null`, and
  `?array $currentNormalRates = null`. Core passes the proved available plan baseline as
  `currentNormalRates`, including when a held/fixed comparison has no projection object. Actual-only
  results pass null; explicit rates must agree with supplied normal projection rates.
- `EstimateMethod::SourceEnergyRules` has value `source_energy_rules_v1`. It identifies estimated
  actual energy only. Exact actual pricing retains its existing exact/term/Hybrid method.
- Source-rule phase breakdowns may carry boolean `energy_price_guaranteed`. The financial core
  must set it true only when displayed `energy_cents` is the actual guaranteed billed rate throughout
  that resolved span. Current disclosed phase amounts alone cannot prove this. Presentation validates
  the flag and preserves directional warnings only for matching adjacent guaranteed actual rates
  and their exact change date. This core hook is now connected; the UI never infers it.

### Transport

`energy_rule_comparison` contains the exact method identity, separate actual/normal certainty,
normal availability/hold state, signed monthly differences/net difference, annual equivalent,
current normal rates and normal `projection` provenance. Separate `actual_projection` provenance
supports an estimated actual-only continuation without asserting a current normal tariff.
For Cheap-style later announced pricing, a projection reference is not a current normal quote.
Source quotes are not serialized.

The serializer retains actual-only real-term totals and nullable normal/base/savings facts. The
schema-18 serializer exception is removed. Signed losses count against gains. Offer eligibility
requires normal availability, genuine typed terms and positive signed net over the same horizon.
Short-term public benefit stays unannualized. Strict view hydration checks finite numbers, exact
booleans/enums, monthly/net/term reconciliation and conditional absence. Legacy canonical records
without the new record retain their previous validation and exact payload round trip.

### Public surfaces

- Standard and featured cards share the presenter. Actual current rates and estimated annual
  equivalents are separate. An exact actual price can have `Arvioitu säästö` without a price-estimate
  marker. Normal comparison values are qualified separately. Fee-only benefits remain exact when
  the shared actual/normal energy forecast cancels, even though both total-price figures are estimates.
  Genuine energy offers use conservative estimate qualification, including model floors.
- Controlled offer copy distinguishes `kiinteänä` from absolute/percentage `alennus normaalihinnasta`,
  and keeps operator, floor and real timing. Detail uses the same offer description and receipt
  rows. Current normal rates say they can change; projected normal comparisons are not advertised
  as guaranteed dated price increases. An unsupported directional promotion notice becomes the
  supported offer-end fact, or is omitted when no proved end exists. Guaranteed actual transitions
  survive through the typed phase proof. Data-conflict warnings remain. Detail hero/verdict/FAQ
  use the same source-rule explanation and do not extend current prices into future guarantees.
- Company offers show the same benefit qualification and uncertainty note.
- Public contract API exposes `energy_rule_comparison`, independent `benefit_is_estimate` and shared
  offer facts. Price-bearing promotion integrity facts are omitted for projected normal comparisons.
- Weekly data/API/prompt use the same eligibility and certainty flags. Canonical descriptions no
  longer copy seller prose. The prompt forbids guaranteed savings and future-rate claims.
- Remotion's existing WeeklyOffers layout now labels estimated benefits and includes the uncertainty
  note. No new-rule class is excluded just because its normal comparison is estimated. Existing
  integrity/package/listability exclusions remain. Actual-only contracts stay available as contracts
  but cannot enter offer-only surfaces.

## Verification

- Targeted public-boundary/surface/kernel run before the last directional-copy checks: 279 passed,
  1373 assertions, 8.02 seconds. Command from
  `laravel`: `DB_URL='' APP_CONFIG_CACHE=/tmp/energy-public-no-config php artisan test --filter='EnergyRulePublicOutputTest|EnergyRuleKernelTest|ContractPricingReadModelTest|ContractApiCanonicalPricingTest|ContractDetailPageTest|ContractDetailPresenterTest|CanonicalOfferSurfacesTest|WeeklyOffersCanonicalPricingTest|ContractCardPresenterTest'`.
- Kernel run after the financial agent connected the helper: 29 passed, 215 assertions, 1.35 seconds.
- `npm run build` in Laravel: passed, 854 ms. Existing old caniuse-lite warning only.
- `npm run lint` and `npm run build` in Remotion are blocked: local `eslint` and `remotion` commands
  are missing. No install or network request was made. This leaves the small TSX/type change
  unverified by its own toolchain.
- First full suite: 4 failed, 2728 passed. Failures were in the concurrent financial fixtures and
  episode memo test. A later check of those suites passed: 24 tests, 402 assertions.
- Second full suite: **2746 passed, 17762 assertions**, 138.66 seconds. Log:
  `/tmp/energy-public-full-final.log`. This run preceded the final neutral offer-end/guaranteed-phase
  copy checks; those have separate focused verification.
- Final owned focused command (the filter above): **282 passed, 1393 assertions**, 7.93 seconds.
  Log: `/tmp/energy-public-final-owned.log`. Includes scalar/map tampering, source-rate FAQ copy,
  both-card neutral-end rendering and preservation of a typed guaranteed actual transition.
- `vendor/bin/pint --test` on all 17 owned PHP files passed. Final Laravel `npm run build` passed,
  800 ms. `git diff --check` passed. Final status/diff reviewed; all eight touched AGENTS/CLAUDE
  pairs match. The large pre-existing and concurrent worktree was not reset.

## Handoff exception — resolved by financial integration

The earlier missing `energy_price_guaranteed` hook is now connected with `EnergyRuleOfferTerms`
and `currentNormalRates`. Source-rule known-transition notices require actual-rate proof, never
normal metadata. Unsupported transitions still fail closed to supported offer-end facts. See
`service-integration.md` for the final financial checks. Earlier public test reports above remain
unit history, not the manager's new combined acceptance gate.

No production action, application LLM call, migration command, commit or push was run.
