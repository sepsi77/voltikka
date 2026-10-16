# Shared shell, /sahkosopimus listing and /spot-price art (sourced by scripts/build-design-art.sh).

# Listing hero (/sahkosopimus and every SeoContractsList page).
season_set scenes/village-dusk-plate-v1.png scenes/listing-hero-plate-{season}-v1.png listing-hero 1672 960
season_set mascot/fox-walk-lantern-v1.png mascot/fox-walk-lantern-{season}-v1.png fox-walk-lantern 320

# Footer scene and the listing interlude strip.
season_set scenes/footer-night-village-v1.png scenes/footer-village-{season}-v1.png footer 1672 960
season_set scenes/interlude-villagers-walk-v1.png scenes/interlude-villagers-walk-{season}-v1.png interlude-walk 1672 960

# Consumption choice homes (44px in the controls block; 96px covers 2x screens).
for home in studio apartment rowhouse house; do
    season_set "illustrations/home-$home-v1.png" "illustrations/home-$home-{season}-v1.png" "home-$home" 96
done

# /spot-price hero (Paper boards 11H-0 and EBO-0): the picture-book village plate and the
# fox that sits and looks up at the sky (250px on the board; 500 covers 2x screens).
season_set scenes/winter-evening-village-v2.png scenes/spot-hero-village-{season}-v1.png spot-hero 1672 960
season_set mascot/fox-sit-skyward-v1.png mascot/fox-sit-skyward-{season}-v1.png fox-sit-skyward 500

# /spot-price villagers. The owl heads the hourly price section (96px vignette); the others
# do the household tasks in "Kodin energiavinkit" (180px). Some villagers have no variant for
# every season: the board (EBO-0) then shows the talvi file, so the script copies it.
season_set_fallback illustrations/villager-owl-watch-v1.png illustrations/villager-owl-watch-{season}-v1.png villager-owl-watch 192 kevat kesa syksy
season_set_fallback illustrations/villager-ev-v1.png illustrations/villager-ev-{season}-v1.png villager-ev 360 kevat kesa syksy
season_set_fallback illustrations/villager-sauna-v1.png illustrations/villager-sauna-{season}-v1.png villager-sauna 360
season_set_fallback illustrations/villager-laundry-v1.png illustrations/villager-laundry-{season}-v1.png villager-laundry 360 kesa
season_set_fallback illustrations/villager-dishwasher-v1.png illustrations/villager-dishwasher-{season}-v1.png villager-dishwasher 360 kesa
season_set_fallback illustrations/villager-waterheater-v1.png illustrations/villager-waterheater-{season}-v1.png villager-waterheater 360 kesa
