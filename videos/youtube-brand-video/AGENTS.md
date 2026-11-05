# youtube-brand-video/ — 15 s YouTube brand ads (Hintalappu, Oma lasku, Haku)

Brief, message, decisions and status: `tasks/youtube-brand-video/`. Tools and general rules: `videos/AGENTS.md`. Paper boards: page "Video · YouTube concepts" (see `design/AGENTS.md`).

## Files

| Path | What it is |
|---|---|
| `hintalappu.html` | The scene, 15 s, in three formats: 16:9 (1920×1080), 1:1 (1080×1080) and 9:16 (1080×1920). It reads the viewport size and sets `body.square` or `body.vertical`; the CSS block "Other formats" and the `layout` object (garland rows/columns, snow) hold the format differences. Wide uses the base rules. Headline word timing and the pendulum/typing motion are in its script (`timing`, `window.renderFrame`) |
| `omalasku.html` | Ad 2, Oma lasku (the bill), 15 s, same three formats. The hook and night plates change to the `-9x16-v1.png` paintings in 9:16. JS measures the bill, the small bill and the ledger slot (`measure()`) so the bill can move between them |
| `haku.html` | Ad 3, Haku (the search), 15 s, same three formats. One search field for the whole film, moved by `window.renderFrame` (rects per format in `L`). At the end it clears its text and fades out with the paper, and the end tag brings its own field: a field that moved into the falling tag looked like a second, ghost field (user, 2026-11-05). The result stream scrolls up from the most expensive rows and lands on the ranked rows, so the change of layer cannot be seen |
| `mix.sh` | Puts the four voice lines at fixed times, ducks the music, adds an optional sound-effect track, normalises to −14 LUFS. An optional 7th argument sets the music level in dB (default 0). Defaults are Hintalappu |
| `sfx.sh` | Makes `audio/<ad>/sfx.wav` from synthetic sounds (key clicks, Enter, paper swish, settle, pen). Its event times copy the scene timing |
| `storyboard/` | Snow-tag SVGs and v2 stills used on the Paper boards |
| `audio/` | Voice takes (`vo/`, trimmed in `vo-trim/`), CTA tests and `music-a.mp3` (ignored by git) |
| `out/` | Work renders and stills (ignored by git) |
| `final/` | The current final videos (Harper voice; ignored by git): `voltikka-<ad>-15s-16x9.mp4` (YouTube in-stream), `-1x1.mp4` (feeds), `-9x16.mp4` (Shorts, Reels, Stories) for `hintalappu`, `omalasku` and `haku`; `voltikka-<ad>-thumbnail-{16x9,1x1,9x16}.jpg` is frame 0 of each format (16:9 at 1280×720, the YouTube thumbnail size; the others at the video size) for `omalasku` and `haku`. Hintalappu has none: its frame 0 has no headline |

## Rebuild

```bash
cd videos/youtube-brand-video
node ../render.mjs hintalappu.html --duration 15 --out out/hintalappu-v5.mp4
node ../render.mjs hintalappu.html --duration 15 --size 1080x1080 --out out/hintalappu-v5-square.mp4
node ../render.mjs hintalappu.html --duration 15 --size 1080x1920 --out out/hintalappu-v5-vertical.mp4
./mix.sh harper out/hintalappu-v5.mp4          # out/hintalappu-v5-harper.mp4 (same for the other two)
mv out/hintalappu-v5-harper.mp4 final/voltikka-hintalappu-15s-16x9.mp4   # when approved
```

Check a layout change with stills first (`--stills 2.6,5.8,9.6,13.6`), in all three sizes. In 9:16 the bottom part of the frame (below about 1500 px) is under the Shorts/Reels captions and buttons, so the text and both tags stay above it; only the snow under the fox goes lower. The wide version must not change when you edit the other formats: compare a wide still before and after.

Voice lines (Harper) start at 0.45, 4.45, 7.6 and 12.3 s; they match the headline timing in the scene. If you change one, change both. The last line is "Hae: Voltikka sähkösopimus.", and the end tag types "voltikka sähkösopimus" at the same time.

Voice script (v4, one file per line):

1. "Löydä oikeasti halvin sähkösopimus Voltikasta."
2. "Näet myös hinnan, kun tarjous loppuu."
3. "Mukana ovat kaikki Suomen sähkösopimukset."
4. "Hae: Voltikka sähkösopimus."

Generated audio is not in git. To make it again:

