---
name: image-gen
description: Generate raster images (mascot art and poses, illustrations, hero and marketing images, cutouts with a transparent background) from a text description and optional reference images, and save them to a folder. Uses the Codex CLI (GPT Image 2, included in the ChatGPT plan) by default and OpenRouter GPT Image 2.5 Sunburst (paid) as the fallback. Use when the user asks to generate, draw or create an image, a mascot pose, a character variant or an image asset. Not for SVG icons, diagrams or UI that code can draw.
---

# Image generation

One script makes one image per call:

```bash
python3 .claude/skills/image-gen/scripts/image_gen.py "<description>" <out_dir> \
  [--name NAME] [--ref IMG ...] [--transparent] [--aspect 4:5] [--backend codex|openrouter]
```

Run it from `design/`. It prints the saved path and the cost. It never overwrites a file: use a new `--name` (for example `-v2`) for each attempt. One image takes 1–2 minutes with Codex and about 30 seconds with OpenRouter. When you need several images, start the calls in parallel (one background command each).

## Backends

| Backend | Model | Cost | Use |
|---|---|---|---|
| `codex` (default) | GPT Image 2 (built-in `image_gen` tool of `codex exec`) | included in the ChatGPT plan (counts toward Codex usage limits) | always first |
| `openrouter` | `openai/gpt-image-2.5-sunburst` | about $0.03 per image | fallback |

Use the fallback only when Codex fails: the CLI is missing or logged out, a usage limit is reached, or a run fails two times. Tell the user that you switched and what it cost. Do not use the fallback just because you do not like a result. Change the prompt and run Codex again. `--model` selects another OpenRouter model, but only when the user asks for one.

## Write the description

- Write a full brief: subject, pose and action, style, palette, background, and a list of things to avoid. Short prompts give generic results.
- **Do not name protected franchises or real brands**, also not in an "avoid" list. OpenAI's safety filter refuses such prompts. Describe the features to avoid instead (for example "no red cap, no letter on the chest").
- For the Voltikka fox, follow `voltikka-brand-guide.md` (mascot rules). The approved reference is `mascot/fox-v2.png` (user, 2026-10-16). Give it as `--ref` for every new pose.
- **No flame tail on the fox.** Do not draw a fox with a fiery tail or a fox that curls around a round shape. Describe the tail as an ordinary fox tail; small green and violet aurora sparks may float near the tip.
- Ask for no text in the image unless the user wants text. When they do, give the exact words in quotes.

## Same character in a new pose

Give the reference with `--ref` and use this description. Change only the last part:

```text
The attached image is the reference sheet for the character <name>, <one-line description>.
Draw the SAME character again. Keep his design identical to the reference: same species and body proportions, same fur colours and stripe pattern, same face, eyes, nose and smile, same ears, same tail, same clothing and props, exactly as in the reference, same illustration style, outline weight, shading and colour palette.
Change only the pose and action: <pose>.
Plain off-white background, no other characters, no text, no letters, no logo.
```

Name the props that change in the pose (for example "scarf OFF, hanging on a birch branch"). In Fetchalot tests on 2026-10-15, both backends kept the character consistent with two different references.

## Transparent background

Add `--transparent`. Codex then asks for a real alpha channel, and OpenRouter sends `background: transparent`. Both gave clean cutouts in tests. The script warns when the result has no alpha channel. In that case, generate again with a flat key colour and remove it:

1. Description ends with: "Background: one perfectly flat, uniform solid pure magenta colour #FF00FF filling the whole canvas edge to edge. No gradient, no shadow. Do not use magenta or pink on the subject." Use green `#00FF00` when the subject has pink or magenta.
2. Remove the key colour with the Codex helper:
   ```bash
   uv run --with pillow python -I ~/.codex/skills/.system/imagegen/scripts/remove_chroma_key.py \
     --input in.png --out out.png --auto-key border --soft-matte --despill
   ```

The Codex transparent result is about 99% opaque inside the subject (alpha 253, not 255). You cannot see this on a plain background.

To crop a transparent strip or cutout to its painted area, run `uv run --with pillow python -I .claude/skills/image-gen/scripts/crop_alpha.py IN OUT [margin]`.

## Check every result

Look at each image before you report it (open it with the file-read tool). Check the subject, the pose, the props, the brand rules and any text. With a reference, compare the face, the proportions, the markings and the props side by side. When something is wrong, make one targeted change to the description and generate again as a new version. Report the saved paths, the backend, the cost and anything that is still wrong.
