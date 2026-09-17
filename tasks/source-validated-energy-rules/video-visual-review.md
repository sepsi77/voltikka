# WeeklyOffers local visual review — 2026-09-16

## Result

The bounded synthetic visual check is complete. The three settled offer cards show the required price and benefit labels without visible clipping. A transition defect remains: the promo and sign-off scenes show a complete first frame, then disappear on the next frame before their entrance animation. This is not release approval.

No application or Remotion source, package manifest, existing task status or context file was changed by this review. This new report is the only repository file added by this unit. No production API, logo URL, Railway, paid LLM or social service was used. No install or browser download ran.

## Manager inspection: full-resolution PNGs

All files are 1080 × 1920. Each has a small `SYNTHETIC QA · NOT REAL OFFERS` marker added by the private test wrapper.

1. `/tmp/voltikka-weekly-visual-qa/frame-145.png` — exact fee benefit.
2. `/tmp/voltikka-weekly-visual-qa/frame-265.png` — estimated normal-price benefit and long Finnish names.
3. `/tmp/voltikka-weekly-visual-qa/frame-385.png` — short Hybrid, annualized comparison total and real-term benefit.
4. `/tmp/voltikka-weekly-visual-qa/frame-213.png` — offer entrance transition; estimate warning is visible with the benefit.

Additional defect evidence: `frame-435.png` / `frame-436.png` and `frame-510.png` / `frame-511.png` in the same directory.

## Method and commands

Private files are in `/tmp/voltikka-weekly-visual-qa/`: `entry.tsx`, `render.cjs`, `qa.css`, `postcss.config.mjs`, `public/`, `bundle/`, logs and PNGs. The entry imports the current `remotion/src/compositions/WeeklyOffers/index.tsx` directly. It does not import `Root.tsx` or run its API-fetching `calculateMetadata`. Composition settings match Root: 1080 × 1920, 30 fps, 570 frames.

The fixture uses `CanonicalContractOffer` and `WeeklyOffersProps`. The explicit short-term basis is the existing `annualized_contract_term` literal. No PHP output was fetched. This review does not verify the separate PHP weekly-basis repair.

Public font acquisition only:

```sh
curl --fail --silent --show-error \
  'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap' \
  -o /tmp/voltikka-weekly-visual-qa/google-fonts.css
node /tmp/voltikka-weekly-visual-qa/prepare.cjs
```

`prepare.cjs` fetched the seven normal-weight 200–800 TTF URLs explicitly returned by that CSS, from `fonts.gstatic.com`, into the private cache. No other remote assets were fetched. The render CSS preserves the current colors, spacing, typography and utility classes. Its remote Google import is removed. A private `FontFace` loader uses `staticFile()` and holds `delayRender` until all seven faces load. `font-black` keeps the current CSS weight request; no new 900 font is introduced.

The installed Tailwind PostCSS plugin is supplied explicitly in the private webpack override. This is needed because the temporary entry is outside the repository config directory. No design rule was changed.

Successful final render command:

```sh
sandbox-exec -p '(version 1) (allow default) (deny network*) (allow network* (local ip "localhost:*") (remote ip "localhost:*") (local unix-socket) (remote unix-socket))' \
  node /tmp/voltikka-weekly-visual-qa/render.cjs \
  > /tmp/voltikka-weekly-visual-qa/render.log 2>&1
```

The sandbox denies external network traffic for Node and Chrome. Loopback serves the private bundle/fonts; local Unix sockets are required for Chrome startup. The fixture has null logo URLs and no fetch calls. The explicit executable is `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`. Browser download requests throw an error.

Type check passed with no diagnostics:

```sh
/Users/seppo/code/voltikka/remotion/node_modules/.bin/tsc --noEmit \
  --jsx react-jsx --esModuleInterop --moduleResolution node --target ES2020 --skipLibCheck \
  /tmp/voltikka-weekly-visual-qa/entry.tsx
```

The final render exited successfully. `render.log` and `browser-log.json` contain render/font/layout diagnostics. `verification.json` records PNG dimensions and text-node bounds. Font checks report true at all 13 sampled frames, after the explicit font-load wait. The read tool was used to inspect the final images, not only DOM output.

