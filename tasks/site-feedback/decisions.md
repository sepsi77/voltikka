# Decisions

## 2026-10-28 — first design round

- The user decided: all pages, reasons + optional text, 12-month text retention, Paper first, page-tailored questions and placement, a goal question after both answers (always last).
- Listing slot 5 keeps the contract rank numbers (4, then the question, then 5). The entry has no category line and no price, and the kicker "Voltikka kysyy" marks it as not a contract.
- No villager art inside a listing or near a contract (design spec §7: villagers never next to a contract or seller). Calculator pages may use a villager vignette at the left of the question.
- A selected vote is drawn as the fox-filled circle, the same "selected" signal as the radios.
- The text field warns against personal data ("Älä kirjoita nimeäsi, puhelinnumeroasi tai sähköpostiosoitettasi.") because free text is personal data.
- Reason choice is a single radio, so the reason counts stay simple to compare.

## 2026-10-28 — user review 1

- Removed "Yksi napautus riittää." The line under the question is now only "Vastauksesi auttaa meitä parantamaan palvelua." (user).
- Reasons and goals show in random order to reduce first-option bias; "Jokin muu" stays last. No option is pre-selected (user). We also store the shown position of the chosen option so the bias can be measured.

## 2026-10-28 — user review 2 and implementation

- "Valitse lähin vaihtoehto" is not proper Finnish; the text is "Valitse parhaiten sopiva vaihtoehto." (user).
- Technical direction approved (user). Plausible events added on user request: `Feedback Vote`, `Feedback Reason`, `Feedback Goal`, props `page_type` + the code (`text_only` when only text was written). Never free text.
- The generic endpoint envelope no longer requires `placement`; the contract-click handler still requires it. Each typed handler keeps only the keys it validated.
- Signing is shared through `AnalyticsContextToken` (purpose-separated keys), the CSV export through `AnalyticsTableCsvExport`, normalization through `NormalizesAnalyticsInput`. Contract-click tests are unchanged except the envelope test.
- Listing facts are unsigned (display state, read from `data-feedback-facts` on the ledger at vote time); only page type and subject are signed.
- Follow-ups are accepted once, within 120 minutes, on the same page type. Late or repeated follow-ups are quiet no-ops so a retry cannot overwrite an answer.
- The home page was not on the board page map; it got its own small reason set (`home`) and the widget after the first section.
- The 404 lead reads "Vastauksesi auttaa meitä korjaamaan puuttuvat sivut ja linkit." because the generic goal lead did not fit there.
- The spot page shows the widget only when hourly prices exist (it sits in that branch); bill comparison, solar, heat pump and consumption calculator only after a result.
- A `<fieldset>`/`<legend>` broke the flex layout (the legend ignores the gap); the steps use `role="group"` with `aria-labelledby` on an `h2` instead.
- Release note: CSS and JS bundles changed. Retain the currently deployed asset files before the next release (laravel/AGENTS.md "Edge HTML caching vs hashed assets").
