# monthly-market-review/ — video engine of the monthly electricity market review

Narrated videos (3–5 min) and Shorts of Voltikan sähkömarkkinakatsaus. This folder is the **shared engine**. Each edition keeps its own sources and outputs in `market-review/editions/<month>/` (user decision 2026-11-08). Decisions and status: `market-review/video.md`. Tools and general rules: `videos/AGENTS.md`. Paper storyboards: page "Video · Markkinakatsaus".

## Where the files are

| Path | What it is | Git |
|---|---|---|
| `videos/monthly-market-review/` (here) | Engine: `lib.js`, `lib.css`, tools below, `music/` (shared Lyria beds) | code yes, `music/` no |
| `market-review/editions/<month>/video/` | Edition sources: `script.mjs` (voice lines, variants, YouTube text), scene HTML (`katsaus.html`, …), `data.js` (export-data.py), `timeline/<variant>.js` (timeline.mjs) | yes |
| `market-review/editions/<month>/work/` | Work files: `audio/vo/<voice>/` voice takes and `check.json`, `audio/<variant>/voice.wav`, `renders/` (silent renders, stills, checks), `archive/` (earlier versions and concepts not published), `storyboard/` | no |
| `market-review/editions/<month>/final/` | Only the files to upload: videos, `.srt`, `-title.txt`, `-description.txt`, thumbnail; Shorts in `final/shorts/` | text yes, media no (`market-review/.gitignore`) |

`edition.mjs` resolves these paths; every tool takes the edition month as its first argument. A variant in `script.mjs` with `final: true` writes its outputs to `final/`, `final: '<sub>'` to `final/<sub>/`, and no `final` to `work/archive/`. Output names are `voltikka-markkinakatsaus-<month>-<variant>.*`. A scene loads the engine and the art with paths relative to its edition folder (`../../../../videos/monthly-market-review/lib.js`, `../../../../design/...`).

## October 2026 variants (`market-review/editions/2026-10/video/`)

| Variant | Scene | Length | Voice | Idea |
|---|---|---|---|---|
| Katsaus | `katsaus.html` | 5:25 (v5) | Gemini 3.8 Flash TTS Kore | Full review, the chosen and published concept (user 2026-11-06). Order: hook, winter, advice for three homes and quarterly contracts, contract market, October in short. Earlier versions in `work/archive/` |
| Kolme kotia | `kotia.html` | about 3:32 | Harper | Consumer advice only (not published) |
| Numerot | `numerot.html` | about 3:30 | Harper | 18 big numbers, one per card (not published) |
| Sähkösää | `saa.html` | about 3:17 | Grant | Weather-forecast format (not published) |

The fox appears only in the opener and the end card, never next to advice, a seller or a contract price. Sellers are named as in the written review (user, 2026-11-06).

## Shorts (from 2026-11-08)

A Short is a variant in the edition's `script.mjs` (name `short-<topic>`) with its own portrait scene `video/short-<topic>.html`. It is not a crop of the long video: the long frames are wide layouts, and a crop cuts the charts and the text. Workflow for each edition, after the long video:

