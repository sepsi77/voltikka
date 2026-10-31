# Decisions

## 2026-10-30

- YouTube first, 16:9, audience from search-keyword custom segments (active contract shoppers, likely omakotitalo). Goal is branding and branded search; CTA asks the viewer to search for Voltikka. (user)
- Use the existing brand style (picture-book art, fox, tokens). Explore concepts, and style only on the motion axis. (proposal accepted by default; user did not object)
- Storyboard frames are made in Paper because Paper frames are HTML, like the render frames. (user + agent)
- Local tools exist: ffmpeg 9.0.2, Node, Playwright Chromium cache.
- Working message (user): **"Löydä oikeasti halvin sähkösopimus."** It combines whole-market coverage and the whole-year price. "Oikeasti" carries both proofs: every contract (not only the ones that pay the comparison site) and the price over all known price phases.
- The comparison with other sites stays implicit. Do not say or show "other sites hide contracts": at least one free national comparison also lists every contract, so an explicit claim would be false. Show only Voltikka's own true facts (every contract, no commission, whole-year price).
- User: "koko vuoden hinta" is abstract for viewers and partly an estimate. Proposed the deceptive-promotion angle instead.
- Data check (local database snapshot 2026-10-28, active contracts): 261 household contracts (Household/Both/null). 38 of them (about 1 in 7) have more than one canonical price phase, mostly a first-months promotion. Only 3 household contracts (5 in total, 3 companies) have a detected hidden increase (`misleading_first_12_months = detected`, increase stated only in the description text).
- Agent recommendation: show the general promotion case (a promotional price that ends, and the normal price after it), not "deception". The hidden-increase case is too rare today to carry an ad, and a fraud-style claim would overstate it and accuse sellers. The price after a promotion is the seller's own published price, not an estimate.

## 2026-10-30 — technical spike

- Pipeline: `videos/render.mjs`. Playwright Chromium loads an HTML scene; for each frame all CSS animations are paused and seeked to virtual time t (`document.getAnimations()`), then optional `window.renderFrame(t)` runs; PNG screenshots go through a pipe to ffmpeg (H.264, CRF 16, yuv420p). `--stills` writes PNG files for review. So scenes are authored with normal CSS keyframes, which also fits Paper (HTML) frames.
- Result: `videos/spike/index.html`, 3 s at 1920×1080/30 fps, 90 frames in about 30 s. A 15 s ad renders in about 2.5 min.
- Easy: plate drift (Ken Burns), cutout fox entry, staggered display type, ink underline draw-on (SVG stroke), fonts from Google Fonts.
- Limits found: plates are 1672×941, so at 1080p with a zoom they are about 1.3× upscaled and a little soft. Final shots need larger art (image-gen at a higher size, or an upscale). The fox cutout has a painted snow patch at the feet; it must stand on snow in the plate. Character animation (walk cycles) is not practical; motion must come from camera, layers, light and ink.

## 2026-10-30 — concepts

- Paper page "Video · YouTube concepts" (`p-D-0`): board 00 brief and message, then four concepts with four 16:9 key frames each (time, picture, on-screen text, voice):
  - 01 Hintalappu: one paper price tag turns over to its later price; camera pulls back to hundreds of tags. No new art. Effort low–medium.
  - 02 Lyhty: dark village, the fox's lantern shows notice boards and the small print; sparks rise into the fox fires. New art needed. Effort high.
  - 03 Haku: starts with the viewer's own search "halvin sähkösopimus"; ledger rows stream past; one row opens to its later price; field types "voltikka". No new art. Effort low.
  - 04 Lumisade: it snows tiny price tags on the picture-book village; the hedgehog reads one. Little new art. Effort medium.
- Shared voice script (about 20 words, about 10 s): "Löydä oikeasti halvin sähkösopimus. Voltikassa ovat mukana kaikki Suomen sähkösopimukset, ja näet myös hinnan, kun tarjous loppuu. Hae: Voltikka."
- All prices in frames are illustrative (4,9 → 8,9 snt/kWh); no seller names. The fox appears only in the hook or end card, never next to a price; the hedgehog reads the small print (open question for the user: villagers are background-only on the site).

## 2026-10-30 — concept choice and v2

