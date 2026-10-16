# Energy area art: /aurinkopaneelit/laskuri and /lampopumput/laskuri (sourced by scripts/build-design-art.sh).

# Solar hero (Paper boards 3WH-0 and EKC-0). The summer file is the original midnight-sun plate
# (solar-hero-midnight-sun-v1.png = solar-hero-kesa-v1.png); talvi has its own file.
season_set scenes/solar-hero-talvi-v1.png scenes/solar-hero-{season}-v1.png solar-hero 1672 960
season_set scenes/solar-interlude-talvi-v1.png scenes/solar-interlude-{season}-v1.png solar-interlude 1672 960

# Heat pump hero (Paper boards C1D-0 and EBU-0). The file without a season name is talvi.
season_set scenes/lampopumppu-hero-frost-v1.png scenes/lampopumppu-hero-frost-{season}-v1.png heatpump-hero 1672 960
season_set scenes/lampopumppu-interlude-snowlanterns-v1.png scenes/lampopumppu-interlude-snowlanterns-{season}-v1.png heatpump-interlude 1672 960
