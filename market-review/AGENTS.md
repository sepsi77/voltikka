# market-review/ — Voltikan sähkömarkkinakatsaus (monthly)

The monthly electricity market review is a recurring product, not a one-off task, so it has its
own top-level folder (user decision 2026-11-06; it was `tasks/monthly-market-review/`). Read the
root `AGENTS.md` first: the site-wide "Writing rules for public copy" apply to every edition.

## Folder layout

| Path | What it is |
|---|---|
| `spec.md`, `data-inventory.md` | What the review contains and every data source with its frequency and gaps |
| `decisions.md` | Method, copy and design decisions with reasons, newest last. Read it before an edition. Entries before 2026-11-06 say `prototype/...`; that is now `tools/` and `editions/` |
| `tasks.json` | Open and done work (same format as `tasks/`) |
| `video.md` | The review video (made in `videos/monthly-market-review/`) |
| `tools/` | Scripts for an edition, with `tools/README.md` (commands). Run them from `tools/` |
| `editions/<YYYY-MM>/` | One folder per review month: the draft (`<month>-katsaus.md`), the frozen figures (`metrics-*.json`, `drivers-*.json`, `consumer-*.json`) and draft SVG charts. The consumer files carry the forecast month in their name (October 2026 → `consumer-outlook-2026-11.json`) |
| `editions/<YYYY-MM>/video/` | The edition's video sources (git): `script.mjs` (voice lines, variants, YouTube text), scene HTML, `data.js`, `timeline/`. The shared engine is `videos/monthly-market-review/` |
| `editions/<YYYY-MM>/work/` | Work files of the edition (not in git): voice takes, voice tracks, renders, stills, archived versions, storyboard images |
| `editions/<YYYY-MM>/final/` | The files to publish: the review video and its Shorts (`final/shorts/`) with `.srt`, title, description and thumbnail. Text files are in git, media are not (`.gitignore`) |

The public page is in Laravel, not here: `laravel/config/market_reviews.php` (list of editions,
`draft` flag, video), `laravel/resources/views/market-reviews/<month>.blade.php` (frozen page
text), `laravel/resources/market-reviews/<month>/` (frozen chart JSON and CSV). See
`laravel/AGENTS.md`, "Monthly market review page".

## Each month (short)

1. Data: run the collectors' backfills if needed, then sync the local database
   (`scripts/sync-production-database.sh`).
2. Figures: `tools/compute.py`, `tools/drivers.py`, the consumer layer (`consumer_inputs.py` →
   `run_consumer.php` → `consumer_results.py`, `consumer_outlook.py`) into `editions/<month>/`.
3. Draft: `editions/<month>/<month>-katsaus.md`. Mark real data problems ⟦TARKISTA⟧ and resolve
   them before publication.
4. Page: copy the previous month's Blade view as a start, write the frozen text; chart JSON with
   `tools/site_payloads.py` (it refuses to overwrite; `--force` only before publication); CSV
   `luvut.csv` (no EEX futures values). Add the edition with `draft => true` to the config.
5. Video and Shorts: `editions/<month>/video/` → `final/` with the engine in `videos/monthly-market-review/` (see its `AGENTS.md`).
6. Publish: `draft => false`, `published_on`, `teaser` (the one line of the home page row), video
   `youtube_id` + `uploaded_on`; tests; commit; deploy with approval. The home page row and the
   menu link change to the new edition by themselves.

## Rules that are easy to get wrong

- A monthly change runs from the last day of the previous month to the last day of the month,
  from stored values; round only the result (2026-11-06).
- Published figures are frozen: the page never reads the database. Correct a published figure
  only with a dated note.
- Spot vs fixed is cost against certainty; compare long fixed contracts over their full term.
- Name a winner only at a known normal price (promotions without a known normal price are out)
  and only for household contracts available nationally.
- EEX/JAO: quote only a few values with source and date; no tables, curves or CSV of them.
- Never mention Energiavirasto in public copy.
