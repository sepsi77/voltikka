# WeeklyOffers release corrections — 2026-09-16

## Result

The approved local corrections and full offline checks are complete. The title reset and detected
near-empty cuts are removed. This is **not general release approval**. The manager must review the
short text overlap during crossfades. No production, provider, API, posting or installation ran.

## Changes

- `TitleScene.tsx`: the complete title, date, count and footer stay visible from frame zero.
  Removed thumbnail-only conditions and title entry springs. The background pattern stays.
- `timing.ts`, `index.tsx`, `Root.tsx`: one shared 25-second duration. At 30 fps the title starts at
  0, carousel at 75, promo at 615, and sign-off at 690. The composition ends at 750.
  Root changes only the WeeklyOffers import/duration. Its API metadata behavior is unchanged.
- `OffersCarousel.tsx`, `SceneFade.tsx`: the carousel receives 540 frames. Three offers each get
  180 frames. Each outgoing section/card stays for 15 more frames while the entire incoming scene
  fades in with its existing spring entrance. The last card stays through frame 629. Overlap does
  not subtract time or add time to the 750-frame composition. All input offers remain in order;
  no truncation was added. The last card also receives any division remainder.
- `OfferCard.tsx`: headings, warning, Hybrid exclusion, housing and kWh labels are at least 36 source
  pixels, or 12 pixels at 360 wide. Warning/benefit subtext is dark on coral; price-panel qualifiers
  are light on dark. The annualized heading says `Vuositasolle laskettu vertailuhinta`.
  Actual-price and normal-benefit estimate flags, amounts, terms and ordering are unchanged.
- `remotion/AGENTS.md` and its existing `CLAUDE.md` symlink record these constraints.

Existing PromoScene, SignOffScene, types, PHP and Vite changes were preserved. No DailySpot source
was changed. Search found no other old WeeklyOffers duration in Remotion source/README or the
WeeklyOffers service. The DailySpot 14.5-second comment is unrelated and remains unchanged.

## Final private evidence

**Directory: `/tmp/voltikka-weekly-release-fixes-final/`** (mode 0700).

The existing `/tmp/voltikka-weekly-full-release/` evidence was not changed. A first correction
render in `/tmp/voltikka-weekly-release-fixes/` is also preserved. It predates the restored background
pattern and final label contrast/size changes. Only the `-final` directory proves the final source.
`source.sha256` matches every WeeklyOffers source file and Root after rendering.

The final harness copies the prior synthetic fixture and uses the exported duration constant,
not the old 570-frame value. It imports the actual composition directly, with no calculateMetadata
API call. It uses cached local fonts, null logo URLs, installed dependencies and installed Chrome.
Browser download callbacks throw. Bundle/render and playback used the inherited process sandbox:

```sh
sandbox-exec -p '(version 1) (allow default) (deny network*) (allow network* (local ip "localhost:*") (remote ip "localhost:*") (local unix-socket) (remote unix-socket))' COMMAND
```

### Commands and actual results

All Node commands below used that loopback-only sandbox.

- `cd remotion && npm run lint && npm run build`: passed, exit 0. ESLint and TypeScript passed.
  The build reports a Node `module.register()` deprecation warning, not an error.
- `remotion/node_modules/.bin/tsc --noEmit --jsx react-jsx --esModuleInterop --moduleResolution node --target ES2020 --skipLibCheck /tmp/voltikka-weekly-release-fixes-final/entry.tsx`:
  passed, exit 0.
- `node /tmp/voltikka-weekly-release-fixes-final/render.cjs`: passed. Log ends with
  `PROGRESS 750 750` and `FULL_RENDER_DONE`.
- `ffmpeg -v error -xerror -i .../synthetic-weekly.mp4 -f null -`: strict full decode passed.
- `ffprobe -v error -count_frames -show_streams -show_format -of json .../synthetic-weekly.mp4`:
  **750 decoded frames, 1080 × 1920, 30 fps, 25.000000 seconds**, H.264 `yuvj420p`, 4,803,851 bytes.
