# Pitches to journalists

One file for each pitch: `YYYY-MM-DD-firstname-lastname-outlet.md`. The date is the planned send date.

## Format

- **Hook only when it is natural.** Refer to the journalist's earlier story only if our figure really continues it (example: Mäkilä). Do not force a link. If there is no natural link, use an angle that interests electricity consumers, with a news hook (a price spike, the start of winter, the end of the year). Check bylines in `../journalist-research/perplexity-byline-check-2026-10-31.md`.
- **Start with who you are and why you write:** "olen Seppo Puusa Voltikasta, joka vertailee sähkösopimuksia ja seuraa niiden hintoja päivittäin. Otan yhteyttä, koska…". The reason must be true: a confirmed story by the journalist, or a reader angle if there is none.
- **Short email (about 100–160 words).** One hook, two or three figures that fit that story, one line about the open data (CC BY 4.0, daily updates), one offer of more figures. Nothing more.
- **One angle for each journalist.** Do not send the same figure to two reporters.
- Each file has: why this journalist, the figure, when to send, the email, backup figures (send only after a reply) and the checks before sending.

## Rules

- **One pitch for each outlet in one week.** Two pitches from the same source in one newsroom look like spam, and the desk can merge them.
- Before you send: run `scripts/sync-production-database.sh`, then the scripts, and update the figures. Open the article you refer to and confirm the byline.
- Do not mention Energiavirasto in pitch text.
- Do not pitch our own forecasts. Futures are "the market price", never a forecast.
- Say "ero tukkuhintaan" or "vähittäispreemio", never "kate" or "voitto".
- The journalist list and the story-type codes are in `../journalist-research/journalist-list.md`. The general story ideas are in `../2026-10-21-story-pitches.md`; its "Do not pitch" list applies here too.

## Scripts

Read-only, from the repository root:

- `python3 -I media/pitches/scripts/spot_vs_fixed.py laravel/database/database.sqlite` — spot against fixed: break-even, rolling periods, futures-implied spot.
- `python3 -I media/pitches/scripts/contract_market.py laravel/database/database.sqlite` — monthly table, fixed price against the futures strip, open-ended contracts, 15-minute prices.

## Send plan

| Week | Journalist | Outlet | Angle |
|---|---|---|---|
| 1 | Ville Mäkilä | Verkkouutiset | Spot must average 11.4 c for today's fixed contract to win |
| 1 | Petri Sallinen | Energiauutiset | Fixed prices rose faster than futures: spread 1.8 → 2.6 c |
| 1 | Ossi Rajala | MTV Uutiset | The price of certainty: 120 €/year (260 € with heating) |
| 1 | Pekka Pantsu | Yle | Supply-obligation electricity up to 65 % above the fixed median |
| 2 | Juha Virranniemi | Yle | An old open-ended contract in euros, plus `/maksatko-liikaa` |
| 2 | Anna Egutkina | MTV Uutiset | Choosing a fixed contract: 24 months is cheaper than 12; 12-month offers cost 610–810 € (consumer angle) |
| 3 | Minna Pantzar | Yle | Chart data: cheap spot, rising fixed prices |
| Mid-December | Satu Koistinen | MTV Uutiset | The year 2026 in contract prices, for her year-end story |

## Not written yet, and why

- **Petri Lajunen, Antti Korhonen, Akseli Lehtonen (Verkkouutiset):** Mäkilä goes first at this outlet. Lehtonen fits the monthly scorecard (P4) when it exists; Korhonen fits P5.
- **Aliisa Uusitalo (STT):** P2 is not ready. On 2026-10-23 only 5 active contracts are flagged, not 24. STT is the widest channel, so give it the monthly scorecard (P4) when it exists.
- **Petri Vironen (Yle South Savo):** Suur-Savon Sähkö has no consumer contracts in our data. Find out which brand sells its electricity first.
- **Anne Lilja (Kaleva), Mikko Kormilainen (MTV), Anni Kavander (Yle):** weak fit or old articles. Wait for P4.
