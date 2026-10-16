# /sahkosopimus/tilastot and /sahkosopimus/sahkon-hintaennuste art (data area; sourced by
# scripts/build-design-art.sh). Paper boards 6MP-0 / FL5-0 (tilastot) and D8Q-0 / G4L-0
# (hintaennuste). Every file has its own season variant in design/scenes/.

# Hero plates: the observatory on the hill (fox with a telescope) and the weather station hill.
season_set scenes/stats-hero-talvi-v1.png scenes/stats-hero-{season}-v1.png stats-hero 1672 960
season_set scenes/forecast-hero-talvi-v1.png scenes/forecast-hero-{season}-v1.png forecast-hero 1672 960

# Villager interlude strips: the berry market (tilastot) and the laundry and kite (hintaennuste).
season_set scenes/stats-interlude-talvi-v1.png scenes/stats-interlude-{season}-v1.png stats-interlude 1672 960
season_set scenes/forecast-interlude-talvi-v1.png scenes/forecast-interlude-{season}-v1.png forecast-interlude 1672 960