- User chose 01 Hintalappu, but found the v1 frames "a bit boring".
- v2 treatment (agent): close-up tag with paper texture, drop shadow and a damped swing on its cord; blurred village plate as background (depth of field) with snow in front of the lens; TARJOUS ink stamp; 3D flip to a red later price with a drawn ink circle; fast pull-back to about 90 tags on garlands at three depths; the last tag swings down as the end card (wordmark + "Hae: voltikka" typed) with the fox looking up. Scene: `videos/hintalappu/index.html`.
- v2 style frames are stills from the real render, placed on Paper board "01 v2" (Paper cannot show CSS transforms, blur or 3D, so Paper mock-ups would undersell the look).
- Voice and music: synthetic, from models on OpenRouter (user). Choose models at the animatic step.

## 2026-10-30 — sound and v3

- User: try this concept to the end; any good Finnish voice; upbeat music for memory.
- Voice: OpenRouter `microsoft/mai-voice-2.1`, `fi-FI-Harper` (default) or `fi-FI-Grant`. Six candidates were transcribed with ElevenLabs Scribe; only the two native Finnish MAI voices said every word right ("Voltikasta", "Hae"). Gemini said "Foltikasta"; ElevenLabs voices said "Hei/Hai".
- Script (final for this version): "Löydä oikeasti halvin sähkösopimus Voltikasta. / Näet myös hinnan, kun tarjous loppuu. / Mukana ovat kaikki Suomen sähkösopimukset. / Hae netistä: Voltikka." The name is now said before the 5 s skip point, and a corner wordmark shows from 0 s. "Hae: Voltikka" alone was heard as "Hei Voltikka".
- Music: Lyria 3 clip, upbeat Nordic winter pop, first 15 s with a 0.9 s fade; ducked under the voice; mix at −14 LUFS.
- User feedback on v2: the end tag swing looked unnatural; the pull-back needs more tags. v3: both tags use a computed damped pendulum with residual sway (end tag also twists in 3D and bounces on the cord); pull-back to scale 0.11 with about 300 tags and a glow behind our tag.
- v3 with sound: `videos/out/hintalappu-v3-harper.mp4` and `-grant.mp4`; both transcripts match the script word for word; both −14.1 LUFS.
- User: search phrase should be "voltikka sähkö" or "voltikka sähkösopimus"; Harper voice preferred over Grant.
- Tested with Harper and transcribed: "Hae: Voltikka sähkösopimus." is clear and shortest (2.2 s; a second take also clear). "Voltikka sähkö" came out as "voltikkakka"; "netistä" variants were longer and one said "Woltikka". Chosen: "Hae: Voltikka sähkösopimus." The end tag is 760 px wide and types "voltikka sähkösopimus" from 12.0 s. v4: `videos/out/hintalappu-v4-harper.mp4`, transcript matches, −14.1 LUFS.

## 2026-10-30 — Folder layout

- User: each video project gets its own folder. Shared tools (`render.mjs`, `generate-audio.mjs`, `package.json`) stay in `videos/`. This project is now `videos/youtube-brand-video/`: scene `hintalappu.html` (was `videos/hintalappu/index.html`), `mix.sh`, `storyboard/` (was `videos/storyboard-assets/`), and its own ignored `audio/` and `out/`. Earlier paths in this file (`videos/out/...`, `videos/hintalappu/`) now point into that folder. The renderer writes to `out/` in the scene's folder. The approved video goes to `final/` with a clear name: v4 Harper is `videos/youtube-brand-video/final/voltikka-hintalappu-15s.mp4` (was `out/hintalappu-v4-harper.mp4`).

## 2026-10-31 — Square and vertical versions

- User: also render square and vertical versions of the final video. One scene file makes all three formats: it reads the viewport size (render `--size`) and moves the headlines, the camera (world translate and scale; the pull-back zooms around our tag), the end tag and the fox. 1:1 is 1080×1080, 9:16 is 1080×1920 with larger headlines; text and tags stay above the bottom caption zone (about 1500 px). More garland rows/columns fill the taller frames after the pull-back. The wide version is unchanged (stills at 9.6 s and 13.6 s are byte-identical to the earlier scene; 2.6 s differs only by rounding, PSNR 83 dB).
- Same audio mix for all three; −14.1 LUFS each. Final files: `videos/youtube-brand-video/final/voltikka-hintalappu-15s-16x9.mp4`, `-1x1.mp4`, `-9x16.mp4` (the 16:9 file was renamed from `voltikka-hintalappu-15s.mp4`).
