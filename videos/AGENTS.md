# videos/ — code-rendered video

Marketing videos made from HTML scenes. No video library (no Remotion). Read the root `AGENTS.md` first; the brand rules in `design/` apply to every frame. Current work: `tasks/youtube-brand-video/`.

## How a render works

`render.mjs` opens a scene HTML file in Playwright Chromium at 1920×1080. For each frame it pauses every CSS animation and seeks it to virtual time t (`document.getAnimations()`), then calls `window.renderFrame(t)` if the scene defines it. PNG screenshots go through a pipe to ffmpeg (H.264, CRF 16, yuv420p). Time is virtual, so a render is the same every time.

```bash
cd videos
npm install                     # once; Playwright Chromium: npx playwright install chromium
node render.mjs spike/index.html --stills 0.3,1,3      # PNG stills for review
node render.mjs spike/index.html --duration 3          # out/spike.mp4
```

Write scenes with normal CSS keyframes and `animation-delay`. Do not use `setTimeout`, `requestAnimationFrame` or real clocks for motion: the renderer cannot seek them. JS-driven parts (for example typing) read t in `window.renderFrame(t)`.

## Voice and music

`generate-audio.mjs` calls OpenRouter: `speech` (`/api/v1/audio/speech`), `transcribe` (`/api/v1/audio/transcriptions`, ElevenLabs Scribe) and `music` (Lyria 3 through chat completions; audio output is only available with `stream: true`). It reads `OPENROUTER_API_KEY` from `laravel/.env` first, because the shell can hold an unrelated, invalid key. Never print the key.

- Finnish voice: `microsoft/mai-voice-2.1` with `fi-FI-Harper:MAI-Voice-2.1` (female, default) or `fi-FI-Grant:MAI-Voice-2.1` (male). ElevenLabs voices are English voices speaking Finnish: "Hae" became "Hai"/"Hei". Gemini TTS said "Foltikka" (and returns only raw PCM).
- Check every voice line by transcribing it, and transcribe the final mix too: the agent cannot listen, and a transcript shows unclear words and voice lost under music. A bare "Hae: Voltikka" was heard as "Hei Voltikka"; the chosen "Hae: Voltikka sähkösopimus" is clear (the longer phrase gives "Hae" context).
- Music: `google/lyria-3-clip-preview` gives a 30 s clip (about 24 s of music) for $0.04.
- `hintalappu/mix.sh <voice>` places the trimmed lines at fixed times, ducks the music under the voice and normalises to −14 LUFS (YouTube).

## Files

| Path | What it is |
|---|---|
| `render.mjs` | The renderer |
| `spike/` | 3 s technical test (plate drift, fox entry, word stagger, ink draw-on) |
| `hintalappu/` | The YouTube price tag ad: `index.html` scene, `mix.sh` audio mix |
| `generate-audio.mjs` | OpenRouter voice, transcription and music |
| `storyboard-assets/` | Images used on the Paper storyboard boards (SVG in git; raster stills are ignored) |
| `out/`, `audio/` | Renders and generated audio (ignored by git) |

`.gitignore` ignores all video, audio and raster image files in `videos/`, also outside `out/` and `audio/`. The production image is built from git, and the root `.dockerignore` allowlist does not include `videos/`, so media never goes into the image. The scene art comes from `design/` (`../../design/...`), which is tracked there.

## Limits

- Painted plates are about 1672 px wide; at 1080p with a zoom they look soft. Final shots need larger art.
- Character animation (walk cycles) is not practical. Motion comes from camera moves, layers, light, ink and particles.
- A 15 s render takes about 2.5 min (about 3 frames per second).