1. **Select.** One idea and one key figure that make sense alone, 25–65 s, from the long video's voice lines (same voice and takes, no new cost). Add a new hook line only if the first line needs context (`sh_*` lines); the first 2 s decide. End with `k_out1` (or a line that points to the full review) and the end card.
2. **Variant.** `...SHORT` (shared settings: Katsaus voice, `size: '1080x1920'`, `final: 'shorts'`, `lead: 0.25`), pauses 0.3–0.7 s. YouTube `TITLE` and `INTRO`; `FOOTER` gives a short footer with `#shorts` for `short-*`.
3. **Scene.** Load `short.css` and `short.js` after `lib.css`/`lib.js`. Keep the layout zones in `short.css`: wordmark in the header, content in y 250–1290, burned-in `captions()` in y 1320–1500, nothing important below 1500 or at the right edge (the player's title, caption and buttons cover them). The first frame shows the hook as a large title (it is also the frame people see while scrolling), and `captions({ skip })` leaves out lines that are already large on the screen. Painted plates appear as a full-width band (`.band`, 1080×608, sharp), never full height (a 1672 px plate at 1920 px height is 2× upscaled and soft). **Next Shorts: explore portrait plates first** (user 2026-11-08, after the October Shorts were published). The band at the top with the text below works, but it looks a bit odd. Generate 9:16 plates (or backgrounds) made for the Short, at least 1080×1920, with calm areas planned for the title and the captions, and compare them with the band layout before you choose. Use the image-gen skill (`.claude/skills/image-gen/`) and the season prompts in `design/seasons-prompts.md`; the Oma lasku ad already has `-9x16-v1.png` portrait plates as a precedent (`design/AGENTS.md`). Keep text readable over the plate (a paper card or a shade, as on the long video) and keep the fox only in openers and end cards. `shortEnd()` makes the end card with the fox (end cards only).
4. **Build and check.** `./build.sh <month> short-<topic>`; render stills first (`node ../render.mjs <scene> --size 1080x1920 --stills ...`). Transcribe the final mix (`node ../generate-audio.mjs transcribe --file <mp4>`).
5. **Publish.** Upload from `final/shorts/` with the title and description; set the full review as the related video. Post date-sensitive Shorts first.

A 30 s Short builds in about 1 min, a 65 s Short in about 2 min (6 jobs).

## Tools

| File | What it does |
|---|---|
| `edition.mjs` | Paths of one edition (see above), loads its `script.mjs`, `voDir()` (take folder of a variant: MAI voice name + speed, or `vo`), `out()` (output base name), `readTimeline()` |
| `voice.mjs <month> <variant> [ids]` | Makes the voice lines of a variant (MAI by default; a variant can set `model`, `voice`, `vo`, `format`, `instructions`, `tag`, `speed` and `providerOptions`), trims silence, transcribes each take and makes a failed take again (max 3): a word of the line is missing, or a word is heard that is neither in the line nor a Finnish number word. Only missing or changed lines, unless ids are given. Log: `work/audio/vo/<voice>/check.json` |
| `timeline.mjs <month> <variant>` | Measures the lines and writes `video/timeline/<variant>.js`, `work/audio/<variant>/voice.wav` and the `.srt` |
| `mix.sh <month> <variant> <video> [out]` | Voice track + looped music (ducked) → −14 LUFS |
| `chapters.mjs <month> [variants]` | YouTube title and description from `CHAPTERS`, `TITLE`, `INTRO`, `DEFAULT_INTRO` and `FOOTER` in the edition script (default: the variants with `final`) |
| `build.sh <month> <variant> [jobs]` | All of the above plus the parallel render, in order. The variant can set `scene` (HTML file name, default the variant name) and `size` (default `1920x1080`) |
| `export-data.py <month>` | Writes `video/data.js` from the edition JSON files, the local SQLite index series and the site's frozen chart data `laravel/resources/market-reviews/<month>/kuva-1..3.json` (all read-only) |
| `recheck.mjs <month> <voices>` | Lists stored takes with unexpected words |
| `voice-test.mjs <month>` | The same passage with several TTS models → `work/audio/voice-test/` (plus `listen-all.mp3`) |
| `short.js`, `short.css` | Portrait Shorts: safe-zone layout, `shortMark()` (wordmark), `captions()` (burned-in captions from the timeline, chunked by length; `sub` text when the line has one), `shortEnd()` (end card) |
| `lib.js`, `lib.css` | Shared scene engine: all motion is a function of t in `window.renderFrame(t)` (no CSS animations). `scene()`, `show()`, `words()`, `count()`, `draw()`, `drift()`, `stamp()`, `chrome()` (wordmark + chapter rail), `billChart()` (option `labels` for a narrow chart) |

## Rebuild

```bash
cd videos/monthly-market-review
python3 -I export-data.py 2026-10              # only when the figures change; data.js is in git
./build.sh 2026-10 katsaus                     # voice, timeline, render, mix, title/description
node ../render.mjs ../../market-review/editions/2026-10/video/katsaus.html --stills 0,60,120 \
  --out ../../market-review/editions/2026-10/work/renders/check/katsaus.mp4   # stills for review
```

A 5:30 video renders in about 6.5 min with 6 parallel jobs (about 25 frames per second in total).

## A new edition

Make `market-review/editions/<month>/video/`, copy `script.mjs` and the scene(s) of the previous edition, change the lines, figures and YouTube text, then run `export-data.py` and `build.sh`. Do not change an earlier edition's sources: its video must stay rebuildable. A change to `lib.js` or `lib.css` affects every edition; render stills of the previous edition to check it.

## Rules learned

- Voice tempo: MAI Harper at speed 1 (about 1.6 words per second) sounded slow and sleepy (user 2026-11-06). A variant can set `speed` in `VARIANTS`; `voice.mjs` sends it to the API and keeps those takes in `audio/vo/<voice>-<speed>/`, so other variants keep their own takes. Katsaus v2 used 1.2 (about 2.1 words per second); Katsaus now uses Gemini Kore (about 2.0 words per second without a speed setting). The tempo of MAI takes varies by about 10 %; make a slow take again (`node voice.mjs <month> <variant> <id>`).
- Pauses: v2 (0.15–0.8 s) felt rushed. Use about 0.5–0.9 s between points on one slide and 1.2–1.6 s at a slide or chapter change.
- Do not say a number with two decimals in the voice (user 2026-11-06). Round it ("alle viisi senttiä", "lähes neljä prosenttia", "noin 1 150 euroa", or say a difference); the screen keeps the exact figure.
- A beat in the middle of a line uses `A(id, f)` (f = 0 start, 1 end), not `S(id) + seconds`, so it follows a change of voice speed.
- Write a number in a case other than the nominative in words in the voice text ("kahdenkymmenenneljän kuukauden"), with the digits in `SUBS`. MAI read "24 kuukauden" as "kaksikymmentäneljä kuukauden" (user 2026-11-06).
- Voice choice (user 2026-11-06): Gemini 3.8 Flash TTS Kore with a style instruction ("more dynamic and interesting"). ElevenLabs v4 Sarah was the runner-up (natural, but slower and less punch; it said "kartaali-" for "kvartaali-"). To compare a voice again, add a variant with other `model`/`voice`/`vo` and the same segments.
- The transcription writes "Cheapin" as "Jeepin", but the voices say it correctly (user listened 2026-11-06). Do not make a take again for this. (Cheap is not in the Katsaus voice since v5.)
- The advice in the video follows the written review (`market-review/editions/2026-10/2026-10-katsaus.md` and the site page). When the written advice changes, change the voice lines and the stamps/ledgers of the scene too.
- Charts use the site's chart data and look (user 2026-11-06): the bill outlook (`billChart()`, kuva 1: spot `--color-cat-spot`, 12-month fixed median `--color-cat-fixed`, an ink whisker for the three-winter range), the October chart in `katsaus.html` (kuva 2: three stacked panels for price with the dashed month mean, wind and temperature anomaly) and the price index (kuva 3: daily 30.9.–31.10., four series in the `/sahkosopimus/tilastot` colours with a point on each day, no "nyt" label). A monthly index change runs from the last day of the previous month to the last day of the month (user rule 2026-11-06), so October includes the 1.10. resets: +12,0 %. Read the JSON; never regenerate it (`site_payloads.py` refuses to overwrite it). Look: `laravel/resources/js/market-review-charts.js` and `inkSeriesStyle()` in `contract-price-statistics.js`. The video draws its own SVG (the renderer seeks virtual time; Chart.js and uPlot are not used). Differences: the video labels index days "2.10." (site "2.lok"). The video does not say the +36 % rise from the May low (user 2026-11-06): its chart starts on 30.9. and cannot show it, and a figure without a chart was not wanted.
- Scene timing comes only from the voice: a visual beat is `S('line') + offset`. If you change a line, run `voice.mjs` and `timeline.mjs` (or `build.sh`); the scene follows.
- The voice reads digits well ("51" → "viisikymmentäyksi"), but write ranges in words ("yhdestätoista kuuteentoista") and avoid "c/kWh" and "€" in voice text.
- "voltikka.fi" was heard as "Woltikka piste fi"; "Voltikassa. Osoite on voltikka piste fi." is clear.
- A scene that is hidden (`display: none`) still runs its tick functions; keep them cheap.
- Transitions: the next scene has a higher z and comes in over the old one (wipe or fade-in). The old scene stays solid (`hold`) under it; a fade-out showed the dark body through both scenes as grey frames. Use `fadeOut` only when the next scene is below.
- "11,3" was once read in English ("eleven point three") and the first check missed it, because digits were left out of the comparison. Now unexpected words fail a take; for a number that still fails, write it in words.
- Music: `google/lyria-3-pro-preview` gives about 3 min of music ($0.08). A transcript of the music says "[on-hold music]" for a calm prompt; prompts with drums, bass and "not elevator music" gave livelier beds (`homes-b`, `weather-b`). The agent cannot listen; the user chooses.