Private setup attempts first failed on redundant CSS font URL resolution and Chrome's blocked Unix socket. A subsequent preliminary render lacked Tailwind utilities because the private entry did not pick up PostCSS configuration. These were test-harness faults, not product defects. The explicit PostCSS override corrected this. The final run overwrote those preliminary PNGs; only the correctly styled final images support this report.

## Synthetic data and coverage

| Frames | Synthetic case | Visible result |
| --- | --- | --- |
| 145, 194 | Exact €24 fee benefit over 12 months; townhouse total €520 | `mitattu etu / 12 kk`, `ETU 24 € / 12 KK`; actual-price heading has no estimate prefix. |
| 195, 213, 265 | Exact actual total €520, estimated normal-price benefit €125 over 12 months | Settled frame 265 shows `arvioitu säästö`, `ARVIOITU ETU 125 € / 12 KK`, and `Normaalihinta voi muuttua. Säästö ei ole taattu.` Actual-price heading remains separate and exact. |
| 385 | Six-month Hybrid; annualized total €560, annual-equivalent comparison saving €60, real customer benefit €30 over six months | `ARVIO · VUOSITASOLLE MUUNNETTU VERTAILUHINTA`; hero and badge retain €30 / 6 kk, not €60 / 12 kk. |
| 0, 435, 436, 490, 510, 511, 565 | Title, promo, sign-off and scene boundaries | Settled text fits; first-frame flash defect described below. |

The long-name case uses `Pohjois-Suomen Sähköenergian Yhteishankinta Oy` and `Pohjoissuomalainen uusiutuvan energian kotitaloussähkösopimus 12 kk`. The company uses two lines and the product uses three. Both fit. The estimate warning, all three consumption tiers and the lower benefit badge remain inside the canvas. No settled text-node bounds exceed the canvas in the diagnostics. This is bounded evidence, not proof for arbitrary-length names or amounts.

## Visual defects and limits

1. **Complete scene flashes before entrance.** Global frame 435 (14.500 s) shows the complete promo; frame 436 (14.533 s) is blank except for the QA marker. Global frame 510 (17.000 s) shows the complete sign-off; frame 511 (17.033 s) returns to the light blank background. Cause: `PromoScene.tsx:31–35` and `SignOffScene.tsx:24–28` treat their local frame zero as a thumbnail and force their springs to one. Their next local frame resets the animation to zero. `WeeklyOffers/index.tsx:66,73` mounts each in a Sequence, where local frame zero occurs during the actual video. No source fix was made.
2. **Blank card boundary, not an overlap transition.** Frame 194 shows the settled first card; frame 195 is blank. `OffersCarousel.tsx:66–67` ends one Sequence before the next starts, while `OfferCard.tsx:169` applies an entrance opacity of zero. Frame 213 shows the benefit and its estimate warning together, before the delayed name/pricing/badge appear. The lower third starts outside the canvas while hidden; these transition bounds are expected from its entrance, not settled clipping.
3. **Readability limit.** The 26 px warning and muted price-basis heading fit at full resolution. Phone-size reading time, platform overlays and compression were not tested. Three offers give four seconds each; a larger offer count shortens the reading interval and was not tested.

No full video, audio, legacy/null-price variant, real logo, live API transport, producer calculation or complete frame-by-frame animation review is claimed. The static normal font assets came from the production Google Fonts CSS URL; this verifies the loaded family and tested fit, not every browser-specific Google font response. The manager must review these results and the separate PHP repair before any release decision.

## Authorized local corrections and repeat check — 2026-09-16

Both requested corrections are complete locally. `PromoScene.tsx` and `SignOffScene.tsx` no longer force springs to one at Sequence-local frame zero. Existing spring settings, frame offset and delays are unchanged. The global TitleScene thumbnail and card boundaries are unchanged. `OfferCard.tsx` now shows `Ei sisällä kulutusvaikutusta` below the price heading only for canonical `base_only_hybrid` offers. It uses 26 px semibold text in `#cbd5e1` on the existing dark card. No financial calculation, amount, benefit label, annualized heading or legacy branch changed.

