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

## 2026-11-05 — More ads for rotation: concept 05 Oma lasku

- User: one ad shown again and again tires the viewers, so make more brand ads with the same CTA ("Hae: Voltikka sähkösopimus."). The agent proposed five concepts (Oma lasku, Oma kulutus, Haku, Järjestys, Revontulet), each with a different proof. All ads end on the same end card (the Hintalappu end tag and the fox), so that viewers learn the sign-off.
- User chose **Oma lasku** (bill comparison). Storyboard: Paper board "05 · Oma lasku (the bill) · storyboard" on page `p-D-0`, with five 16:9 key frames and a script panel. Mailbox at dusk → the bill opens as the torn paper of /maksatko-liikaa → contract rows priced for the same period and kWh → the bill drops into its own place, with an ink circle and the difference to the cheapest → shared end card at the same house at night.
- Voice (Harper): 0.4 s "Maksatko sähköstä liikaa?" · 2.6 s "Voltikka vertaa laskuasi kaikkiin Suomen sähkösopimuksiin." · 6.3 s "Näet, mitä jokainen sopimus olisi maksanut samalta ajalta." · 12.3 s "Hae: Voltikka sähkösopimus." The timings must be checked with real takes.
- Claim basis: the bill tool prices every active contract for the bill's own period and kWh, energy part only (no transfer). The bill and all prices are fictional, and no sellers are named. The difference is small and calm (no "säästä!").
- User: **no real months or dates in an ad**, because the ad then looks old almost at once. Use timeless words ("viime kuun lasku", "1 kk", "sama aika").
- Art: a mailbox cut out of the postman interlude looked cut off, and separate cutouts looked pasted together (user). Paint the scene as one image.
- Postman variant (user asked to see it): a badger cutout pasted beside the mailbox looked separate from the scene and too small. House, mailbox and postman are now painted in one image: `design/scenes/omalasku-hook-postman-v1.png` (wider) and `-v2.png` (closer), on Paper board "05 · Frame 1 variants (postman)". Open: whether a villager may be the focus of the hook (the brand guide keeps villagers in the background).
- User chose **C** (2026-11-05): the hook is `design/scenes/omalasku-hook-postman-v1.png`, with the badger postman at the mailbox in the same painting. This is the user's exception to the villager rule for this ad: the postman may be the focus of the hook, because he is not near a contract, a price or a seller. Still needed: the painting at a larger size, and a night version of the same place without the postman (his ski tracks stay) for the end card.

## 2026-11-05 — Storyboard for 03 Haku

- User asked for a storyboard of the search concept too. Paper board "03 · Haku (the search) · storyboard" on page `p-D-0`: five 16:9 key frames and a script panel. Night winter village (`scenes/winter-evening-village-v2.png`) with an inked search field that types "halvin sähkösopimus" → a torn-paper list of every contract unrolls under the field → the four home presets of the site; 18 000 kWh is chosen and the rows change order → the field types "voltikka sähkösopimus" and the shared end tag swings down around it.
- Voice (Harper): 0.4 s "Etsitkö halvinta sähkösopimusta?" · 2.6 s "Voltikassa ovat mukana kaikki Suomen sähkösopimukset." (the Hintalappu take) · 6.2 s "Voltikka laskee jokaisen hinnan sinun kulutuksellasi." · 11.5 s "Hae: Voltikka sähkösopimus." (the Hintalappu take). The timings must be checked with real takes.
- Proof of this ad: prices at the viewer's own consumption. Before production, check with real data that the top of the list changes order between 5 000 and 18 000 kWh.
- The search field is drawn in Voltikka's ink style, with no search-engine logo, colours or result page. No new art is needed.
- User doubted the "turn" frame (the old search deletes itself under "Ensi kerralla:"): viewers may not get it. Removed. The proof frame now runs 6–10.5 s, and the end card 10.5–15 s, where the field clears in one stroke and types the new search.

## 2026-11-05 — Production of Oma lasku and Haku

