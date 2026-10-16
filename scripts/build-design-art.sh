#!/usr/bin/env bash
# Converts the approved redesign art (design/) into the WebP files the Laravel app serves
# from laravel/public/images/art/. Run it again after art in design/ changes.
#
# Output names are <name>-<season>[-<width>].webp, with season talvi|kevat|kesa|syksy.
# App\Support\Season::art() builds the same names. Source files keep the design/ naming:
# the file without a season name is the talvi (or original-season) version.
#
# Requires cwebp (brew install webp).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
src="$root/design"
out="$root/laravel/public/images/art"
mkdir -p "$out"

seasons=(talvi kevat kesa syksy)

# convert <source png> <output name without .webp> <width> [quality]
convert() {
    local input="$1" name="$2" width="$3" quality="${4:-78}"
    if [[ ! -f "$input" ]]; then
        echo "missing source: $input" >&2
        exit 1
    fi
    cwebp -quiet -mt -q "$quality" -alpha_q 90 -resize "$width" 0 "$input" -o "$out/$name.webp"
}

# season_source <talvi file> <pattern with {season}> <season>
season_source() {
    local talvi="$1" pattern="$2" season="$3"
    if [[ "$season" == "talvi" ]]; then
        echo "$src/$talvi"
    else
        echo "$src/${pattern//\{season\}/$season}"
    fi
}

# season_set <talvi file> <pattern> <output name> <width...>
# The first width is the default file; other widths get a -<width> suffix.
season_set() {
    local talvi="$1" pattern="$2" name="$3"
    shift 3
    local widths=("$@")
    for season in "${seasons[@]}"; do
        local input
        input="$(season_source "$talvi" "$pattern" "$season")"
        convert "$input" "$name-$season" "${widths[0]}"
        for width in "${widths[@]:1}"; do
            convert "$input" "$name-$season-$width" "$width"
        done
    done
}

# season_set_fallback <talvi file> <pattern> <output name> <width> <seasons with a variant...>
season_set_fallback() {
    local talvi="$1" pattern="$2" name="$3" width="$4"
    shift 4
    local own=" $* "
    for season in "${seasons[@]}"; do
        local input="$src/$talvi"
        if [[ "$season" != "talvi" && "$own" == *" $season "* ]]; then
            input="$src/${pattern//\{season\}/$season}"
        fi
        convert "$input" "$name-$season" "$width"
    done
}

# Art sets. core.sh holds the shell, listing and spot art; each redesign area owns one fragment in scripts/design-art/ so that areas
# can add art without editing this file. Pass a fragment name (for example `home`) to build
# only that fragment.
for part in "$root"/scripts/design-art/*.sh; do
    if [[ $# -eq 0 || "$(basename "$part" .sh)" == "$1" ]]; then
        # shellcheck source=/dev/null
        source "$part"
    fi
done

du -sh "$out"
