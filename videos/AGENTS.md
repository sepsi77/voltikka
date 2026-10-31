# videos/ — code-rendered video

Marketing videos made from HTML scenes. No video library (no Remotion). Read the root `AGENTS.md` first; the brand rules in `design/` apply to every frame. Current work: `tasks/youtube-brand-video/`.

## Folder layout

Each video project has its own folder. The shared tools stay at the top.

```
videos/
├── render.mjs, generate-audio.mjs, package.json   # shared tools (one node_modules)
├── spike/                  # 3 s technical test
└── <project>/              # one folder per video project, e.g. youtube-brand-video/
    ├── AGENTS.md           # project notes: brief pointer, script, timing, voice, how to rebuild
    ├── <concept>.html      # scene(s); the file name names the output (hintalappu.html → out/hintalappu.mp4)
    ├── mix.sh              # audio mix for this project (optional)
    ├── storyboard/         # images for the Paper boards
    ├── audio/              # generated voice and music (ignored by git)
    ├── out/                # work renders and stills (ignored by git)
    └── final/              # the approved video(s) to upload, with a clear name (ignored by git)
```

Name a project folder like its task in `tasks/` (`tasks/youtube-brand-video/` ↔ `videos/youtube-brand-video/`). Put a scene directly in the project folder, so its paths to the art stay `../../design/...`.

## How a render works

`render.mjs` opens a scene HTML file in Playwright Chromium at 1920×1080. For each frame it pauses every CSS animation and seeks it to virtual time t (`document.getAnimations()`), then calls `window.renderFrame(t)` if the scene defines it. PNG screenshots go through a pipe to ffmpeg (H.264, CRF 16, yuv420p). Time is virtual, so a render is the same every time. Output goes to `out/` in the scene's folder, unless `--out` is given.

```bash
cd videos
npm install                     # once; Playwright Chromium: npx playwright install chromium
node render.mjs spike/index.html --stills 0.3,1,3      # PNG stills for review: spike/out/spike-*.png
node render.mjs spike/index.html --duration 3          # spike/out/spike.mp4
```

Other formats use `--size` (for example `--size 1080x1920` for 9:16). A scene can read its viewport size and change its layout; `youtube-brand-video/hintalappu.html` makes 16:9, 1:1 and 9:16 from one file.

Write scenes with normal CSS keyframes and `animation-delay`. Do not use `setTimeout`, `requestAnimationFrame` or real clocks for motion: the renderer cannot seek them. JS-driven parts (for example typing) read t in `window.renderFrame(t)`.

## Voice and music

`generate-audio.mjs` calls OpenRouter: `speech` (`/api/v1/audio/speech`), `transcribe` (`/api/v1/audio/transcriptions`, ElevenLabs Scribe) and `music` (Lyria 3 through chat completions; audio output is only available with `stream: true`). Run it from the project folder (`node ../generate-audio.mjs ... --out audio/x.mp3`), so files land in that project's `audio/`. It reads `OPENROUTER_API_KEY` from `laravel/.env` first, because the shell can hold an unrelated, invalid key. Never print the key.

- Finnish voice: `microsoft/mai-voice-2.1` with `fi-FI-Harper:MAI-Voice-2.1` (female, default) or `fi-FI-Grant:MAI-Voice-2.1` (male). ElevenLabs voices are English voices speaking Finnish: "Hae" became "Hai"/"Hei". Gemini TTS said "Foltikka" (and returns only raw PCM).
- Check every voice line by transcribing it, and transcribe the final mix too: the agent cannot listen, and a transcript shows unclear words and voice lost under music. A bare "Hae: Voltikka" was heard as "Hei Voltikka"; "Hae: Voltikka sähkösopimus" is clear (the longer phrase gives "Hae" context).
- Music: `google/lyria-3-clip-preview` gives a 30 s clip (about 24 s of music) for $0.04.
- Mix to −14 LUFS (YouTube) and duck the music under the voice; `youtube-brand-video/mix.sh` is a working example.

## Projects

| Folder | What it is |
|---|---|
| `spike/` | 3 s technical test (plate drift, fox entry, word stagger, ink draw-on) |
| `youtube-brand-video/` | 15 s YouTube brand ad, concept Hintalappu; see its `AGENTS.md` |

## Media files

`.gitignore` ignores `out/` and `audio/` in every project (and `final/` through the media extensions) and all video, audio and raster image files anywhere in `videos/`. SVG storyboard files stay in git. The production image is built from git, and the root `.dockerignore` allowlist does not include `videos/`, so media never goes into the image. The scene art comes from `design/`, which is tracked there. Paper keeps its own uploaded copies of storyboard images, so moving local files does not break a board.

## Limits

- Painted plates are about 1672 px wide; at 1080p with a zoom they look soft. Final shots need larger art.
- Character animation (walk cycles) is not practical. Motion comes from camera moves, layers, light, ink and particles.
- A 15 s render takes about 2.5 min (about 3 frames per second).
