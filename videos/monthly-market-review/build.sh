#!/usr/bin/env bash
# Builds one variant of an edition end to end: voice (only missing or changed lines), timeline,
# parallel render, mix, and the YouTube title/description of a published variant.
#
#   ./build.sh <month> <variant> [jobs]
#
# The scene is market-review/editions/<month>/video/<scene>.html, where <scene> is the variant's
# `scene` (default: the variant name). Frame size: the variant's `size` (default 1920x1080).
# The silent render goes to work/renders/<variant>.mp4; the mixed video to edition.out() + .mp4.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
month="$1"; variant="$2"; jobs="${3:-6}"
cd "$here"
node voice.mjs "$month" "$variant"
node timeline.mjs "$month" "$variant"
read -r scene size total out work < <(node --input-type=module -e "
  import { edition, readTimeline } from './edition.mjs';
  const ed = await edition('$month'); const v = ed.variant('$variant');
  const tl = await readTimeline(ed.timeline('$variant'));
  console.log(ed.video + '/' + (v.scene ?? '$variant') + '.html', v.size ?? '1920x1080', tl.total, ed.out('$variant'), ed.work);")
mkdir -p "$work/renders"
../render-parallel.sh "$scene" "$total" "$work/renders/$variant.mp4" "$jobs" "$size"
./mix.sh "$month" "$variant" "$work/renders/$variant.mp4" "$out.mp4"
node chapters.mjs "$month" "$variant" > /dev/null
echo "$out.mp4"
