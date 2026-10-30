#!/usr/bin/env bash
# Adds voice and music to the rendered Hintalappu video.
#
#   hintalappu/mix.sh <voice> [video] [music]
#   hintalappu/mix.sh harper out/hintalappu-v3.mp4 audio/music-a.mp3
#
# Voice lines are audio/vo-trim/<voice>-{1..4}.wav (silence trimmed). Each line starts at a
# fixed time that matches the headline timing in index.html. The music is ducked under the
# voice, and the mix is normalised to -14 LUFS, the YouTube playback level.
set -euo pipefail
cd "$(dirname "$0")/.."

voice="${1:?voice name, for example harper}"
video="${2:-out/hintalappu-v3.mp4}"
music="${3:-audio/music-a.mp3}"
out="${video%.mp4}-${voice}.mp4"

# Line start times in milliseconds: 0.45 s, 4.45 s, 7.6 s, 12.3 s.
starts=(450 4450 7600 12300)

ffmpeg -loglevel error -y -i "$video" -i "$music" \
  -i "audio/vo-trim/${voice}-1.wav" -i "audio/vo-trim/${voice}-2.wav" \
  -i "audio/vo-trim/${voice}-3.wav" -i "audio/vo-trim/${voice}-4.wav" \
  -filter_complex "
    [1:a]atrim=0:15,asetpts=PTS-STARTPTS,aresample=48000,aformat=channel_layouts=stereo,afade=t=out:st=14.1:d=0.9[m];
    [2:a]adelay=${starts[0]}|${starts[0]}[v1];
    [3:a]adelay=${starts[1]}|${starts[1]}[v2];
    [4:a]adelay=${starts[2]}|${starts[2]}[v3];
    [5:a]adelay=${starts[3]}|${starts[3]}[v4];
    [v1][v2][v3][v4]amix=inputs=4:normalize=0,apad=whole_dur=15,aresample=48000,aformat=channel_layouts=stereo,
      highpass=f=90,acompressor=threshold=-20dB:ratio=3:attack=5:release=80,volume=1.8,asplit=2[vo][key];
    [m][key]sidechaincompress=threshold=0.03:ratio=8:attack=15:release=350[ducked];
    [ducked][vo]amix=inputs=2:normalize=0,loudnorm=I=-14:TP=-1.5:LRA=9,atrim=0:15[a]" \
  -map 0:v -map "[a]" -c:v copy -c:a aac -b:a 192k -ar 48000 -movflags +faststart "$out"

echo "$out"
