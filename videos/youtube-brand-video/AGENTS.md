# youtube-brand-video/ — 15 s YouTube brand ad (Hintalappu)

Brief, message, decisions and status: `tasks/youtube-brand-video/`. Tools and general rules: `videos/AGENTS.md`. Paper boards: page "Video · YouTube concepts" (see `design/AGENTS.md`).

## Files

| Path | What it is |
|---|---|
| `hintalappu.html` | The scene, 15 s, in three formats: 16:9 (1920×1080), 1:1 (1080×1080) and 9:16 (1080×1920). It reads the viewport size and sets `body.square` or `body.vertical`; the CSS block "Other formats" and the `layout` object (garland rows/columns, snow) hold the format differences. Wide uses the base rules. Headline word timing and the pendulum/typing motion are in its script (`timing`, `window.renderFrame`) |
| `mix.sh` | Puts the four voice lines at fixed times, ducks the music, normalises to −14 LUFS |
| `storyboard/` | Snow-tag SVGs and v2 stills used on the Paper boards |
| `audio/` | Voice takes (`vo/`, trimmed in `vo-trim/`), CTA tests and `music-a.mp3` (ignored by git) |
| `out/` | Work renders and stills (ignored by git) |
| `final/` | The current final videos (v4, Harper voice; ignored by git): `voltikka-hintalappu-15s-16x9.mp4` (YouTube in-stream), `-1x1.mp4` (feeds), `-9x16.mp4` (Shorts, Reels, Stories) |

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
