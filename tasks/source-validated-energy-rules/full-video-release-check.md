# Full local WeeklyOffers video check — 2026-09-16

## Result for the manager

**Full local render and playback are complete. The no-flash release gate does not pass.**
The encoded video still shows a complete title at frame 0, then an empty entrance at frame 1.
All five later scene/card boundaries also cut to an empty or almost empty entrance.
The earlier Promo/SignOff complete-first-frame defect is fixed, but that does not remove these cuts.
No application source correction was made. Manager approval is required before a correction.

This report does not approve default-V4 deployment, V5 activation, or posting. Other financial
release gates remain manager-owned. Existing shared reports and status files were not changed.

## Exact evidence

Private directory: `/tmp/voltikka-weekly-full-release/`.

- `synthetic-weekly.mp4`: full H.264 render, 1080 × 1920, 30 fps, **570 encoded and decoded video
  frames**, video duration **19.000000 s**, file size **4,132,662 bytes**.
- `entry.tsx`, `qa.css`, `render.cjs`, `bundle/`, `composition.json`: private render inputs.
  The entry imports the actual WeeklyOffers composition, not Root.tsx. It uses the previous
  corrected synthetic fixture, including authoritative `base_only_hybrid`.
- `render.log`: ends with `PROGRESS 570 570` and `FULL_RENDER_DONE`. `browser-log.ndjson`
  retains browser diagnostics. The Node module-register deprecation warning is not a render error.
- `ffprobe.json`: full stream/container inspection and counted decoded frames.
- `phone/frame-000.png` through `phone/frame-569.png`: **all 570 decoded frames**, each 360 × 640.
- `overview.png`: 38 chronological samples, every 15 frames. Its last two black tiles are unused
  contact-sheet cells, not video frames.
- `boundary-{0,75,195,315,435,510}.png`: all six entrance/boundary strips, inspected visually.
  Initial strip frames: 0, 1, 2, 3, 10, 30. Other strips: boundary−1, boundary, +1, +2, +8, +25.
- `analyze.py`, `frame-analysis.json`, `frames.gray`: every-frame pixel analysis and font/layout checks.
- `playback.cjs`, `playback.json`, `playback.log`: installed Chrome played the complete local MP4
  at normal speed in a **360 × 640 viewport**, through the `ended` event. Elapsed time: 19,230.5 ms.
  Chrome reported 570 total frames, 568 frame callbacks, and **2 dropped playback frames**.
  This is not a claim of zero-drop playback. The independent file decode found all 570 frames.
- `audio-check.txt`: the renderer added an AAC stereo track, but all **3,657,728 decoded PCM bytes
  are zero**. The composition contains no authored audio. AAC padding gives the container/browser
  duration 19.050667 s. No speech, music or audio-content check is needed for this silent composition.

## Method and commands

The harness reuses already cached local fonts and installed dependencies/Chrome. It has null logo
URLs and an explicit synthetic marker. No application kernel, calculateMetadata API fetch, new
export, production API, LLM, dependency install, browser download or social posting ran.

Render and browser playback used this process-level sandbox, inherited by Chrome:

```sh
sandbox-exec -p '(version 1) (allow default) (deny network*) (allow network* (local ip "localhost:*") (remote ip "localhost:*") (local unix-socket) (remote unix-socket))' \
  node /tmp/voltikka-weekly-full-release/render.cjs
# Same sandbox with playback.cjs for normal-speed local MP4 playback.
```

Loopback only serves the private bundle, fonts and MP4. Browser-download callbacks throw.
The full private render bundles current source with the established Tailwind override; it does not
change source CSS. H.264 settings are CRF 18 and requested yuv420p; ffprobe reports full-range
`yuvj420p`. This is local encoding evidence, not social-platform recompression evidence.

Other completed checks:

