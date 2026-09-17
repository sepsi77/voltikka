# Current calculation integration

## Status

Partial end-to-end implementation. The current read-side V5 proof and the focused financial
kernel are implemented and tested. The kernel costs explicitly parsed V5 DTOs with an explicitly
supplied underlying-normal estimate. Current service parser opt-in remains disabled. Do not
activate it before normal-anchor integration and estimated-offer presentation are complete.

## Implemented API

`CurrentSourcePromotionEvidence::forContracts(Collection $contracts, ?CarbonInterface $asOf = null)`
keeps `valid` and `rates` compatible and adds `energy_rules_valid` to each pointed record.

The new boolean requires:

- Existing exact contract, current observation, published interpretation and source ownership.
- Exactly schema-v5, prompt-v20 and validator-v18; legacy validity does not authorize rules.
- Matching optional analysis observation ID, published status, and empty validation errors.
- Observation start, completion and publication no later than the Helsinki as-of day's UTC end.
  Publication cannot precede observation start or completion. Last observation cannot precede
  first observation. An unchanged current pointer remains usable after its last observation day.
- Complete output equality with both stored and caller-supplied pricing, calculation and source
  consistency arrays. Numeric JSON representation follows existing publication equality.
- Full profile-bound validation against input rebuilt from the selected immutable source and
  the observation's original analysis date. Validation makes no model call and no write.

The current service's shared parse/anchor path (all four entry points) and premium loader pass
explicit comparison dates to this existing proof read. Neither opts into energy-rule parsing yet.
This prevents partial activation before known guarantees and counterfactuals are safe.

Query count: still one joined batch SELECT for any pointed contract set; zero SELECTs when all
contracts are unpointed. Validation adds no per-contract query. Tests measure these counts.
No second source-proof service was added.

## Focused financial kernel

`CanonicalContractPriceCalculator::calculate` adds the trailing optional
`?NormalEnergyProjection $normalEnergyProjection`. This argument is ignored on Historical and
Unknown-rule paths. It does not query or resolve evidence. `NormalEnergyProjection` wraps existing
SupplierAdjustedEstimate or ResetEstimate plus exact canonical normal rates in the target VAT basis.
The plan checks those rates against the source map. Supplier scalar summaries use existing 15/9
Time and 5/7 Season episode weights; reset scalar summaries use the calculator's existing usage
weights. A promotional-price estimate cannot act as this normal reference.

`EnergyRulePlan` admits one complete current General/Time/Season normal tariff and current actual
energy map. Typed normal continuations must agree with that map. Changing ordinary/future normal
references, unsupported mixtures, Spot and packages fail closed when known rules exist. Known
future fixed spans are accepted without borrowing them for earlier gaps. Conflicting overlapping
fixed/formula spans fail closed. Missing current energy remains unavailable. The kernel does not
infer seller terms from normal amounts alone.

Five follow-up corrections extend this bounded kernel without service activation. The fully
known-actual follow-up below supersedes their counterfactual-only exclusion limits:

- Plain Current fixed energy with no normal metadata remains available when own guarantees cover
  every costed segment. Actual and normal energy are identical. Fixed6 keeps real-term annualization;
  Fixed12/24 keep first-year pricing. Fees and fee-only promotions remain in core billing. This billed
  map is not an adjustable normal tariff or a premium donor; a supplied normal projection is rejected
  for this fixed-only map. Genuine promotions without counterfactual proof remain excluded.
- Actual expiry no longer drops independently active normal proof. A matching current typed Normal
  continuation with an Unknown rule can establish current coverage. An already-started expired fee
  phase can still supply its ongoing own energy guarantee. A future parent cannot supply earlier
  actual coverage. Wrong normal values and explicit energy changes that conflict with inherited
  guarantees fail closed.
- A future ordinary fixed11 span with no normal basis cannot reuse old normal9 before/after that
  span. Changing-normal-reference plans remain unsupported. A distinct introductory fixed overlay
  can use explicit current normal9. Fixed offer removal requires the component's Introductory role,
  not a numeric difference on an ordinary Future component.
- Billed introductory coverage must retain an explicit fixed/formula rule throughout the promotion.
  A two-month guarantee under a three-month billed promotion now fails closed unless another
  explicit rule covers the rest. Fee splits and repeated unchanged guarantees remain supported.
- Parents starting at or after the selected cost-window end do not add plan requirements. Active
  independent facts from already-started past parents remain available.

These are calculation tests on typed/parser inputs, not a claim of new source-language coverage.

### Fully guaranteed actual-only availability

The same plan builder now makes a bounded guarantee-only attempt when the paired plan cannot be
built. This is not another billing engine: it creates the same EnergyRulePlan and uses the existing
costWindow/costSegment. Every required energy bucket must have an explicit FixedPrice rule over the
complete real comparison window. Rule and parent boundaries are checked before admission. Current
coverage, future-parent, overlap, conflicting explicit energy, component, fee and package guards stay
in force. No supplied normal projection is used by this attempt, and no normal/premium anchor is
created. A fixed9 four-month guarantee with an unknown actual tail still fails.

