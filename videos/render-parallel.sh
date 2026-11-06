#!/usr/bin/env bash
# Renders a long scene in parallel parts and joins them without re-encoding.
#
#   ./render-parallel.sh <scene.html> <duration s> <out.mp4> [jobs] [size]
#
# Each part is one render.mjs process with --from; the parts have the same encoder settings,
# so the concat demuxer joins them with stream copy. About 3 frames per second per job.
set -euo pipefail
scene="$1"; total="$2"; out="$3"; jobs="${4:-6}"; size="${5:-1920x1080}"
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(dirname "$out")/.parts-$(basename "$out" .mp4)"
rm -rf "$tmp"; mkdir -p "$tmp"
fps=30
frames=$(python3 -c "print(round($total*$fps))")
per=$(( (frames + jobs - 1) / jobs ))
pids=()
for ((i = 0; i < jobs; i++)); do
  f0=$(( i * per )); n=$(( frames - f0 < per ? frames - f0 : per ))
  (( n <= 0 )) && break
  node "$here/render.mjs" "$scene" --from "$(python3 -c "print($f0/$fps)")" --duration "$(python3 -c "print($n/$fps)")" \
    --size "$size" --out "$tmp/part-$i.mp4" > "$tmp/part-$i.log" 2>&1 &
  pids+=($!)
  echo "file 'part-$i.mp4'" >> "$tmp/list.txt"
done
for p in "${pids[@]}"; do wait "$p"; done
ffmpeg -loglevel error -y -f concat -safe 0 -i "$tmp/list.txt" -c copy -movflags +faststart "$out"
rm -rf "$tmp"
echo "$out"