```sh
# External network denied for each check.
remotion/node_modules/.bin/tsc --noEmit --jsx react-jsx --esModuleInterop \
  --moduleResolution node --target ES2020 --skipLibCheck \
  /tmp/voltikka-weekly-full-release/entry.tsx
ffmpeg -v error -xerror -i /tmp/voltikka-weekly-full-release/synthetic-weekly.mp4 -f null -
ffprobe -v error -count_frames -show_streams -show_format -of json \
  /tmp/voltikka-weekly-full-release/synthetic-weekly.mp4
python3 /tmp/voltikka-weekly-full-release/analyze.py
```

TypeScript and strict full decode passed. Every rendered frame reported the loaded font.
There were **zero out-of-canvas or horizontal-overflow findings in the logged text nodes across
195 settled frames**: 60–74, 145–194, 265–314, 385–434, 490–509, 560–569.
The inherited logger covers single-text-child h2/span/div nodes, not every possible DOM text node
or opacity state. Hidden entrance lower-thirds can be outside the canvas by design. Pixel review
supplements, but does not turn these bounds into universal clipping proof.

## Visual findings and concrete corrections to consider

### 1. Initial complete-title flash — definite blocker for the requested no-flash gate

`boundary-0.png`, and full decoded `phone/frame-000.png` / `frame-001.png`, show the full title,
date, badge, count and footer, followed by the almost blank page. Source:
`remotion/src/compositions/WeeklyOffers/TitleScene.tsx`, `isThumbnailFrame = frame === 0`, in
both TitleScene and its LowerThird. The full movie includes that thumbnail-only frame.

Suggested narrow correction, **not implemented**: separate still-thumbnail state from normal
video playback. Preserve the thumbnail deliberately without forcing global video frame 0 to the
settled state. This needs manager approval because the previous bounded repair explicitly kept
TitleScene unchanged.

### 2. Empty cuts at every later scene/card boundary — no-blank-flash gate not met

| Boundary | Time | Result |
| --- | --- | --- |
| 74 → 75 | 2.500 s | Settled title disappears; first card starts empty. |
| 194 → 195 | 6.500 s | Settled exact card disappears; estimated card starts empty. |
| 314 → 315 | 10.500 s | Settled estimated card disappears; Hybrid starts empty. |
| 434 → 435 | 14.500 s | Settled Hybrid disappears; promo starts empty. |
| 509 → 510 | 17.000 s | Settled promo disappears; sign-off starts light/empty, then darkens. |

The all-frame detector crops off the QA marker and counts pixels below gray 200. Less than
0.01% dark pixels identifies **near-blank**, not necessarily mathematically empty frames:
1–3, 75–82, 195–202, 315–322, 435–444, 510–512. The boundary images confirm the visual cuts.
These are composition entrances, not failed fetches or missing decoded frames.

The old Promo/SignOff *complete first frame followed by empty frame* is absent. Their entrances
now progress correctly from empty. Removing an opening flash and avoiding an empty cut are
separate requirements. If the manager requires no empty cuts, retain outgoing content until incoming
content is visible, or use a nonempty entrance state. Do not reintroduce the local-frame-zero
thumbnail shortcut. Timing/overlap changes require approval and a complete repeat render.

### 3. Phone-size fit passes for tested cards; full reading-time approval does not

The 360 × 640 decoded frames 145, 265 and 385 were inspected directly. The long company and
contract names fit in two and three lines. No tested settled card visibly clips or overlaps.
Actual-price and benefit certainty remain separate:

- Exact card: €24 / 12 kk; €520 comparison total.
- Estimated normal benefit: `arvioitu säästö`, `ARVIOITU ETU 125 € / 12 KK`, and
  `Normaalihinta voi muuttua. Säästö ei ole taattu.` The actual-price heading stays exact.
- Six-month Hybrid: annualized estimate heading and `Ei sisällä kulutusvaikutusta`; €560
  annualized comparison total; **€30 / 6 kk**, not the synthetic €60 annual-equivalent saving.

