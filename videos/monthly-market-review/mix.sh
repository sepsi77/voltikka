#!/usr/bin/env bash
# Adds the voice track and the music to a rendered video of one variant.
#
#   ./mix.sh <variant> <video.mp4> [out.mp4]
#
# The voice track is audio/<variant>/voice.wav (timeline.mjs). The music file and its level come
# from script.mjs; the music loops if the video is longer, is ducked under the voice and fades
# out at the end. Two-pass loudness normalisation to −14 LUFS (YouTube).
set -euo pipefail
cd "$(dirname "$0")"
variant="$1"; video="$2"; out="${3:-${video%.mp4}-mix.mp4}"
read -r music music_db total < <(node --input-type=module -e "
  import { VARIANTS } from './script.mjs';
  import { readFileSync } from 'node:fs';
  const v = VARIANTS['$variant']; globalThis.window = {};
  eval(readFileSync('timeline/$variant.js', 'utf8'));
  console.log(v.music, v.musicDb, window.TL.total);")
fade=$(python3 -c "print(max(0, $total - 3.0))")

inputs=(-i "$video" -i "audio/$variant/voice.wav" -stream_loop -1 -i "$music")
graph="
  [2:a]atrim=0:${total},asetpts=PTS-STARTPTS,aresample=48000,aformat=channel_layouts=stereo,afade=t=in:d=1.5,afade=t=out:st=${fade}:d=3,volume=${music_db}dB[m];
  [1:a]aresample=48000,aformat=channel_layouts=stereo,highpass=f=90,acompressor=threshold=-20dB:ratio=3:attack=5:release=80,volume=1.8,asplit=2[vo][key];
  [m][key]sidechaincompress=threshold=0.03:ratio=6:attack=20:release=500[ducked];
  [ducked][vo]amix=inputs=2:normalize=0,atrim=0:${total}"

stats=$(ffmpeg -hide_banner -nostats "${inputs[@]}" -filter_complex "${graph},loudnorm=I=-14:TP=-1.5:LRA=11:print_format=json[a]" -map "[a]" -f null - 2>&1 | sed -n '/^{/,/^}/p')
get() { echo "$stats" | sed -n "s/.*\"$1\" : \"\(.*\)\".*/\1/p"; }
ln="loudnorm=I=-14:TP=-1.5:LRA=11:measured_I=$(get input_i):measured_TP=$(get input_tp):measured_LRA=$(get input_lra):measured_thresh=$(get input_thresh):offset=$(get target_offset):linear=true"

ffmpeg -loglevel error -y "${inputs[@]}" -filter_complex "${graph},${ln},aresample=48000[a]" \
  -map 0:v -map "[a]" -c:v copy -c:a aac -b:a 192k -ar 48000 -shortest -movflags +faststart "$out"
echo "$out"
