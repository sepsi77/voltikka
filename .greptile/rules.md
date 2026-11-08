# How to review Voltikka

Voltikka (https://voltikka.fi/) compares Finnish electricity contracts, shows spot prices and
contract-price statistics, and publishes forecasts and a monthly market review. Consumers choose a
contract from the prices we show, and journalists quote our figures. A wrong or misleading number
is the most expensive bug here.

## What matters most, in order

1. **Published numbers are correct and honest.** Check annual costs, unit prices, statistics,
   forecasts and market-review figures: VAT basis (household prices include 25.5% VAT, company
   prices exclude it; convert once), missing or unknown values counted as zero, wrong periods or
   denominators, Helsinki dates and DST, sign errors, and labels that overstate what was measured.
2. **Canonical pricing is the source of truth.** Where canonical pricing and relational price
   components disagree, canonical wins. A surface that silently falls back to relational rows, or
   drops a contract because it has none, re-exposes seller price manipulation.
3. **Historical evidence is not rewritten.** Observed source evidence, stored snapshots and
   retained method rows stay unchanged. History calculations use only evidence that existed at the
   target date (no later seller facts, current peers or future curves).
4. **Production safety.** Commands that write data are dry runs by default. No automatic rebuild,
   retry of a failed command, or production writer without an explicit approval step. Deploy scripts
   keep their guards. No secrets in code, logs or Sentry context.
5. **Cache identity.** A change of price semantics changes the cache key or schema version, so no
   visitor sees a payload built with the old rules. Public GET/HEAD requests never calculate missing
   market prices.
6. **UX truth.** Pages say what is known; unavailable, empty and error states are distinct and
   honest. Finnish copy is correct.

## Please do not flag

- **Missing abstractions or future-proofing.** The project follows a strict simplicity rule: no
  extension points, generic frameworks, extra retries, caches, queues, flags or config without a
  current requirement.
- **Formatting and lint style.** Pint, the PHP test suite and the Python linters cover it.
- **Real database tests.** Laravel feature tests use a real test database on purpose; only external
  HTTP (`Http::fake`) and time are faked.
- **Non-code content.** These paths are excluded from review: task records (`tasks/`), content and
  material (`media/`, `research/`, `market-review/editions/`), design art and boards (`design/`),
  video production (`videos/`, `remotion/`), agent session state (`.autoclaude/`), built images
  (`laravel/public/images/`, `laravel/public/build/`) and `legacy/` (deprecated code, not in use).
  The code that produces published figures (`market-review/tools/`) and the public pages in
  `laravel/` are reviewed.
- **`CLAUDE.md` and `AGENTS.md` pairs.** `CLAUDE.md` is a symlink to, or an exact copy of, the sibling
  `AGENTS.md`. Identical edits in both are required.
- **Agent definition copies.** `.claude/agents/<name>.md` are required copies of
  `.pi/agents/<name>.md`; they differ only by a `model:` line.
- **Opinionated public copy.** Public text takes clear expert stances on purpose (root `AGENTS.md`,
  "Writing rules for public copy"). Do not ask for hedges or disclaimers. Flag a claim only when it
  is false against the code or data.
- **Legitimate negative or zero spot prices.** They are valid values, not errors.
- **Decisions recorded in a task's `decisions.md` or a nested `AGENTS.md`**, unless the code shows
  wrong, stale or misleading results. Then flag the result and cite the decision.

## Useful context

- Root `AGENTS.md` is the project context; each area has its own `AGENTS.md` with local rules and the
  reasons behind them. `laravel/app/Services/CanonicalPricing/AGENTS.md` and
  `laravel/app/Services/ContractStatistics/AGENTS.md` hold the pricing and statistics rules.
- Public text is Finnish. Code, comments and context files are English.
- A finding is most useful with a concrete failure path: the input or state, and the wrong output a
  visitor, journalist or operator would see.
