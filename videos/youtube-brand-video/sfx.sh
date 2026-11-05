#!/usr/bin/env bash
# Makes the 15 s sound-effect track of one ad from synthetic sounds (no sample files).
#
#   youtube-brand-video/sfx.sh omalasku     # audio/omalasku/sfx.wav
#   youtube-brand-video/sfx.sh haku         # audio/haku/sfx.wav
#
# The event times match the scene: the key times in haku.html (`gaps`, `ctaStart`, `ctaGap`) and the
# animation delays in omalasku.html. If you change one, change both.
set -euo pipefail
cd "$(dirname "$0")"
ad="${1:?omalasku or haku}"
dir="audio/$ad/sfx-parts"
mkdir -p "$dir"
ff() { ffmpeg -loglevel error -y "$@"; }

# The sounds: a soft key click, an Enter key, a paper swish, a paper settle and a pen scribble.
ff -f lavfi -i "aevalsrc='0.5*exp(-t*260)*(sin(2*PI*1700*t)+0.6*(random(0)*2-1))':s=48000:d=0.05" -af "highpass=f=700,lowpass=f=7000" "$dir/click.wav"
ff -f lavfi -i "aevalsrc='0.7*exp(-t*120)*(sin(2*PI*900*t)+0.8*(random(0)*2-1))':s=48000:d=0.09" -af "highpass=f=300,lowpass=f=6000" "$dir/enter.wav"
ff -f lavfi -i "anoisesrc=d=0.5:c=pink:a=0.6:r=48000" -af "bandpass=f=2600:width_type=o:w=2,afade=t=in:d=0.18,afade=t=out:st=0.2:d=0.3,volume=0.8" "$dir/swish.wav"
ff -f lavfi -i "anoisesrc=d=0.25:c=brown:a=0.8:r=48000" -af "lowpass=f=900,afade=t=out:st=0.03:d=0.22,volume=0.9" "$dir/settle.wav"
ff -f lavfi -i "anoisesrc=d=0.6:c=white:a=0.5:r=48000" -af "bandpass=f=3200:width_type=o:w=1.5,tremolo=f=13:d=0.85,afade=t=in:d=0.05,afade=t=out:st=0.4:d=0.2,volume=0.55" "$dir/pen.wav"

# Events: "sound time gain".
events=()
if [ "$ad" = haku ]; then
  gaps=(0.09 0.08 0.1 0.07 0.09 0.08 0.13 0.08 0.07 0.09 0.08 0.1 0.07 0.08 0.09 0.07 0.08 0.1 0.09)
  t=0.75
  for g in "${gaps[@]}"; do events+=("click $t 0.5"); t=$(echo "$t + $g" | bc); done
  events+=("enter 2.45 0.7" "swish 3.05 0.6" "settle 5.9 0.4" "settle 10.0 0.6" "swish 10.9 0.5" "click 11.0 0.6")
  for n in $(seq 1 21); do events+=("click $(echo "12.2 + $n * 0.075" | bc) 0.4"); done
else
  events+=("swish 2.5 0.5" "swish 2.95 0.7" "swish 4.95 0.6" "settle 8.55 0.8" "pen 9.3 0.6" "swish 10.9 0.6")
fi

inputs=() ; filters="" ; labels=""
for i in "${!events[@]}"; do
  read -r sound at gain <<<"${events[$i]}"
  ms=$(printf '%.0f' "$(echo "$at * 1000" | bc)")
  inputs+=(-i "$dir/$sound.wav")
  filters+="[$i:a]volume=$gain,adelay=$ms|$ms,aformat=channel_layouts=stereo[e$i];"
  labels+="[e$i]"
done
ff "${inputs[@]}" -filter_complex "${filters}${labels}amix=inputs=${#events[@]}:normalize=0,apad=whole_dur=15,atrim=0:15[a]" -map "[a]" -ar 48000 "audio/$ad/sfx.wav"
echo "audio/$ad/sfx.wav (${#events[@]} events)"
