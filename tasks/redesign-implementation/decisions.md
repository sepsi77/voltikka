# Decisions

- 2026-10-16: Keep Tailwind 3. Map the design tokens into `tailwind.config.js` through CSS variables from `resources/css/tokens.css` (a copy of `design/voltikka-tokens.css` as `:root` variables). A Tailwind 4 upgrade is a separate change and is not necessary for the redesign.
- 2026-10-16: Shared foundation (manager): `resources/css/tokens.css` (generated from `design/voltikka-tokens.css`), `resources/css/redesign.css` (`.vk-wrap`, tone variables `.vk-tone-dark|light`, hero plate/shade, `.vk-hero-h1`, `.vk-hero-lead`, `.vk-leader`, `.vk-focus`), Blade `x-ink.sparkle|wave|hairline|glyph`, `x-site.hero`, `App\Support\Season`, `scripts/build-design-art.sh` → `laravel/public/images/art/<name>-<season>[-<width>].webp`.
- 2026-10-16: Layout contract: a page with a painted hero passes `heroTone` ('dark'|'light', from `Season::heroTone(<art>)`) in its layout data, outside any prepared-data cache. The layout then renders the top bar absolutely over the hero. Without `heroTone` the top bar sits on a night band in the page flow (pages not yet redesigned).
- 2026-10-16: Hairlines between ledger entries use `--color-edge-strong` as on the Paper board (the spec text says `--color-edge`; the board wins for exact values).
- 2026-10-16: Local season preview: `?vuodenaika=talvi|kevat|kesa|syksy`, only when `APP_ENV=local`.
- 2026-10-16: Work split (user asked for subagents): shell, listing controls/results, ledger entries, /spot-price. Each agent owns separate files.
- 2026-10-16 (ledger): The ledger entry replaces both contract-card templates. `featured-contract-card` renders `contract-card` with `cheapest` + rank 1 (one template). Shared components (`x-card.band|receipt|footer`, `x-company-logo`, `x-info-popover`) got opt-in variants (`ledger` / `inked`); defaults are unchanged for the contract detail page. `ContractCardView::$bucket` (PricingBucket) added for the glyph and category colour.
- 2026-10-16 (ledger): Warning tags use `--color-price-high` as the spec says; the Paper board shows fox-deep. Five ledger lanes start at 1200px (not 1024): at 1024 the name and part labels were squeezed into narrow columns. 640-1199 uses a two-row layout.
- 2026-10-16: The desktop hero shade is 430px with a later fade (75%), not Paper's 360px. On the bright autumn and summer plates the last lines of the price index fell under 4.5:1 (spec §8). The spot hero keeps its own shade.
- 2026-10-16: Found while building: under Livewire 4.4.2, `wire:model.blur` without `.live` syncs only on the client and sends no request on blur (`livewire.esm.js`, `shouldSendNetwork = isLive || hasLazyWithoutLive`). The own-consumption field and the bill fields do not commit until another live action. This existed before the redesign. Fixing it changes the documented form policy and `FormInputBlurPolicyTest` (`wire:model.live.blur` keeps the "process on blur" intent), so it waits for the user.

## 2026-10-16 — Typed Livewire fields use `wire:model.live.blur`

The user approved the fix. Livewire 4.4.2 sends no request for a plain `wire:model.blur`; it only updates the local value on blur. All typed fields now use `wire:model.live.blur` (blur stays the one processing boundary; the order `.live.blur` matters, because `.blur.live` sends on each keystroke). `FormInputBlurPolicyTest` and the policy text in root, `laravel/` and `app/Livewire/` AGENTS.md changed with it. Verified in the browser: own consumption 12 345 typed + blur recalculates the listing.

## 2026-10-16 — Round 2 organisation

Eight area agents (home, detail, company, tools, energy, data, seo-pages, longread) build the remaining pages in parallel. Each area owns its Blade files, one `redesign-<area>.css` file and one art fragment `scripts/design-art/<area>.sh` (the main art script now sources the fragments; the round 1 art moved to `core.sh`). `Season::LIGHT_HEROES` is the only shared PHP list that areas edit, one line each.

## 2026-10-16 — Round 2 home: / and /sahkosopimus/halvin-sahkosopimus

