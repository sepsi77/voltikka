# Video of the monthly review (phase 5: video template)

Code: `videos/monthly-market-review/` (see its `AGENTS.md`). Paper: page "Video · Markkinakatsaus".

## Brief (user, 2026-11-06)

- A 3–5 min video of the October 2026 review (`prototype/2026-10-katsaus.md`), mostly for consumers, with some figures from the journalist layer.
- Make a handful of variations that test different concepts; the user reviews them in the morning and we iterate.
- Sellers can be named, because this is the monthly review: anything in the written review can be in the video (user, 2026-11-06).
- The agent worked alone overnight; Paper was optional for planning and storyboards.

## Decisions (agent, 2026-11-06, for review)

- Format: 16:9 1920×1080 for YouTube, Finnish synthetic voice (MAI Harper; Grant for the weather variant to test a second voice), music bed under the voice at −14 LUFS, an `.srt` subtitle file and a YouTube description with chapters for each video. No 9:16 cut yet.
- Four concepts, each a different answer to "how do we tell this month":
  1. **Katsaus** (5:29): the whole review in four chapters with a chapter rail. Tests the "complete monthly show" (consumer + journalist).
  2. **Kolme kotia** (3:32): only the advice, one home at a time. Tests a practical consumer video.
  3. **Numerot** (3:33): 18 big numbers, one per card, with progress rail. Tests a fast, sound-off-friendly format for social and Shorts-style cutdowns.
  4. **Sähkösää** (3:17): a TV weather forecast with the fox at the weather station, a map of Finland, a forecast strip, a "sähkövaroitus", the contract-market "ilmanpaine" and "näin varaudut". Tests a recurring branded series ("Voltikan sähkösää").
- The fox appears only in openers and end cards, never next to advice, a seller or a contract price (brand rule). The forecast and observatory plates have the fox in the painting; they are used only for openers and end cards.
- EEX: only single quoted values with source and date (October actual vs January future, November 11,3, December 11,2, Q1 14,5, year 2027 8,4), never a futures curve, because the EEX redistribution terms are not checked (`drivers-2026-10.json` note). The user earlier accepted six EEX values in the written review.
- Figures are exactly those of the draft and its JSON files, with three wording changes:
  - Nuclear: the draft says "lähes puolet ydinvoimasta oli huollossa", but availability was 46.5 %, so more than half was off. The video says "ydinvoimasta oli käytössä vain 46,5 prosenttia". ⟦TARKISTA: fix the draft sentence too⟧
  - The 150 € winter difference belongs to the typical 12-month fixed contract, not to the recommended 24-month contract, so the weather variant says "poistaa kalliin tammikuun riskin" instead.
  - The house without electric heating: the voice says "vajaat tuhat euroa"; the screen shows 950–970 €.
- Katsaus is 5:29, a little over the 5 min target. Cuts made: neighbour prices (screen only in Sähkösää), the 2028 futures sentence, the forecast of fixed-term prices. More cuts are possible if the user wants 5:00.
- Music: four Lyria 3 Pro beds; the agent cannot listen. Calm prompts were transcribed as "[on-hold music]", so two livelier beds were made for Kolme kotia and Sähkösää (`homes-b`, `weather-b`). The user chooses.

## Round 2 (user feedback 2026-11-06)

- The user prefers **Katsaus**. Work continues on it; the other three stay as they are.
- New order: hook ("Lokakuu oli halpa. Talvesta tulee kallis. Mikä sopimus nyt kannattaa?") → Talvi (futures, three reasons, Monday 51 c, worst week) → Mitä valita (three homes, quarterly) → Sopimukset → Lokakuu lyhyesti (spot review, daily chart, November outlook, warm-autumn lesson) → outro. Reason: the October spot review is the least interesting part for consumers; the advice now starts at 1:01 (was 2:30).
- Voice too slow and sleepy: MAI Harper now speaks at API speed 1.2 (2.1 words per second, was 1.6); pauses are shorter. Katsaus v2 is 4:24 (was 5:29).
- The voice never says a number with two decimals (user rule); it rounds ("alle viisi senttiä", "lähes 16 senttiä", "noin 1 150 euroa", "noin 70 euroa enemmän"). The screen keeps exact figures. This changed some shared lines (k_be02_*, k_q*), so Kolme kotia gets the rounded text when it is rebuilt.
- Voice test (user request): one passage with MAI Harper/Grant, ElevenLabs v4 (Sarah, Alice, George), v3, multilingual v2, Gemini 3.8 Flash TTS (Kore, Charon, Aoede), Fish Audio S2.1 Pro, Grok (Eve, Rex), and 1.15 time-stretched versions: `videos/monthly-market-review/audio/voice-test/listen-all.mp3`. Transcripts: all clean except Fish Audio (numbers mangled). OpenAI TTS is not on OpenRouter and the shell OpenAI key is invalid, so it was not tested. The user chooses by ear.

## Round 3 (user 2026-11-06)

