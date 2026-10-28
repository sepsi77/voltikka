# Site feedback: "Löysitkö etsimäsi?"

Status: design (Paper boards) for user review. No code yet.

## Goal

Collect data on how well each page matches what visitors look for, and why visitors come to Voltikka. Use the data to improve UX.

## Flow (all pages)

1. Question "Löysitkö etsimäsi?" with thumbs up ("Kyllä") and thumbs down ("En"). The vote is saved at once.
2. After "En": page-specific reasons (one radio choice, list ends with "Jokin muu") + optional text (max 500 characters) → "Jatka" or "Ohita".
3. Goal question "Mitä olit tänään tekemässä?": after "Kyllä" it is the only follow-up; after "En" it is the last step. Goal codes come from one site-wide list; each page shows its five most likely goals + "Jokin muu" + optional text.
4. Thanks line.

Reasons and goals show in a new random order each time; "Jokin muu" stays last. No option is selected at the start. Each answer stores the shown position of the chosen option, so we can check for position bias. The question has one short line under it: "Vastauksesi auttaa meitä parantamaan palvelua."

Frequency: one answer hides the widget on every page for 30 days; "Ei nyt" hides it for 7 days (browser storage, safe failure).

Page map (placement, reasons, goals per page type): Paper page "Feedback · Löysitkö etsimäsi?", board "FB · Notes: page map, reasons and goals". Placement is tailored per page type; listings use ledger slot 5 (after entry 4) on page 1.

## User decisions (2026-10-28)

- Scope: all public pages (not /tietosuoja, /kayttoehdot).
- Reasons plus optional text.
- Questions are tailored to the page. Example: the solar calculator never offers contract reasons.
- Ask a goal question after "Kyllä" too, and as the last step in all cases.
- Placement is tailored per page type; not only at the end of the content.
- Free-text retention: 12 months.
- Paper boards first, then code.

## Technical direction (proposed, not approved yet)

- Plain JS widget (pages use shared cached HTML), not a Livewire component.
- New event type on `POST /api/analytics/events` (enum case, handler, validation; see `laravel/app/Services/Analytics/AGENTS.md`).
- One typed table (for example `page_feedback`): the vote creates the row by a browser UUID; follow-up steps update the same row once within a short time limit.
- Signed page context (route/page type and page facts) against spam, the existing rate limit, a length limit.
- Context: page type, path without query, vote, reason code, goal code, texts, device class, attribution source/medium, page facts (listing: pricing filter, consumption, result count, postcode used yes/no, bill mode; detail: contract ID and company). No visitor ID or session ID.
- Free text is personal data: update the privacy page, delete text after 12 months, never log payloads.
- Read-only Filament resource with filters and CSV download.
