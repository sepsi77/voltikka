# Open Graph share images (sourced by scripts/build-design-art.sh, after every other fragment,
# because it reads the hero WebP files they write). Paper board "R3 · OG images".
#
# Output: laravel/public/images/og/<art>-<season>.jpg (1200 x 630, JPEG for crawler support)
# and og/default.jpg (the talvi home hero) for App\Support\Season::ogImage(). Each image is the
# hero art with the wordmark tag design/logo/og-wordmark-tag.png. That tag is a 2x browser
# render of design/logo/og-wordmark-tag.html (screenshot the #tag box at device scale 2);
# render it again only when the wordmark changes.
#
# Requires uv (Pillow is installed on the fly). Add an art name here when a new page hero
# should have its own share image; pages whose art is not listed get the home hero.

og_out="$root/laravel/public/images/og"
mkdir -p "$og_out"

og_art=(
    home-hero listing-hero cheapest-hero contract-band company-band companies-hero
    laskuri-hero maksatko-hero solar-hero heatpump-hero stats-hero forecast-hero spot-hero
    hero-porssisahko hero-city hero-city-helsinki hero-city-tampere hero-city-turku hero-city-oulu
)

og_pairs=()
for art in "${og_art[@]}"; do
    for season in "${seasons[@]}"; do
        og_pairs+=("$out/$art-$season.webp" "$og_out/$art-$season.jpg")
    done
done
og_pairs+=("$out/home-hero-talvi.webp" "$og_out/default.jpg")

uv run --quiet --with pillow python -I "$root/scripts/design-art/og_image.py" \
    "$src/logo/og-wordmark-tag.png" "${og_pairs[@]}"

du -sh "$og_out"