- User on the voices: ElevenLabs v3/v4 and Gemini 3.8 are by far the best (natural; correct case of month numbers); v4 is a little slow, but good with the fast tag or a 1.15 stretch; Gemini has more emotion and punch; Charon sounds sped up; Fish and Grok are unusable (strong foreign accent); multilingual v2 sounds robotic.
- v2 felt rushed: the voice is one file per line, joined with 0.15–0.8 s pauses. Pauses are now 0.5–0.9 s inside a slide and 1.2–1.6 s at a slide or chapter change (a simple change; one file per slide with word timestamps stays an option).
- Full Katsaus with Gemini Kore (4:57) and ElevenLabs v4 Sarah with the fast tag (5:26): `final/voltikka-markkinakatsaus-2026-10-katsaus-kore.mp4` and `-katsaus-sarah.mp4`.
- Month numbers in a case other than the nominative are written in words in the voice. `k_q1` now says "kvartaalisopimusten" (site word rule) and `k_oct7` "Hinta oli korkeimmillaan" (Sarah said "kallain").
- Cost (OpenRouter list prices, estimate): one full Katsaus voice track about $0.32 with ElevenLabs v4, about $0.06 with Gemini 3.8 Flash TTS.

## Round 4 (user 2026-11-06)

- Voice: **Gemini Kore** ("more dynamic and interesting"). It is now the voice of the `katsaus` variant; the comparison variants are removed. Final: `final/voltikka-markkinakatsaus-2026-10-katsaus.mp4` (4:57); earlier versions are kept as `-v1-harper`, `-v2-harper` and `-v3-sarah`.
- "Cheapin": both voices say it correctly; only the transcription writes "Jeepin".

## Round 5 (user 2026-11-06, through the review-page session)

- The video uses the same charts as the site page (same frozen data `laravel/resources/market-reviews/2026-10/kuva-*.json`, same colours): the bill outlook has the three-winter whisker and the site labels; the October chart has three panels (price with month mean, wind, temperature); the price index shows only October with its four series and their October change beside the chart. The voice did not change. Final: `final/voltikka-markkinakatsaus-2026-10-katsaus.mp4`; the version before this change is `-v4-kore`.
- Index change (user rule via the review-page session): from the last day of the previous month to the last day of the month, so October includes the 1.10. resets: index +12,0 % (was +3,7 % from 2.10.), open-ended +10,5 %, quarterly/monthly +45,4 %, fixed-term +1,2 %. kuva-3.json now covers 30.9.–31.10. The video changed: `k_mkt3` now says "nousi lokakuussa 12 prosenttia. Suurin osa noususta tuli kuun vaihteessa, kun kvartaali- ja kuukausihintaisten sopimusten uudet hinnat alkoivat." (new Kore take, transcript clean), the `+12,0 %` figure, the change list and a 1.10. note on the chart.
- The +36 % rise from the May low is removed from the video (user 2026-11-06): the chart starts on 30.9. and cannot show May. `k_mkt4` is now "Myyjät hinnoittelevat tulevaa talvea, eivät mennyttä kuukautta." (new take, transcript clean). Length 5:00.
- Not rebuilt: Numerot/Sähkösää still say 3,7 % (`s_mkt1`).

- Electrically heated house (user 2026-11-06: follow the written version): the advice is now "pörssisähkö on pitkällä aikavälillä halvempi, mutta tuleva talvi on sille riski; kiinteä hinta antaa varmuuden talven laskusta, mutta siitä maksat kesällä". Winter: spot about 150 € more (cold winter 190 €); April–October fixed about 225 € more; year: spot about 75 € cheaper. If fixed: 24 months, Pohjois-Karjalan Sähkö Optimi takuu (about 1 500 €, Herrfors Spot+ 1 564 €), second year spot about 175 € cheaper (115 € over 24 months), 36 months about 290 € more over three years; load shifting about 77 €. Cheap 36 kk is no longer named. Quarterly line `k_q2`: about 155 €, "vaihda pörssisähköön tai 24 kuukauden kiinteään". New Kore takes k_be02_2…8 and k_q2 (transcripts clean; the transcription writes "optimitakuu" as one word). Length 5:25. Kolme kotia shares these lines and gets them when it is rebuilt.

- `final/` holds only the files to upload (Katsaus video, .srt, title, description, thumbnail). The earlier Katsaus versions and the Kolme kotia, Numerot and Sähkösää outputs are in `videos/monthly-market-review/out/archive/` (user 2026-11-06).

## Open for the user

- Which concept (or mix) to develop; length target; voice (Harper or Grant); music.
- Whether the weather format should become the monthly series name.
- A 9:16 cutdown (Numerot is the natural source).
- Paper boards show the rendered key frames of each variant (Paper cannot show the motion).

## Output (2026-11-06)

`videos/monthly-market-review/final/` (ignored by git): `voltikka-markkinakatsaus-2026-10-<variant>.mp4` (1920×1080, −14.0 LUFS), `.srt`, `-description.txt` (YouTube chapters) and `-thumbnail.jpg` (frame 0, 1280×720) for katsaus (5:29), kotia (3:32), numerot (3:33) and saa (3:17). Each voice line was transcribed; a sample minute of each mix was transcribed to check that the voice is clear over the music.