- The homepage has its own hero component (`x-site.home-hero`, 760px, horizontal shade + top shade, as board 6HY-0). The desktop shade is wider (980 instead of 900) and holds 72% before its fade, and the dark shade fades out at its lower end: on the kesä and syksy plates the end of the 680px lead fell under 4.5:1 and the 640px dark shade left a hard edge on the lake.
- `x-site.hero` got an optional `foxClass` prop (default output unchanged) for the cheapest page's 236x177 sled fox.
- The cheapest podium entry is CSS only (`.cheapest-podium` in `redesign-home.css`) over the shared ledger entry, so there is still one card template.
- The homepage trend chart lead series (Pörssisähkö) is aurora through the existing `leadStroke` payload key. The other series keep the JS slate styles; per-category colours for them (board) need a change in `contract-price-statistics.js`, which this area does not own.
- The spot mini chart bars are aurora (board), not the old green/amber/red tiers; the state of the current price is in the number colour and its label.

## 2026-10-16 — Round 2 seo-pages (group E)

- Only `/sahkosopimus/porssisahko` gets the river-ice hero (`hero-porssisahko`): the art shows moving ice, which is the spot mechanism. The other pricing-type pages keep the village listing hero; Spot, Quarterly, ConsumptionEffect and FixedPrice pages get the large category glyph beside the results count (Paper 3PD-0). Time-of-use, seasonal, general and Hybrid pages get no glyph because their lists can mix buckets.
- Every city page uses the same coastal-city plate (`hero-city`, from the Helsinki art), as `pages-plan.md` says ("generic coastal city silhouette"). Superseded in round 3 (user, 2026-10-16): Helsinki, Tampere, Turku and Oulu have their own plate with recognisable landmarks (`hero-city-<slug>`); every other city uses a general lakeside town plate (`hero-city`). The fox stays in the same place in all five, so the CSS crop is shared.
- Board deviation: the porssisahko board has no price index, but the live page shows the 30-day spot trend. It moves inline under the lead, in the city board's layout (5SY-0), because the painted fox fills the right side. The hero is therefore about 565px high on a desktop, not 460.
- The side shade on these two heroes holds its strength a little longer than Paper (dark 62% to 46%, 0 at 66%; light 74% to 50%, 0 at 70%) so the 14px index text keeps 4.5:1 over the city lights.
- Below 1024px the two painted plates are a band under the text (like the spot hero), because the fox is in the middle of the plate and the text would sit on busy art.
- SEO listing H1s are long compound words ("Kvartaalisähkösopimukset" is 417px at 34px); the hero clipped them at 390px. On SEO listing heroes the phone H1 is `min(34px, 7vw)`. The shared `.vk-hero-h1` rule in `redesign.css` has the same problem on other pages with long one-word titles.
- The city solar estimate moved from above the controls to after the list and pagination (contracts high, spec §1). Restyled as a heading with an inked sun vignette, no card.
- `/sahkosopimus/paikkakunnat`: the per-town number is the postcode count (live: "N postinumeroa"); the board shows only the number, so one caption under the count explains it and each number has an sr-only "postinumeroa". The four map pins are links to those city pages.

## 2026-10-16 — Detail page (round 2, area "detail")

