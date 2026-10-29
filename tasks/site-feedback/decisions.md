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

## 2026-10-28 — production release

- User approved the deployment. Commit `4a48943`, image `deploy-000006-4a489437a2ff` (PHP 8.4.27), Railway deployment `bc1a94c3-11ba-4a99-bc6c-389f5a2a3233` SUCCESS at about 13:48 Helsinki.
- Retained the previous production `app-CSDxisPt.css` and `app-Pr_ebGQi.js` (fetched from production; hashes in `laravel/AGENTS.md`). Both still answer 200 after the release.
- Checks after the release: `/up` 200; widget on `/sahkosopimus` (listing), `/tietoa`, `/sahkosopimus/sahkoyhtiot` and the 404 page; privacy page has the new text; the endpoint rejects an invalid feedback context with 422; the deploy log shows the `page_feedback` migration DONE. No test answer was written to production.
- Open: add the Plausible goals `Feedback Vote` / `Feedback Reason` / `Feedback Goal` and the custom properties (user, Plausible dashboard).

## 2026-10-29 — admin attribution columns

- Plausible check (user report: one admin row, no Plausible event): production sends `Feedback Vote` with its props (checked with both requests mocked). Plausible shows custom events only after the goals exist; ad blockers can also block Plausible but not the first-party endpoint.
- The admin table now shows source, medium and landing page (campaign hidden), on user request. The data was already stored. Commit `35b2360`, image `deploy-000007-35b2360eb80a` (PHP 8.4.27), Railway deployment `e3b7195c-ee49-427d-90d5-bbaec8657ae4` SUCCESS at about 12:16 Helsinki; `/up` 200.
