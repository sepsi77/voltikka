#!/usr/bin/env bash
# Adds the voice track and the music to a rendered video of one variant.
#
#   ./mix.sh <month> <variant> <video.mp4> [out.mp4]
#
# The voice track is work/audio/<variant>/voice.wav of the edition (timeline.mjs). The music file
# (relative to this folder) and its level come from the edition's video/script.mjs; the music loops
# if the video is longer, is ducked under the voice and fades out at the end. Two-pass loudness
# normalisation to −14 LUFS (YouTube).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
month="$1"; variant="$2"; video="$(cd "$(dirname "$3")" && pwd)/$(basename "$3")"; out="${4:-${video%.mp4}-mix.mp4}"
read -r voice music music_db total < <(cd "$here" && node --input-type=module -e "
  import { edition, readTimeline } from './edition.mjs';
  const ed = await edition('$month'); const v = ed.variant('$variant');
  const tl = await readTimeline(ed.timeline('$variant'));
  console.log(ed.work + '/audio/$variant/voice.wav', ed.music(v), v.musicDb, tl.total);")
fade=$(python3 -c "print(max(0, $total - 3.0))")

inputs=(-i "$video" -i "$voice" -stream_loop -1 -i "$music")
graph="
  [2:a]atrim=0:${total},asetpts=PTS-STARTPTS,aresample=48000,aformat=channel_layouts=stereo,afade=t=in:d=1.5,afade=t=out:st=${fade}:d=3,volume=${music_db}dB[m];
  [1:a]aresample=48000,aformat=channel_layouts=stereo,highpass=f=90,acompressor=threshold=-20dB:ratio=3:attack=5:release=80,volume=1.8,asplit=2[vo][key];
  [m][key]sidechaincompress=threshold=0.03:ratio=6:attack=20:release=500[ducked];
  [ducked][vo]amix=inputs=2:normalize=0,atrim=0:${total}"

stats=$(ffmpeg -hide_banner -nostats "${inputs[@]}" -filter_complex "${graph},loudnorm=I=-14:TP=-1.5:LRA=11:print_format=json[a]" -map "[a]" -f null - 2>&1 | sed -n '/^{/,/^}/p')
get() { echo "$stats" | sed -n "s/.*\"$1\" : \"\(.*\)\".*/\1/p"; }
ln="loudnorm=I=-14:TP=-1.5:LRA=11:measured_I=$(get input_i):measured_TP=$(get input_tp):measured_LRA=$(get input_lra):measured_thresh=$(get input_thresh):offset=$(get target_offset):linear=true"

mkdir -p "$(dirname "$out")"
ffmpeg -loglevel error -y "${inputs[@]}" -filter_complex "${graph},${ln},aresample=48000[a]" \
  -map 0:v -map "[a]" -c:v copy -c:a aac -b:a 192k -ar 48000 -shortest -movflags +faststart "$out"
echo "$out"