The original PNGs and harness remain intact. The repeat harness is `/tmp/voltikka-weekly-visual-qa-after/`. It reuses the cached local fonts and public assets. Its private fixture corrects the short Hybrid's offer and consumption comparability from `comparable` to `base_only_hybrid`. The original fixture did not contain that authoritative value. No new payload field was added. The repeat render still imports WeeklyOffers directly and uses explicit local Chrome with external network denied.

Verification commands and actual results:

- `cd remotion && npm run lint && npm run build`: passed, exit 0. Build emitted only a Node `module.register()` deprecation warning.
- `remotion/node_modules/.bin/tsc --noEmit --jsx react-jsx --esModuleInterop --moduleResolution node --target ES2020 --skipLibCheck /tmp/voltikka-weekly-visual-qa-after/entry.tsx`: passed, no diagnostics.
- The same `sandbox-exec` policy shown above, with `node /tmp/voltikka-weekly-visual-qa-after/render.cjs`: passed; 12 full-resolution PNGs rendered. `render.log` and `browser-log.json` are in the repeat directory.
- `python3 /tmp/voltikka-weekly-visual-qa-after/verify.py`: passed. All 12 PNGs are 1080 × 1920; all font checks are true. Settled text-node bounds at 265, 385, 490 and 565 fit the canvas without horizontal overflow. The Hybrid disclosure is present at 385 and absent at non-Hybrid 265. Its element bounds are x=80, y=896.375, width=920, height=39. The estimated-benefit warning remains present at 265. Results are saved in `verification.json`.
- No Remotion test files or test script were found. No test framework or dependency was added. The private repeat-check script supplies bounded assertions instead; no PHP tests were changed.
- `git diff --check`: passed. Final diff and status were reviewed. Package files and TitleScene have no Git diff. Existing unrelated edits, including the prior OfferCard estimate-label changes and types changes, remain intact.

The read tool was used to inspect full-resolution frames 265, 385, 435, 436, 450, 490, 510, 511, 525 and 565. Frame 385 shows the new disclosure clearly, the unchanged `ARVIO` annualized heading, €560 comparison total, and €30 / 6 kk benefit. Frame 265 retains all estimate wording and long-name fit. The settled promo and sign-off fit without visible clipping.

Both boundary pairs (435/436 and 510/511) now show the empty light entrance state, not a complete scene followed by a blank frame. Later samples 450 and 525 show partial entrances before settled 490 and 565. This confirms the corrected start progresses from empty to visible instead of flashing. The retained spring bounce is not a claim of mathematical monotonicity throughout the whole scene. Hidden lower-third text bounds outside the canvas at entrance are expected.

Full-resolution PNG paths:

- `/tmp/voltikka-weekly-visual-qa-after/frame-265.png` — estimated benefit.
- `/tmp/voltikka-weekly-visual-qa-after/frame-385.png` — Hybrid disclosure.
- `/tmp/voltikka-weekly-visual-qa-after/frame-435.png`
- `/tmp/voltikka-weekly-visual-qa-after/frame-436.png`
- `/tmp/voltikka-weekly-visual-qa-after/frame-437.png`
- `/tmp/voltikka-weekly-visual-qa-after/frame-450.png` — partial promo entrance.
- `/tmp/voltikka-weekly-visual-qa-after/frame-490.png` — settled promo.
- `/tmp/voltikka-weekly-visual-qa-after/frame-510.png`
- `/tmp/voltikka-weekly-visual-qa-after/frame-511.png`
- `/tmp/voltikka-weekly-visual-qa-after/frame-512.png`
- `/tmp/voltikka-weekly-visual-qa-after/frame-525.png` — partial sign-off entrance.
- `/tmp/voltikka-weekly-visual-qa-after/frame-565.png` — settled sign-off.

Limits remain: no full video/audio release check, phone-size reading-time test, platform overlay/compression check, live API, real logos, legacy/null-price render or complete frame-by-frame review. No production, API, Railway, social, LLM, install, commit or push operation ran. Shared context and task-status consolidation remain manager-owned. These results are not full video release approval.