- The contract band hero has no text; the fox is painted into `contract-band-<season>` art at the far right. Contract name, price and seller sit below the band. `contract-band` is light in kevät and kesä.
- The Preferred Sources strip follows the contract header (the page's primary header), as on board 39Y-0.
- The bill module stays open by default (recorded product decision in `app/Livewire/AGENTS.md`); the board draws it closed.
- The chart's contract series is the fox line and the reference a dashed granite line (board), replacing the old "coral is never a data series" rule from the previous design system.
- Seller-behaviour facts beside the chart stay the presenter's sentences; the board's split "25 / kertaa muutettu …" figure needs structured data that the presenter does not return.

## 2026-10-16 — Round 2 tools (manager summary)

`/sahkosopimus/laskuri` and `/maksatko-liikaa` use `x-site.tool-hero` (540px, fox in the plate). The bill form is drawn as a torn paper bill (spec "Tool pages" proposal; the user has not confirmed it yet). Presets and bill periods carry radio marks (controls must look like controls) although the board draws tabs. The top bar no longer marks "Sähkösopimukset" active on `/sahkosopimus/laskuri`.

## 2026-10-16 — Round 2 company (manager summary)

- `x-site.company-hero` (`list` 600px street scene, `band` 460px farmland with the text column on the right so it is far from the fox). The Preferred Sources strip stays directly after the hero, although the board puts the company facts first (project rule wins).
- Household entries on the company page render without rank and logo (`showRank=false`), as on the board; business contracts use `x-site.company-compact-entry` (same presenter view model).
- Market range rows have no shared € axis: each row has its own p20–p80 domain, so a shared axis would be false.
- Logos with empty margins: `redesign-company.css` crops 9 measured logo files with `object-view-box`. Temporary; the permanent fix is to trim margins in the logo pipeline. Re-measure or remove a crop line when a logo file changes.
- Emission figures are ink (aurora at 0), not traffic-light colours.

## 2026-10-16 — Round 2 energy (/aurinkopaneelit/laskuri, /lampopumput/laskuri)

- Own hero component `x-site.energy-hero` (art `solar-hero` 660px / `heatpump-hero` 540px): the fox is painted into these plates, the shade falls from the left (Paper "Sky shade", per season tone) and the solar hero holds the address search, so only the art layer clips. Phone: text on the tone ground, the scene as a band below (as on /spot-price), placed so the painted fox stays in view. Both heroes are light in kevät/kesä (`Season::LIGHT_HEROES`).
- The solar address field keeps the board's boxed look (white 86%, 2px ink, radius 14): it is the one input an estimate needs and must read as a control on the painted sky. All other fields are underlined (`x-site.energy-field`).
- Heat pump savings use `--color-saving` and an extra cost `--color-price-high` as the board and spec §2 show; the older "neutral slate deltas" rule in `app/Livewire/AGENTS.md` was replaced.
- The solar monthly chart puts a value on every bar (the old hover tooltips are gone) and switches to one row per month below 640px. The board's "kesällä jopa 10× enemmän kuin talvella" note (text from the live "Tietoa laskurista" list) shows only on a laptop, next to the best month.
- Board-only notes ("AKSELIT", "Avattuna: …", the option lists under the selects) are documentation of the board, not page copy; the real axis labels, advanced panel and select options render instead.

## 2026-10-16 — Round 2 data (/sahkosopimus/tilastot, /sahkosopimus/sahkon-hintaennuste)

- Shared chart renderer `resources/js/contract-price-statistics.js` now draws every `data-line-chart` with the ink skin by default (manager request: home, company and statistics charts must agree). Series colour follows the label, not the series index: category colours, Toistaiseksi dashed ink-muted, "Markkinan mediaani" dashed granite, `leadStroke` pins series 0 (old coral `#f97316` → fox). `data-chart-skin="legacy"` opts out. Blade legends on the home, company and article pages still copy the old `NON_LEAD_STYLES` values and must be changed to mirror `inkSeriesStyle()` by their owners.
- Board deviations: Preferred Sources strip directly after the hero (root rule) instead of after the controls; caption quotes have no copy action (the live page has none; copy stays on the deep-dive quotes); forecast change colours follow the saved outlook tone, not the sign of a tiny change; all deep-dive panels stay visible (no tabs), as on the live page.
- Local data: `forecasting:run-fixed-contracts --as-of=2026-10-15` was run against the local SQLite database only, because the local database had no rows for the current model and the forecast page showed its empty state.

## 2026-10-16 — Round 2 integration (manager)

- Full PHPUnit: 2 995 tests. After one test fix (`ContractHistoryPresenterTest` regex allows the new date `<span>`), the only failures are the date-dependent `ContractDetailPresenterTest::test_a_market_reset_…` (also fails on unchanged HEAD) and `LayoutAssetLoadingTest` while `public/hot` exists (passes with it moved aside). `npm run build` passes. Pint findings in changed files equal those in HEAD.
- Browser sweep of 23 routes at 390 and 1440: no horizontal scroll, no page errors, hero + top bar mode correct (articles use the night band by design).
- Shared `.vk-hero-h1` uses `overflow-wrap: anywhere` (never clip a long Finnish compound). `hyphens: auto` was tried and removed: the browser broke words at wrong points.
- `x-ink.series-line` draws chart legend swatches with the label rules of `inkSeriesStyle()`; home, company and article legends use it, so legends and canvas lines cannot drift.

## 2026-10-16 — Round 3 Preferred Sources strip

- The strip is redesigned (Paper "R3 · Strip" boards, user approved): notice-board villagers (art `noticeboard`, fragment `scripts/design-art/strip.sh`, 560 and 1120 wide, not lazy) beside the title, text and a pinned paper-note button with the bill-form torn edge (CSS `::after` data-URI, a second one for hover). User change: no kicker ("Kylän ilmoitustaulu" is not visible copy). Every page already rendered it directly after its hero except the contract page, which the detail owner moves under the band. `PreferredSourceActionStripTest` now checks with the DOM that the strip directly follows the hero `<section>`.
- Compact version (user: the strip took too much height; boards IEC-0/IMD-0/IN8-0 updated): desktop one centred row (art 384 × 150, title one line, note), 155px; below 1024 no art and no download (`<picture>` with a `(min-width: 1024px)` source over a 1×1 data-URI img), 193px at 390. Between 1024 and 1279 the gaps are 32px and the art shrinks (the one-line title sets the text column's minimum width), so nothing overflows.
- The strip now has 32px (48px desktop) below its edge line; pages whose next block starts with its own top rule (statistics controls) show two rules 64px apart. Leave it to the data/detail page owners when they rework section spacing.

## 2026-10-16 — Round 3 contract page (section rhythm + paper bill)

- Built from Paper boards HYA-0, IND-0, J0P-0 and notes J9K-0 (user approved, including the new copy "Nykyinen sähkölaskusi", "tällä yhdellä laskutusjaksolla." and the "Tulos näkyy tässä" guide). The strip moved under the painted band, before the breadcrumb; the breadcrumb block adds no top space because the strip owns the space below its edge line.
- Every section after the header is `.vk-detail-section`: 1.5 px edge rule, 48 px (phone 32) to the heading, 28 px (phone 32) between blocks; sections 64 px (phone 48) apart. The old `--ruled` modifier is gone. Hintatiedot → vs. pörssisähkö 40 px and chart → timeline 52 px come from small offsets on those blocks.
- The bill module is a normal always-open section. The shared partial got an opt-in `paper` layout (the `/maksatko-liikaa` bill classes); the listing keeps the compact default layout. The paper layout shows the dates for every period choice. The big figure uses `delta` with two decimals; `delta_label` stays the screen-reader sentence (its whole-euro rounding is unchanged).

## 2026-10-16 — Round 3 data pages (statistics + forecast)

- Built from Paper boards IF1-0, JEW-0, JQB-0, JST-0 and notes JVC-0 (user approved). Statistics: "Tarkemmin sopimustyypeittäin" is two 584px cells per row (64 column gap, 88 row gap), one column below 1024; Pörssisähkö is cell 1 with its margin chart in place of the Vs. row. Forecast: one full-width row per term, conclusion first.
- Chart end labels read the key figure through `data-chart-end` (presentation only), because a weekly chart's last point can be an average that rounds differently (live: 11,80 beside a key figure of 11,81).
- Rhythm: 96px between sections (64px phone); 56px (48px phone) from the strip's edge line to the first block. The statistics controls lost their top rule so only the strip line shows.

## 2026-10-16 — Open Graph share images

- User: Voltikka had no `og:image`. Every page now has a 1200 × 630 JPEG share image and the `twitter:card` `summary_large_image` tags (Paper board "R3 · OG images"). The image is the page's own painted hero for this season, scaled to 1200 wide and centre-cropped, with the Voltikka wordmark on a paper tag (torn bottom edge) in the upper-left sky. No price, contract or seller text in the image; the title comes from `og:title`.
- One mechanism: pages pass `ogArt` beside `heroTone`; `Season::ogImage()` falls back to the home hero of the season, then `og/default.jpg`. Pages without own art (tietoa, legal, articles, locations map, 404) use the home hero.
- JPEG, not WebP, for crawler support; quality 72 keeps each file under 200 KB (76 files plus the default, about 11 MB). The 404 art was left out (nobody shares a 404 page). The source is the built 1672 WebP hero, not the PNG, so the art name list in `og.sh` needs no second source mapping; the downscale hides the WebP compression.
- The alt text is fixed ("Voltikka-logo ja piirretty suomalainen maisema"), because the page title of a contract page contains a price that the image does not show.

## 2026-10-16 — Hero crops on wide and narrow screens

- User: on `/sahkosopimus/tilastot` the crop hid the main subject. Cause: full-bleed plates use `width: max(100%, 1440px)` and are anchored at the bottom, so above 1440 the extra plate height was cut from the top, through the subject (statistics fox headless at 1920, gone at 2560). The pörssisähkö, city, solar, laskuri, maksatko and heat-pump heroes had the same fault in a smaller degree.
- Rule: a full-bleed plate wider than 1440 grows around a focus line (`--plate-focus` / `--seo-plate-focus`, a share of the plate height at the subject's head) that keeps its 1440 position. At 1440 the formulas give exactly the Paper crop. Values are measured on the plate art; all four seasons share one composition.
- Below 1440 the heat-pump plate keeps its right edge and the seller band keeps its left edge (centred, the fox / the walking fox fell off the edge at 1024).
- Checked at 390, 1024, 1440, 1920 and 2560 in all four seasons for every hero page. Home, index, cheapest, listing, companies, contract band, spot, long-read and 404 heroes were already right and are unchanged.
