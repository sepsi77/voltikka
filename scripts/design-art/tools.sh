# Tool pages: /sahkosopimus/laskuri (consumption calculator) and /maksatko-liikaa (bill comparison).
# Sourced by scripts/build-design-art.sh; build only this fragment with
# `scripts/build-design-art.sh tools`.

# Hero plates (Paper boards 4UL-0 / EBS-0 and 8X7-0 / EBT-0). The fox is part of the plate.
season_set scenes/laskuri-hero-meter-v1.png scenes/laskuri-hero-meter-{season}-v1.png laskuri-hero 1672 960
season_set scenes/maksatko-hero-bill-v1.png scenes/maksatko-hero-bill-{season}-v1.png maksatko-hero 1672 960

# Villager interlude strips (1000 wide on the boards).
season_set scenes/laskuri-interlude-firewood-v1.png scenes/laskuri-interlude-firewood-{season}-v1.png laskuri-interlude 1672 960
season_set scenes/maksatko-interlude-postman-v1.png scenes/maksatko-interlude-postman-{season}-v1.png maksatko-interlude 1672 960

# Calculator profile homes: 84px on the board (the listing's 96px files are too small for 2x).
for home in studio apartment rowhouse house; do
    season_set "illustrations/home-$home-v1.png" "illustrations/home-$home-{season}-v1.png" "tool-home-$home" 168
done