- `ffmpeg` decoded all 750 frames to `phone/frame-000.png` through `frame-749.png`, at 360 × 640.
  A separate crop/scale/gray decode produced `frames.gray` for every-frame analysis.
- `python3 .../analyze.py`: passed assertions. All 750 frames report loaded local fonts.
  **Zero near-blank frames** with the prior detector: crop away the QA marker, then require less
  than 0.01% of pixels below gray 200. **Zero logged text overflow findings across 360 settled
  frames**. Settled ranges exclude hidden premount nodes and entrance motion.
- `node .../playback.cjs`: installed Chrome played the MP4 at normal speed in a 360 × 640 viewport
  through `ended=true`. Elapsed 25,227.6 ms; 750 frame callbacks, 750 total video frames,
  **0 reported dropped frames in this run**. This is a browser metric, not a physical-device test.
  Browser duration is 25.045333 seconds because of audio padding.
- Full audio decode: 4,808,704 PCM bytes, all zero. No authored speech/music is present.
- `shasum -a 256 -c .../source.sha256`: all source hashes passed.
- `git diff --check`: passed. Final diff and `git status --short` reviewed.

The all-frame layout logger covers single-text-child h2/span/div nodes, not every DOM text node.
The source establishes the 36-pixel qualifier size. Visual checks supplement the bounds checks;
these checks do not prove universal input fit.

## Visual review and manager image paths

All paths below are under `/tmp/voltikka-weekly-release-fixes-final/`.

- `boundary-0.png`: frames 0, 1, 2, 3, 7, 10, 14, 15, 30. The title and footer stay complete.
- `boundary-75.png`, `boundary-255.png`, `boundary-435.png`, `boundary-615.png`,
  `boundary-690.png`: each contains boundary−1, boundary, +1, +2, +7, +10, +14, +15, +25.
  Each tile is 360 × 640. Every boundary, including the outgoing overlap end, was inspected.
- `phone/frame-145.png`: settled exact card.
- `phone/frame-325.png`: settled long-name card with estimated normal benefit.
- `phone/frame-505.png`: settled short-term Hybrid card.
- `overview.png`: all sections sampled every 30 frames; inspected in chronological order.
- `synthetic-weekly.mp4`, `playback.json`, `frame-analysis.json`, `ffprobe.json`, `render.log`,
  `checks.log`, `audio-check.json`: full local evidence.

The three settled phone cards fit without visible clipping. Long company and contract names wrap
in two and three lines. The warning, comparison heading, consumption labels and Hybrid exclusion
are readable at the inspected phone size. The estimated benefit remains €125 / 12 months with an
exact actual-price heading. The Hybrid remains €30 / 6 months, separately from its €560 annualized
estimated comparison total. Cards now have about four settled seconds after the staggered entry.
This does not claim that every viewer can read all text in that time.

**Crossfade review note:** two texts briefly share positions during a normal crossfade. Company
names overlap around frames 262–265 and 442–445. The promo headline overlaps the fading Hybrid
name around frame 625. The sign-off badge crosses the outgoing promo headline around frame 700.
These are visible in the boundary sheets; they clear by the end of the 15-frame overlap. There is
no settled text collision or near-empty cut. Do not describe this as zero text overlap. The manager
must decide whether this brief crossfade effect is acceptable; this report does not approve it for
posting. No alternate transition design was added without approval.

## Limits

No real-feed, provider/logo, social-compression, platform-overlay, physical-phone, financial-data
or general-release pass is claimed. The old saved-real-data check was not repeated or changed.
No manual inspection of every full-resolution frame is claimed: every frame was decoded and
measured; every boundary and all settled cards were visually inspected. Normal-speed playback was
measured by Chrome through its ended event. No commit, push, deployment or posting occurred.
