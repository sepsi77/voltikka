#!/usr/bin/env bash
# Adds voice, music and (optional) sound effects to a rendered 15 s video.
#
#   youtube-brand-video/mix.sh <voice> [video] [music] [voice dir] [starts ms] [sfx] [music dB]
#   youtube-brand-video/mix.sh harper out/hintalappu-v4.mp4                          # Hintalappu (defaults)
#   youtube-brand-video/mix.sh harper out/omalasku-v1.mp4 audio/omalasku/music.mp3 \
#     audio/omalasku/vo-trim "400 2600 6300 12300" audio/omalasku/sfx.wav -5
#   youtube-brand-video/mix.sh harper out/haku-v1.mp4 audio/haku/music.mp3 \
#     audio/haku/vo-trim "400 2600 6200 11500" audio/haku/sfx.wav
#
# Paths are relative to this project folder.
#
# Voice lines are <voice dir>/<voice>-{1..4}.wav (silence trimmed). Each line starts at a fixed
# time that matches the headline timing in the scene. The music is ducked under the voice, and
# the mix is normalised to -14 LUFS, the YouTube playback level.
set -euo pipefail
cd "$(dirname "$0")"

voice="${1:?voice name, for example harper}"
video="${2:-out/hintalappu-v4.mp4}"
music="${3:-audio/music-a.mp3}"
vodir="${4:-audio/vo-trim}"
# Hintalappu line start times in milliseconds: 0.45 s, 4.45 s, 7.6 s, 12.3 s.
read -r -a starts <<<"${5:-450 4450 7600 12300}"
sfx="${6:-}"
# Music level before the mix, in dB (0 = as generated). A busy clip can cover the voice even when ducked.
music_db="${7:-0}"
out="${video%.mp4}-${voice}.mp4"

# Without a sound-effect track, use silence so the filter graph stays the same.
if [ -n "$sfx" ]; then sfx_in=(-i "$sfx"); else sfx_in=(-f lavfi -t 15 -i "anullsrc=r=48000:cl=stereo"); fi

inputs=(-i "$video" -i "$music" -i "$vodir/${voice}-1.wav" -i "$vodir/${voice}-2.wav" \
  -i "$vodir/${voice}-3.wav" -i "$vodir/${voice}-4.wav" "${sfx_in[@]}")
graph="
    [1:a]atrim=0:15,asetpts=PTS-STARTPTS,aresample=48000,aformat=channel_layouts=stereo,afade=t=out:st=14.1:d=0.9,volume=${music_db}dB[m];
    [2:a]adelay=${starts[0]}|${starts[0]}[v1];
    [3:a]adelay=${starts[1]}|${starts[1]}[v2];
    [4:a]adelay=${starts[2]}|${starts[2]}[v3];
    [5:a]adelay=${starts[3]}|${starts[3]}[v4];
    [v1][v2][v3][v4]amix=inputs=4:normalize=0,apad=whole_dur=15,aresample=48000,aformat=channel_layouts=stereo,
      highpass=f=90,acompressor=threshold=-20dB:ratio=3:attack=5:release=80,volume=1.8,asplit=2[vo][key];
    [m][key]sidechaincompress=threshold=0.03:ratio=8:attack=15:release=350[ducked];
    [6:a]aresample=48000,aformat=channel_layouts=stereo,volume=0.6[fx];
    [ducked][vo][fx]amix=inputs=3:normalize=0,atrim=0:15"

# Two-pass loudness: measure first, then normalise with the measured values, so every ad lands at -14 LUFS.
stats=$(ffmpeg -hide_banner -nostats "${inputs[@]}" -filter_complex "${graph},loudnorm=I=-14:TP=-1.5:LRA=9:print_format=json[a]" -map "[a]" -f null - 2>&1 | sed -n '/^{/,/^}/p')
get() { echo "$stats" | sed -n "s/.*\"$1\" : \"\(.*\)\".*/\1/p"; }
ln="loudnorm=I=-14:TP=-1.5:LRA=9:measured_I=$(get input_i):measured_TP=$(get input_tp):measured_LRA=$(get input_lra):measured_thresh=$(get input_thresh):offset=$(get target_offset):linear=true"

ffmpeg -loglevel error -y "${inputs[@]}" -filter_complex "${graph},${ln},aresample=48000[a]" \
  -map 0:v -map "[a]" -c:v copy -c:a aac -b:a 192k -ar 48000 -movflags +faststart "$out"

echo "$out"