- User asked for both videos, 16:9 first, then 1:1 and 9:16. Scenes: `videos/youtube-brand-video/omalasku.html` and `haku.html`; finals in its `final/` as `voltikka-omalasku-15s-*.mp4` and `voltikka-haku-15s-*.mp4`.
- Haku data check (live listing, read-only, 2026-11-05): the top two stay the same at 2 000–18 000 kWh, but places 3–8 change between 5 000 and 18 000 kWh, and new contracts come into the top four. The claim "Voltikka laskee jokaisen hinnan sinun kulutuksellasi" and the reorder in the ad are true to the site. The prices and moves in the ad are fictional.
- Haku voice line 2 is a new take ("Voltikassa ovat mukana …"); the Hintalappu take has other words ("Mukana ovat …"). Line 4 in both ads is the Hintalappu take. All new takes were transcribed and match their text.
- New art (image-gen, Codex): `design/scenes/omalasku-end-night-v1.png` (the hook place at night, no postman, ski tracks stay) and the 9:16 paintings `omalasku-hook-postman-9x16-v1.png` and `omalasku-end-night-9x16-v1.png`, because a 9:16 crop of the 16:9 painting loses the postman or the house. The 16:9 hook is still 1672 px wide (not a larger version); at 1080p it is shown at 115 %, which is acceptable without a zoom.
- Order change in Oma lasku: the bill moves into its place first, and the other ledger rows drop in around it (the rows covered the small bill in square). The difference note sits under the ledger in all formats.
- Haku end card: the tag does not swing while it drops, because the search field must land exactly in the tag's field. The field is handed to the tag at 12.0 s, when the tag hangs still; then the tag swings a little with it.
- Sound: a new Lyria music clip per ad (same style as Hintalappu), and synthetic sound effects (`sfx.sh`): key clicks for both searches in Haku, paper swishes, a paper settle and a pen sound in Oma lasku. `mix.sh` now normalises in two passes: the one-pass mix with the effects came out at −15 LUFS.

## 2026-11-05 — Review round 1 of Oma lasku and Haku (16:9 first)

- User feedback: Haku is too fast and the change from "Kaikki Suomen sähkösopimukset." to "Hinnat sinun kulutuksellasi." is too abrupt; frame 0 is often the YouTube thumbnail and must work as one; the Oma lasku music sounds like elevator music. The 1:1 and 9:16 versions wait until the 16:9 versions are approved.
- Frame 0: in both ads the first headline is on screen from frame 0 (no entry animation). In Haku the search field also shows "halvin sähkösopimus" as a grey suggestion, and the typing writes over it.
- Haku v3: the result stream now starts at the most expensive rows and scrolls up, slows down and stops on the cheapest eight. These are the same rows as the ranked list, so the list does not jump. Then the other rows fade, and the list rests for about 0.6 s before the next headline. All later beats are 0.5 s later (voice line 3 at 6.7 s, line 4 at 12.0 s), and the end card is 3.5 s instead of 4 s. Two soft settle sounds mark the stop of the stream and of the reorder.
- Oma lasku music: two new Lyria clips with drums, bass and a clear hook (`audio/omalasku/music-c.mp3` funk, `music-b.mp3` indie pop). The old prompt asked for "soft felt piano", "cosy" and "gentle finger snaps". The agent cannot listen, so the user chooses.
- Haku v4: the search field no longer moves into the end tag. While the tag fell, the field waited at the tag's rest position, so two empty search fields showed (user screenshot at 0:12). Now the field clears its text at the top of the list (11.0–11.2 s) and fades out with the paper. The tag drops with its own field, and the CTA types there from 12.2 s.
- Oma lasku music: user chose B (`music-b.mp3`, indie pop), but at full level it covered the voice. It is now mixed 5 dB lower (`mix.sh` 7th argument; the default 0 dB keeps the other ads the same).
- User approved the 16:9 versions (Haku v4, Oma lasku v4 with music B at −5 dB). The 1:1 and 9:16 versions were rendered from the same scenes and checked with stills; all six are in `final/` (15.0 s; Haku −14.2 LUFS, Oma lasku −14.0 LUFS). Frame 0 of each ad is also in `final/` as `voltikka-<ad>-thumbnail-16x9.jpg` (1280×720), for a custom YouTube thumbnail.
- Oma lasku 9:16 end card: the fox sat at the left edge (the mailbox fills the right side) and looked up to the left, out of the frame (user). The fox and its sparks are now mirrored in 9:16 only, so it looks up at the tag. The 9:16 final was rendered again; 16:9 and 1:1 are unchanged.
- Thumbnails for all three formats of Haku and Oma lasku are in `final/` (frame 0 of each final video). None was made for Hintalappu: its frame 0 is the blurred village with no headline, and its first frame with a headline also shows the "TARJOUS 4,9 snt/kWh" tag, which out of context can look like a price offer.
