#!/usr/bin/env bash
# Renders and mixes all four variants into out/archive/ (final/ holds only the published Katsaus) (two at a time, 10 jobs in total).
set -euo pipefail
cd "$(dirname "$0")"
tot() { node -e "global.window={};eval(require('fs').readFileSync('timeline/$1.js','utf8'));console.log(window.TL.total)"; }
one() { ../render-parallel.sh "$1.html" "$(tot "$1")" "out/$1-$2.mp4" "$3" && ./mix.sh "$1" "out/$1-$2.mp4" "out/archive/voltikka-markkinakatsaus-2026-10-$1.mp4"; }
tag="${1:-v3}"
one katsaus "$tag" 6 & one numerot "$tag" 4 & wait
one kotia "$tag" 6 & one saa "$tag" 4 & wait
node chapters.mjs > /dev/null
ls -la out/archive/
