# Remotion public offer facts

Read the repository root `AGENTS.md` and the Remotion skill before changing video code.

WeeklyOffers receives canonical facts from Laravel's `WeeklyOffersVideoService`. Preserve the
separate actual-price `is_estimate` and offer `benefit_is_estimate` flags. Exact actual prices can
still have projected normal-price savings. Such benefits must say `arvioitu säästö` or `ARVIOITU ETU`
and state that normal prices can change and savings are not guaranteed. A short-term benefit uses
its real term; annualized totals are comparison values. Do not derive savings from current rates,
source prose or source quotes. Legacy API data keeps its existing branch.

Canonical `base_only_hybrid` offers must visibly say `Ei sisällä kulutusvaikutusta` below the
price heading. Preserve the annualized comparison heading and real-term benefit independently.
Do not infer this classification from a product name.

`PromoScene` and `SignOffScene` run inside Sequences: local frame zero must not force the complete
thumbnail state. Use the existing spring entrance at that boundary. A local zero is not the global
TitleScene thumbnail; forcing springs to one caused a full-scene flash followed by a blank frame.
TitleScene now stays in its settled state from frame zero, including its footer. Do not restore a
thumbnail-only frame-zero shortcut: the full offline movie exposed a complete-to-empty reset.

`WeeklyOffers/timing.ts` shares the 25-second duration with Root and the composition. The carousel
receives its 18-second budget; three production offers have six seconds each. Do not truncate input
in the video. Section starts remain 0, 75, 615 and 690 at 30 fps. Retain outgoing sections/cards for
15 extra frames while `SceneFade` fades the entire incoming scene, with its original spring entry.
The last carousel card also stays through the promo overlap. Overlaps do not shorten the budget.

Important price headings, normal-benefit warnings, Hybrid exclusions and consumption labels are at
least 36 source pixels (12 pixels at 360 wide). Use `Vuositasolle laskettu vertailuhinta` for short-term
annualized comparison totals. Keep actual-price and normal-benefit certainty separate. Dark warning
text on coral and light labels on the dark price panel improve contrast without reducing font size.

Full offline correction evidence is in `tasks/source-validated-energy-rules/video-release-fixes.md`.
The earlier bounded samples did not detect all empty cuts; preserve that history in
`full-video-release-check.md`. Synthetic local rendering does not prove a real feed, remote logos,
social compression, physical-device reading time or general release readiness.

`npm run lint` runs ESLint and TypeScript. `npm run build` bundles the video. Do not install missing
dependencies or fetch remote render assets in a no-network task.