At this phone size, the 26 px warning, Hybrid exclusion and price-basis heading scale to **8.67 px**.
The 24 px consumption labels scale to 8 px. Main amounts and names are readable; these important
qualifiers are much smaller and the muted price-basis heading is weak. Each card has only four
seconds, with staggered entrances; the last lower-third starts 1.4 seconds into the card.
Thus, fit alone is not adequate evidence that a visitor can read every qualifier at normal speed.
I do not give a phone reading-time pass. Manager review should consider larger important qualifiers
and sufficient settled time. No user reading study or physical-device test is claimed.

## Separate saved-real-data text check

Input remained unchanged:
`/tmp/voltikka-energy-preflight-20260916-a/audit-fixes-final-candidate.json`.
Its before/after SHA-256 check passed (`candidate-before.sha256`). No SQLite database was opened.

`real-text-check.php` loads Composer's autoloader only, not the Laravel application kernel.
It runs the real `CanonicalOfferFacts` helper against saved economic fields. External network and
writes to the repository and sealed preflight directory are denied. Output is private
`real-text-check.json`. No price is recalculated or invented.

Important adapter limit: the saved audit projection omits `phase_breakdown.label`; it is not a full
calculated-cost/API transport payload. A direct strict hydration attempt rejects that omission.
The private helper adapter therefore omits the phase-breakdown records, which this offer helper
never reads. All totals, offer terms, benefit amounts, term facts and estimate facts stay unchanged.
This is a scoped real-term/public-copy check, **not an end-to-end WeeklyOffers feed test**.

- 375 saved rows checked at each of 2000, 5000 cold, 5000 warm and **18000 kWh**.
- Each phase gives 45 public offer-helper outputs. These include business rows and are **not**
  the household-only, integrity-filtered, one-per-company WeeklyOffers selection.
- Each output matches the stored real-term saving and months for short terms, otherwise the stored
  comparison saving and 12-month basis. Estimate flag/notice assertions pass. Cold/warm outputs match.
- Vaasa six-month household examples retain **€5.90 / 6 months**, separately from €11.80 annual-equivalent
  saving. Aalto Huoleton retains €5.95; Cheap SUPERDIILI retains €17.80 and its four-month fee term.
- No accepted saved example has `benefit_is_estimate=true`; estimated-benefit visual coverage remains
  synthetic. The saved file contains **no 10000 kWh calculation**. The 18000 rows were never renamed,
  interpolated or used as the WeeklyOffers house tier. Existing three-exact-consumption service tests
  remain the separate evidence for that path; they were not rerun in this unit.

One additional strict-reader issue is recorded for manager investigation, not silently repaired:
`bdfd4u-keravan-energia-oy-perusfiksu-24kk` is saved as listed with `base_only_hybrid` and
`estimate_method=forward_curve_spot`. Current `ContractPricingViewData::fromArray()` rejects that
combination at line 217. It occurs in every saved phase. This row has no offer, no offer terms and
zero discount saving, so this is **not evidence of a wrong displayed WeeklyOffers benefit**.
The 45 helper checks exclude the rejected row. Do not claim complete saved-payload transport
acceptance until the manager resolves this mismatch. No comparability or method was invented to
make it pass.

## Preservation and remaining limits

Only this new report was added to the repository by this unit. No existing source/report/status,
package, sealed JSON, database or prior QA artifact was edited. The private harness references
cached font files read-only. Concurrent work added two unrelated test files between status captures;
this unit did not modify them. No commit, push, production change or V5 activation occurred.

The full MP4, every-frame decode/metrics and normal-speed browser playback are now checked.
Visual inspection covered the chronological overview, every boundary strip and settled phone cards;
it was not a manual inspection of each of the 570 full-resolution frames. Synthetic data does not
prove live provider/logo availability or economic correctness. Platform overlays, actual social
recompression and final posted presentation remain **post-release checks requiring separately
authorized posting**. They are neither claimed here nor reasons to perform posting during local QA.
