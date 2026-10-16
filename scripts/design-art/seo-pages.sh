# SEO listing page variants, city listings and the location index (round 2 area seo-pages;
# sourced by scripts/build-design-art.sh). Paper page "Pages · group E" and its season boards.
# Each set names its talvi file explicitly: the old file without a season name is not always
# the winter version (the Helsinki hero is syksy, the map is kesä).

# /sahkosopimus/porssisahko (boards 3C8-0, F8H-0): the fox on a jetty watching river ice is
# painted into the plate, so there is no fox cutout. Ice-fishing interlude.
season_set scenes/hero-porssisahko-talvi-v1.png scenes/hero-porssisahko-{season}-v1.png hero-porssisahko 1672 960
season_set scenes/interlude-villagers-icefishing-talvi-v1.png scenes/interlude-villagers-icefishing-{season}-v1.png interlude-icefishing 1672 960

# City listings /sahkosopimus/paikkakunnat/{location} (boards 5BI-0, F8I-0; review board
# "R3 · City heroes" on "Round 3 · revisions"): Helsinki, Tampere, Turku and Oulu have their own
# plate with their landmarks; every other city uses the general lakeside town plate. The fox is
# painted in at the same place in all of them, so one CSS crop fits. Sea-swim interlude.
season_set scenes/hero-paikkakunta-yleinen-talvi-v1.png scenes/hero-paikkakunta-yleinen-{season}-v1.png hero-city 1672 960
for city in helsinki tampere turku oulu; do
    season_set "scenes/hero-paikkakunta-$city-talvi-v1.png" "scenes/hero-paikkakunta-$city-{season}-v1.png" "hero-city-$city" 1672 960
done
season_set scenes/interlude-villagers-seaswim-talvi-v1.png scenes/interlude-villagers-seaswim-{season}-v1.png interlude-seaswim 1672 960

# Location index /sahkosopimus/paikkakunnat (boards 887-0, F8J-0): the painted map (500 wide
# on the board), the fox with a compass (220) and the ski-trip interlude.
season_set illustrations/map-finland-talvi-v1.png illustrations/map-finland-{season}-v1.png map-finland 1000 600
season_set mascot/fox-compass-talvi-v1.png mascot/fox-compass-{season}-v1.png fox-compass 440
season_set scenes/interlude-villagers-skitrip-talvi-v1.png scenes/interlude-villagers-skitrip-{season}-v1.png interlude-skitrip 1672 960
