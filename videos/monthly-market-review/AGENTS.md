# monthly-market-review/ — video of the monthly electricity market review

Longer (3–5 min) narrated videos of Voltikan sähkömarkkinakatsaus. First edition: October 2026 (draft `market-review/editions/2026-10/2026-10-katsaus.md`). Decisions and status: `market-review/video.md`. Tools and general rules: `videos/AGENTS.md`. Paper storyboards: page "Video · Markkinakatsaus".

## Four concept variations (2026-11-06, for review)

| Variant | File | Length | Voice | Idea |
|---|---|---|---|---|
| Katsaus | `katsaus.html` | 5:25 (v5) | Gemini 3.8 Flash TTS Kore | Full review, the chosen concept (user 2026-11-06). Order: hook, winter (why it will be expensive), advice for three homes and quarterly contracts, contract market, October in short. Painted chapter openers, paper chart scenes, chapter rail. Earlier versions in `out/archive/`: `-v1-harper` (old order), `-v2-harper` (speed 1.2, short pauses), `-v3-sarah` (ElevenLabs v4), `-v4-kore` (Kore with the old charts) |
| Kolme kotia | `kotia.html` | about 3:32 | Harper | Consumer advice only. Three homes on a dusk snowfield; the camera flies to each house and its bill chart, verdict and cheapest contracts |
| Numerot | `numerot.html` | about 3:30 | Harper | 18 big numbers, one per card, dark and light cards alternate, progress rail. Works without sound |
| Sähkösää | `saa.html` | about 3:17 | Grant | Weather-forecast format: the fox at the weather station (autumn → winter), weather board, map of Finland, forecast strip, "sähkövaroitus", contract-market "ilmanpaine", "näin varaudut" |

The fox appears only in the opener and the end card, never next to advice, a seller or a contract price. Sellers are named as in the written review (user, 2026-11-06).

## Files

| Path | What it is |
|---|---|
| `script.mjs` | All voice lines (`LINES`), subtitle text where the spoken form differs (`SUBS`), and the line order, pauses, voice and music of each variant (`VARIANTS`). Lines shared by variants are made once per voice: a change to a shared line changes every variant that uses it, so rebuild them all |
| `voice.mjs` | Makes the voice lines of a variant (MAI by default; a variant can set `model`, `voice`, `vo` (take folder), `format`, `instructions`, `tag` (text before each line, ElevenLabs) and `providerOptions`), trims silence, transcribes each take and makes a failed take again (max 3): a word of the line is missing, or a word is heard that is neither in the line nor a Finnish number word. Log: `audio/vo/<voice>/check.json` |
| `recheck.mjs` | Lists stored takes with unexpected words (`node recheck.mjs harper grant`) |
| `timeline.mjs` | Measures the lines and writes `timeline/<variant>.js` (start/end of each line for the scene), `audio/<variant>/voice.wav` and the subtitles (`final/…-katsaus.srt`; other variants `out/archive/`) |
| `export-data.py` | Writes `data/<month>.js` from the prototype JSON files, the local SQLite index series and the site's frozen chart data `laravel/resources/market-reviews/<month>/kuva-1..3.json` (as `DATA.site.kuva1..3`; all read-only) |
| `lib.js`, `lib.css` | Shared engine: all motion is a function of t in `window.renderFrame(t)` (no CSS animations). `scene()`, `show()`, `words()`, `count()`, `draw()`, `drift()`, `stamp()`, `chrome()` (wordmark + chapter rail), `billChart()` |
| `mix.sh` | Voice track + looped music (ducked) → −14 LUFS |
| `chapters.mjs` | YouTube title and description with chapters: `final/…-katsaus-title.txt` and `-description.txt` (other variants: `node chapters.mjs kotia` → `out/archive/`) |
| `render-all.sh` | Renders and mixes all four variants into `out/archive/` (about 20 min) |
| `voice-test.mjs` | The same passage with several TTS models and voices → `audio/voice-test/` (plus `listen-all.mp3`, all samples in order with a tone between) and a transcript check of each |

## Rebuild

```bash
cd videos/monthly-market-review
python3 -I export-data.py 2026-10
node voice.mjs katsaus                       # only missing or changed lines
node timeline.mjs katsaus
node ../render.mjs katsaus.html --stills 0,60,120 --out out/check/katsaus.mp4
../render-parallel.sh katsaus.html "$(node -e "global.window={};eval(require('fs').readFileSync('timeline/katsaus.js','utf8'));console.log(window.TL.total)")" out/katsaus-v1.mp4 6
./mix.sh katsaus out/katsaus-v1.mp4 final/voltikka-markkinakatsaus-2026-10-katsaus.mp4
node chapters.mjs
```

