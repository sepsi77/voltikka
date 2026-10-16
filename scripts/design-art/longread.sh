# Long-read pages: /tietoa, legal pages, the two articles and the 404 page
# (Paper page "Pages · group F": boards 49Y-0, 38P-0, 6HX-0, AO6-0; seasons EDN-0).
# Sourced by scripts/build-design-art.sh; build only this fragment with
# `scripts/build-design-art.sh longread`.

# /tietoa hero: the fox at its desk (540x405 on the board). Indoor scene: only the window
# view changes by season.
season_set mascot/tietoa-fox-desk-v1.png mascot/tietoa-fox-desk-{season}-v1.png tietoa-fox-desk 1080

# Legal hero (/tietosuoja, /kayttoehdot): the night-sky plate and the fox asleep under a lamp
# (480x184 on the board). Neither file has season variants yet, so every season gets the
# original file.
season_set_fallback scenes/night-sky-plate-v1.png scenes/night-sky-plate-{season}-v1.png night-sky-plate 1672
for season in "${seasons[@]}"; do
    convert "$src/scenes/night-sky-plate-v1.png" "night-sky-plate-$season-960" 960
done
season_set_fallback mascot/legal-fox-asleep-stretched-v1.png mascot/legal-fox-asleep-stretched-{season}-v1.png legal-fox-asleep 960

# Article hero: the fox reads by the fire (560x420 on the board). Indoor, one file.
season_set_fallback mascot/article-fox-fireside-v1.png mascot/article-fox-fireside-{season}-v1.png article-fox-fireside 1120

# Article interlude strip: villagers play kyykkä (860 wide in the article column).
season_set scenes/article-interlude-kyykka-v1.png scenes/article-interlude-kyykka-{season}-v1.png interlude-kyykka 1672 960

# 404 hero: the fox lost in the forest with a lantern (full-bleed, 810 high on the board).
season_set scenes/404-fox-lost-forest-v1.png scenes/404-fox-lost-forest-{season}-v1.png 404-forest 1672 960