- Fixed4 for all 12 months with normal9 but Unknown normal basis lists at 48 EUR for 1200 kWh.
- The same Fixed6 lists with actual term cost 24 EUR and annualized comparison 48 EUR.
- Ordinary fixed9 for six months then fixed11 for six months lists at 120 EUR. Normal energy follows
  those same guarantees when there is no energy offer; fee-only normal comparisons remain intact.
- Future ordinary11 in May followed by unknown June still fails. Full fixed coverage does not excuse
  missing current energy, conflicting locks, incomplete tariff buckets or unsupported billed fees.

A genuine/unresolved energy offer with unavailable counterfactual proof sets the internal
`normalAvailable=false`, baseTotalCost=null, baseMonthlyCosts=[], signed differences=[], and
netDifference=null. It has no measured benefit or offer terms. The assumption is
`energy_rule_normal_unavailable`; it does not claim normal-known, normal-held or a normal forecast.
Actual term totals remain independent from missing normal term totals.

**Term transport limit for schema 19:** the old strict public record requires normal cost inside a
term record, and term_price_only requires that record. New actual-only terms therefore cannot use
schema 18. Their serializer throws a specific LogicException instead of emitting invalid data or
inventing normal values. No existing supported round trip changes. Whole-year actual-only outcomes
with null normal cost do round-trip. Public term transport/copy must be implemented before service
activation; the current read model was not relaxed.

Own actual and normal bounds resolve through the existing PhaseTimelineBuilder. Splits retain
calendar usage and no-overflow billing fractions. `costWindow` applies absolute per-bucket paired
rates after governing-phase resolution, then calls the existing costSegment without a second
supplier/reset adjustment. Fixed actual prices remain protected. Formula prices apply their source
operator to projected normal prices. Source floors and the existing nonnegative model floor remain
separate. Known independently fixed normal spans suppress normal projection only on their bounds.
Without a supplied estimate, the normal tariff has an explicit hold assumption, not a guarantee.

Monthly explicit normal fees remain primary, including zero/equal/lower values. FeeCurrent4 to
ordinary future8 remains timed in both passes. An implicit fee introduction requires the monthly
component's own Introductory role, not its parent's energy-intro phase. Original fees, one-time
charges, profile/calendar billing and real-term annualization remain in the existing engine.
`normalHoldMonthlyCosts` cannot replace the paired normal calculation. Component clones retain
rules; `withoutEnergyOffer()` uses the offer's own validated normal basis and preserves locks when
there is no genuine energy offer. A positive percentage at normal zero remains a real operator.

`EnergyRuleComparison` is an internal typed record with method identity `source_energy_rules_v1`,
signed monthly differences, their net, separate actual/normal certainty, hold state and the supplied
typed estimate. A fixed whole-year actual total remains exact while normal savings use forecasts.
Core monthly savings are signed; core positive benefit is max(0, net), not sum(max(0, month)).
Real-term core costs and net remain unannualized; comparison values use 12/term once.

Public offer presentation is deliberately not activated: outcome serialization emits empty monthly
offer savings, zero offer benefit (also for the term), no offer terms and includes_discounts=false.
It does not fabricate a monthly allocation. Actual/normal costs, current billed rates and ranking
remain available. The new internal record is not serialized. Existing public read-model round trips
are tested, including a negative real-term net. Normal reference rates never replace current billed
rates. Future integration must add truthful estimated-benefit transport and copy before removing
this withholding rule.

## Pending required implementation

- Proven current normal-map candidates and trusted lineage episodes; compatible peer premiums.
- Current parser opt-in from the accepted batched proof in every service/loader path.
- Estimated-benefit public transport/copy and offer-surface acceptance.
- Real-service, SQLite publication-proof, lineage, peer, API and surface acceptance together.
- Full changing-normal-reference/future-reference plans remain unsupported.
- One calculated-cost schema bump from 18 to 19 when service activation makes new semantics active.

Schema remains 18 because no existing service enables energy-rule parsing. V4/v19/v17 remain
defaults; parser default false, Historical and annual method selection stay unchanged.
Root and CanonicalPricing parent contexts remain manager-owned. The ForwardPremium context
records the loader's new dated proof call and explicitly states that parsing is not activated.

## Verification

All Artisan commands run from `laravel` with
`DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config`; PHPUnit uses isolated SQLite.
New proof tests prevent stray HTTP requests and assert that none were sent.

- Final `php artisan test --filter=SourceValidatedEnergyRulesTest`: 11 passed, 73 assertions,
  including malformed source input and date/status/tuple checks.
- `php artisan test --filter='SourceValidatedEnergyRulesTest|CurrentSupplierPremiumIntegrationTest|CurrentPriceEpisodeResolverTest|CanonicalContractPricingServiceTest|Historical'`:
  206 passed, 3853 assertions; `/tmp/energy-current-proof-tests.log`.
- `php artisan test --filter='EnergyRule|Promotion|CurrentReset|CurrentHybrid|CurrentSupplier|ContractApiCanonicalPricingTest'`:
  202 passed, 4130 assertions; `/tmp/energy-proof-related-tests.log`.