A 5:30 video renders in about 6.5 min with 6 parallel jobs (about 25 frames per second in total).

`final/` holds only the files to upload: the Katsaus video, `.srt`, title, description and thumbnail. Earlier versions, the other three concepts and work files go to `out/archive/` (user 2026-11-06).

## Rules learned

- Voice tempo: MAI Harper at speed 1 (about 1.6 words per second) sounded slow and sleepy (user 2026-11-06). A variant can set `speed` in `VARIANTS`; `voice.mjs` sends it to the API and keeps those takes in `audio/vo/<voice>-<speed>/`, so other variants keep their own takes. Katsaus v2 used 1.2 (about 2.1 words per second); Katsaus now uses Gemini Kore (about 2.0 words per second without a speed setting). The tempo of MAI takes varies by about 10 %; make a slow take again (`node voice.mjs <variant> <id>`).
- Pauses: v2 (0.15–0.8 s) felt rushed. Use about 0.5–0.9 s between points on one slide and 1.2–1.6 s at a slide or chapter change.
- Do not say a number with two decimals in the voice (user 2026-11-06). Round it ("alle viisi senttiä", "lähes neljä prosenttia", "noin 1 150 euroa", or say a difference); the screen keeps the exact figure.
- A beat in the middle of a line uses `A(id, f)` (f = 0 start, 1 end), not `S(id) + seconds`, so it follows a change of voice speed.
- Write a number in a case other than the nominative in words in the voice text ("kahdenkymmenenneljän kuukauden"), with the digits in `SUBS`. MAI read "24 kuukauden" as "kaksikymmentäneljä kuukauden" (user 2026-11-06).
- Voice choice (user 2026-11-06): Gemini 3.8 Flash TTS Kore with a style instruction ("more dynamic and interesting"). ElevenLabs v4 Sarah was the runner-up (natural, but slower and less punch; it said "kartaali-" for "kvartaali-"). To compare a voice again, add a variant with other `model`/`voice`/`vo` and the same segments.
- The transcription writes "Cheapin" as "Jeepin", but the voices say it correctly (user listened 2026-11-06). Do not make a take again for this. (Cheap is not in the Katsaus voice since v5.)
- The advice in the video follows the written review (`market-review/editions/2026-10/2026-10-katsaus.md` and the site page). When the written advice changes, change the voice lines and the stamps/ledgers of the scene too.
- Charts use the site's chart data and look (user 2026-11-06): the bill outlook (`billChart()`, kuva 1: spot `--color-cat-spot`, 12-month fixed median `--color-cat-fixed`, an ink whisker for the three-winter range), the October chart in `katsaus.html` (kuva 2: three stacked panels for price with the dashed month mean, wind and temperature anomaly) and the price index (kuva 3: daily 30.9.–31.10., four series in the `/sahkosopimus/tilastot` colours with a point on each day, no "nyt" label). A monthly index change runs from the last day of the previous month to the last day of the month (user rule 2026-11-06), so October includes the 1.10. resets: +12,0 %. Read the JSON; never regenerate it (`site_payloads.py` refuses to overwrite it). Look: `laravel/resources/js/market-review-charts.js` and `inkSeriesStyle()` in `contract-price-statistics.js`. The video draws its own SVG (the renderer seeks virtual time; Chart.js and uPlot are not used). Differences: the video labels index days "2.10." (site "2.lok"). The video does not say the +36 % rise from the May low (user 2026-11-06): its chart starts on 30.9. and cannot show it, and a figure without a chart was not wanted.
- Scene timing comes only from the voice: a visual beat is `S('line') + offset`. If you change a line, run `voice.mjs` and `timeline.mjs`; the scene follows.
- The voice reads digits well ("51" → "viisikymmentäyksi"), but write ranges in words ("yhdestätoista kuuteentoista") and avoid "c/kWh" and "€" in voice text.
- "voltikka.fi" was heard as "Woltikka piste fi"; "Voltikassa. Osoite on voltikka piste fi." is clear.
- A scene that is hidden (`display: none`) still runs its tick functions; keep them cheap.
- Transitions: the next scene has a higher z and comes in over the old one (wipe or fade-in). The old scene stays solid (`hold`) under it; a fade-out showed the dark body through both scenes as grey frames. Use `fadeOut` only when the next scene is below.
- "11,3" was once read in English ("eleven point three") and the first check missed it, because digits were left out of the comparison. Now unexpected words fail a take; for a number that still fails, write it in words.
- Music: `google/lyria-3-pro-preview` gives about 3 min of music ($0.08). A transcript of the music says "[on-hold music]" for a calm prompt; prompts with drums, bass and "not elevator music" gave livelier beds (`homes-b`, `weather-b`). The agent cannot listen; the user chooses.