```bash
node ../generate-audio.mjs speech --model microsoft/mai-voice-2.1 --voice fi-FI-Harper:MAI-Voice-2.1 \
  --text "Näet myös hinnan, kun tarjous loppuu." --out audio/vo/harper-2.mp3
ffmpeg -i audio/vo/harper-2.mp3 -af "silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.02,areverse,silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.05,areverse" -ar 48000 audio/vo-trim/harper-2.wav
node ../generate-audio.mjs transcribe --file audio/vo-trim/harper-2.wav   # must match the line
```

Takes differ each time; transcribe each one and keep a clear take. Music: `node ../generate-audio.mjs music --out audio/music-a.mp3 --prompt "Upbeat, bright Nordic winter pop instrumental for a 15 second TV ad. 124 BPM, plucky synths, sleigh bells, handclaps, warm bass, a catchy 4-bar hook from the first second, confident and friendly, clean ending. No vocals."`

## Oma lasku and Haku (2026-11-05)

Storyboards: Paper boards "05 · Oma lasku" and "03 · Haku" on page `p-D-0`. Both end on the Hintalappu end tag and fox.

Frame 0 is often the YouTube thumbnail, so the first headline has no entry animation: it is on screen from frame 0 (Haku also shows its search as a grey suggestion that the typing writes over). Haku v3 (user 2026-11-05: too fast, abrupt middle) has a 0.6 s rest between "Kaikki Suomen sähkösopimukset." and "Hinnat sinun kulutuksellasi.", and the beats after it are 0.5 s later; the end card is 3.5 s.

| Ad | Voice lines (Harper, start) | Music | Takes |
|---|---|---|---|
| Oma lasku | 0.4 "Maksatko sähköstä liikaa?" · 2.6 "Voltikka vertaa laskuasi kaikkiin Suomen sähkösopimuksiin." · 6.3 "Näet, mitä jokainen sopimus olisi maksanut samalta ajalta." · 12.3 "Hae: Voltikka sähkösopimus." | `audio/omalasku/music-b.mp3` at −5 dB (user chose B, 2026-11-05; at 0 dB it covered the voice; `music.mp3` was too calm, `music-c.mp3` was not chosen) | `audio/omalasku/vo-trim/harper-{1..4}.wav` |
| Haku | 0.4 "Etsitkö halvinta sähkösopimusta?" · 2.6 "Voltikassa ovat mukana kaikki Suomen sähkösopimukset." · 6.7 "Voltikka laskee jokaisen hinnan sinun kulutuksellasi." · 12.0 "Hae: Voltikka sähkösopimus." | `audio/haku/music.mp3` | `audio/haku/vo-trim/harper-{1..4}.wav` |

Line 4 is the Hintalappu take (`audio/vo-trim/harper-4.wav`). The other lines are new takes; each was transcribed and matched its text. The bill and all prices are fictional, and no sellers are named. No months or dates (user rule).

```bash
cd videos/youtube-brand-video
./sfx.sh omalasku && ./sfx.sh haku
node ../render.mjs omalasku.html --duration 15 --out out/omalasku-v1.mp4      # add --size 1080x1080 / 1080x1920
./mix.sh harper out/omalasku-v1.mp4 audio/omalasku/music-b.mp3 audio/omalasku/vo-trim "400 2600 6300 12300" audio/omalasku/sfx.wav -5
node ../render.mjs haku.html --duration 15 --out out/haku-v1.mp4
./mix.sh harper out/haku-v1.mp4 audio/haku/music.mp3 audio/haku/vo-trim "400 2600 6700 12000" audio/haku/sfx.wav
```

Music prompts (Lyria 3 clip): Oma lasku `music-c` "Funky, energetic Scandinavian pop instrumental for a 15 second TV commercial. 118 BPM, tight disco-funk drums, groovy slap bass, staccato brass stabs, bright clavinet, sleigh bells, a strong memorable hook from the first second, playful attitude like a modern brand ad, clean button ending. No vocals." and `music-b` "Driving, upbeat Nordic indie-pop instrumental for a 15 second TV ad. 128 BPM, punchy live drums with handclaps, bouncy synth bass, bright electric guitar riff, glockenspiel and sleigh bell accents, a bold catchy 4-bar hook from the very first second, energetic, cheeky and confident, lifts at the halfway point, clean ending on the last beat. No vocals." (the first prompt, with "soft felt piano", "cosy" and "gentle finger snaps", gave elevator music); Haku "Upbeat, bright Nordic winter pop instrumental for a 15 second TV ad. 126 BPM, plucky synths, marimba, sleigh bells, handclaps, warm bass, a catchy playful 4-bar hook from the first second, clever and friendly, clean ending. No vocals."