- Scoped Pint ran on the four changed PHP files; only import order needed correction.
  Final `vendor/bin/pint --test` passed on those four files. `git diff --check` passed.
  Final status and the changed source were reviewed.

Kernel verification (same isolated SQLite environment, no HTTP requests):

- Final `php artisan test --filter=EnergyRuleKernelTest`: 18 passed, 94 assertions.
  Early failures were a PHPUnit reserved helper name,
  two wrong enum names, an introductory role on a future-only test component, and the new reset
  scalar check reading zero-use profile buckets not present in the tariff. A wrong-unit fixture
  was correctly rejected by the parser before reaching the kernel; that redundant kernel assertion
  was removed. All remaining tests pass.
- Final `php artisan test --filter='CanonicalContractPriceCalculatorTest|EnergyRuleKernelTest|Supplier|Reset|Hybrid|Historical'`:
  514 passed, 6362 assertions; `/tmp/energy-kernel-related.log`.
- Kernel coverage includes A4/B7 with normal12; normal-only forecast certainty; positive/negative
  monthly netting and negative six-month net; 0/50/100 percentages at normal0; source/model floors;
  General/Time/Season, Supplier and Reset estimates; VAT; midmonth and Jan31 boundaries; six-month
  Hybrid clipping; future fixed spans and preceding gaps; wrong baseline/current evidence guards;
  own-normal offer removal; independent fees and no repeated fee uplift in gaps;
  Unknown/Historical invariance and public round trips.
- Final scoped `vendor/bin/pint --test` passed on the seven PHP files in this unit.
  `git diff --check` passed. Final source, diff and status were reviewed.
- No source profile, proof, parser asset, service opt-in, loader or public read-model code changed
  in the kernel unit. The nearest DTO context records the implementation and staging rules.

Five-fix follow-up verification (same isolated environment):

- Reproduction: `php artisan test --filter=EnergyRuleKernelTest` gave 6 failed, 17 passed,
  97 assertions after correcting an invalid mixed relative/date test rule. All six remaining
  failures were the requested calculation gaps, including the inherited fee-phase guarantee.
- Final `php artisan test --filter=EnergyRuleKernelTest`: 26 passed, 144 assertions,
  0.84 seconds; `/tmp/energy-five-kernel.log`.
- Final `php artisan test --filter='EnergyRuleKernelTest|CanonicalContractPriceCalculatorTest|Supplier|Reset|Hybrid|Historical'`:
  522 passed, 6412 assertions, 29.29 seconds; `/tmp/energy-five-related.log`.
- An intermediate related run had one test-fixture failure: an array append after unset skipped
  index 1, and a later test edit created an empty phase. The local helper now reindexes its phases.
  The old midmonth-rule test also had a longer billed promotion than its guarantee; its parent and
  normal continuation now disclose the same explicit boundary. Neither correction weakens proof.
- Scoped Pint and final `vendor/bin/pint --test` passed for EnergyRulePlan.php,
  CanonicalComponent.php and EnergyRuleKernelTest.php. `git diff --check` passed.
  Final source, diff and status were reviewed. DTO CLAUDE.md remains an AGENTS.md symlink.
- No core billing, comparison transport, source-proof, prompt, shared fixture, service opt-in,
  schema version, default profile, root context or shared tasks.json change in this follow-up.

Fully guaranteed actual-only follow-up verification (same isolated environment):

- Reproduction `php artisan test --filter=EnergyRuleKernelTest`: 2 failed, 26 passed,
  146 assertions; `/tmp/energy-actual-repro.log`.
- Final `php artisan test --filter=EnergyRuleKernelTest`: 29 passed, 194 assertions,
  1.02 seconds; `/tmp/energy-actual-kernel.log`.
- Final `php artisan test --filter='EnergyRuleKernelTest|CanonicalContractPriceCalculatorTest|Supplier|Reset|Hybrid|Historical'`:
  525 passed, 6462 assertions, 29.38 seconds; `/tmp/energy-actual-related.log`.
- Scoped Pint and final `vendor/bin/pint --test` passed on EnergyRulePlan, EnergyRuleComparison,
  CanonicalPricingOutcome, CanonicalContractPriceCalculator and EnergyRuleKernelTest.
  `git diff --check` passed; final source, diff and status were reviewed.
- Tests include actual-only 12/6-month costs, explicit normal-unavailable state, annual public
  round-trip, deliberate short-term transport refusal, ordinary fixed steps and fee-only benefits,
  ignored supplied forecasts, complete Time buckets, missing tails/current energy, conflicting
  guarantees and unsupported fees. Existing operator and Historical/Unknown guards still pass.
- No source-proof, prompt, shared fixture, service activation, schema/default, root context or
  shared tasks.json changes. CanonicalComponent was not changed by this additional follow-up.

No full suite, asset build, production operation, network request, LLM request, migration command,
history rewrite, commit or push ran. The existing unrelated working tree was preserved.
