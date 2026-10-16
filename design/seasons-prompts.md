# Season prompt templates

Templates for the season variants (see `voltikka-design-system.md` §7 "Seasons"). They were used for the pilot on 2026-10-16 and gave consistent results with Codex (GPT Image 2).

How to use: give the existing (usually winter) file as `--ref`; add `mascot/fox-v2.png` as a second `--ref` when the fox is in the image; add `--transparent` for cutouts and strips; then crop strips with `uv run --with pillow python -I .claude/skills/image-gen/scripts/crop_alpha.py IN OUT`. Name the result `<name>-<season>-v1.png` with `talvi`, `kevat`, `kesa` or `syksy`.

## Season briefs (`{s}`)

**kevat**

> late spring (kevät) in Finland, nature waking up: snow melting into small patches and last grey snowbanks in the shade, meltwater streams and puddles reflecting the sky, lake ice breaking into floating floes with open blue water, birches with tiny fresh light-green leaves, yellow coltsfoot flowers and pussy willows along the path, a few whooper swans and cranes flying back, long bright pale-gold evening light and a clear pale sky, a fresh and hopeful mood; villagers in light jackets and rubber boots

**kesa**

> Finnish midsummer night under the nightless sky (kesä): the sun hangs low on the horizon, the sky glows warm gold, peach and soft pink fading to pale blue, lush green birches and meadows full of wildflowers (meadowsweet, harebells, daisies, fireweed), a mirror-calm lake, a wooden rowing boat and a sauna jetty, a tiny midsummer bonfire far away on the shore, a warm, lazy, relaxed mood; villagers in summer clothes; no snow anywhere

**syksy**

> Finnish autumn (syksy) evening in full ruska: birches and aspens in bright yellow, orange and red, rowan trees with red berries, fallen leaves swirling in a strong gusty wind, slanting rain showers from grey-blue rain clouds with one gap of warm low sunset light, wet shiny paths and puddles, warmly lit windows, a cosy but wild and blustery mood; villagers in yellow raincoats and rubber boots, one umbrella turning inside out in the wind; no snow

Winter brief (for art whose original is not winter): deep snow, kaamos blue hour, aurora, warm lit windows, lanterns, chimney smoke, ski tracks.

## Ground under a cutout (`{s2}`)

- **kevat**: spring: wet young grass with two yellow coltsfoot flowers and a last crumb of melting snow
- **kesa**: summer: a small patch of meadow grass with daisies and a harebell
- **syksy**: autumn: a small patch of fallen yellow and orange leaves beside a little rain puddle, a few leaves blowing

## Templates

### SCENE

```text
The attached image is a picture-book scene for the Finnish site Voltikka. Draw the SAME scene again with the SAME composition: same camera and horizon, the same layout of buildings, trees, path, power line and lake, the same empty calm sky area for text in the same place and the same size, the same small villagers in about the same places, the same fine ink outline and soft watercolour style. Change ONLY the season and weather to: {s}. No aurora. No text, no letters, no numbers, no logos.
```

### FOOT

```text
The attached image is the footer scene for the Finnish site Voltikka, with the Voltikka fox (green Nordic scarf) asleep in the front. Draw the SAME scene again with the SAME composition: same camera, the same village, lake, cottages, lamp post and the same pale empty band at the top that fades to the page colour, the fox asleep in the same place and pose, same fine ink outline and soft watercolour style. Change ONLY the season and weather to: {s}. The fox sleeps on the seasonal ground (grass, moss or leaves). No aurora. No text, no letters, no logos.
```

### STRIP

```text
The attached image is a transparent picture-book strip of animal villagers walking home along a village path (hare with a lantern, badger pulling a sled or cart with a sleepy hedgehog, beaver carrying birch sauna whisks, owl flying with a scarf, red sauna cabin at the right). Draw the SAME strip again: same characters, same order, same poses and same composition in a low wide band, same ink and watercolour style. Change ONLY the season to: {s}. In seasons without snow the sled becomes a small wooden handcart, and clothing fits the weather. Transparent background outside the painted band. No fox. No text, no letters, no logos.
```

### FOX

```text
The attached images show the Voltikka fox (reference sheet and the current pose). Draw the SAME fox in EXACTLY the same pose as the second image: same body, face, ears, the green Nordic scarf, the ordinary bushy fox tail with small green and violet aurora sparks at the tip, same illustration style and colours. Change ONLY the small patch of ground under the paws to fit {s2}. Keep the patch small. Transparent background, no other characters, no text.
```

For a strip whose activity exists only in winter, replace "Change ONLY the season" with the seasonal counterpart activity (spec, "Activities follow the season").

## Lessons from the full run (2026-10-16)

Add these lines to the prompt when they apply. The model copies details from the winter reference.

- Always: "Only animal villagers, never humans." The model adds people to village scenes.
- Spring, summer and autumn: "Absolutely no snow or ice anywhere: no snow caps on roofs, posts, rocks or signposts. No Christmas wreaths, no skis, no winter props."
- Summer (and warm spring): "Light summer clothes; NO wool hats, scarves or sweaters" for villagers. (The fox keeps its green scarf: it is the brand.)
- A transparent strip has no sky: "No aurora."
- A winter scene with an ice road or the fox on ice: in other seasons, say where the fox walks instead ("on a stony causeway / the shore, never on open water").
- Fox cutouts: do not ask for "aurora sparks at the tail tip" in a new pose; Codex can draw them as a flame-like wisp, which the brand forbids. Ask for "a plain white fur tip, no flame, no wisps". Keep sparks only when the result shows small separate stars, as on `fox-sit-skyward` and `fox-walk-lantern`.
- Check the season of the original first; name each file by the season it shows.
- Check the fox tail count in every result (2026-10-16): three forecast heroes had a second tail raised behind the back. Say "exactly ONE tail" in the prompt.
- Distant crowds (bonfire, path walkers, picnic tables) often come out human, and the winter original's skis and door wreaths stay in other seasons. Enlarge these areas when you check a result. To fix one, give the result as `--ref` with "Redraw it as an exact copy … Change ONLY …": Codex then changes only that area. When Codex does not change a figure of about 15 px, fix the pixels directly (for example, copy the ears of a neighbouring hare).
