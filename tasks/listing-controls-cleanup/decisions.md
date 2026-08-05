# Decisions

- **The postcode form is collapsed behind an availability disclosure (explicit
  user decision, 2026-08).** Most visitors never enter a postcode; even the
  compacted one-line form drew too much attention. The disclosure trigger
  states the current availability ("Saatavuus: koko Suomi" / the selected
  postcode plus helper sentence), so the fail-closed national-only scope stays
  glanceable without opening anything. The trigger anatomy matches the bill and
  filter disclosures, so the tools cluster is three consistent rows. "Poista
  postinumero" moved inside the panel with the input and suggestions.
- **The control stack order is a design decision, not an accident.** Primary
  choices (consumption, price behavior, availability) come first as one
  sequence; the two collapsed tools (bill, filters) follow as one cluster. The
  bill disclosure used to split consumption from the pills, which was the main
  source of the "busy" impression. Documented in `laravel/app/Livewire/AGENTS.md`.
- **Postcode selector demoted to one row.** It is a secondary refinement; the
  full labelled form made it the loudest element in the stack. The visible
  "Postinumero (5 numeroa)" label is `sr-only`; the helper sentence
  ("Lisää postinumero, niin saat mukaan alueelliset sopimukset.") and the
  placeholder ("Postinumero, esim. 00100") carry the affordance. All Livewire
  bindings, wire:target lists, localStorage Alpine logic, error/loading/
  suggestion states are unchanged.
- **"Tyhjennä suodattimet" moved inside the filters panel.** Outside the
  `x-show` region it rendered inside the collapsed bordered box whenever
  `hasActiveFilters()` was true (e.g. postcode or pill selected), looking like a
  floating orphan link. Pills and postcode have their own inline clear actions;
  accordion-hosted filters auto-open the panel, so the link is always reachable
  when relevant.
- **`SeoCityRoutesTest::test_city_page_shows_contracts_count` was already
  failing before this cleanup**: the in-flight postcode-eligibility work
  rewrote the results caption ("N sopimusta. 12 kk arvio sisältää tarjoukset…")
  and dropped the word "vertailussa" the test asserted. The assertion now
  checks the new caption copy.
- The consumption selector's `<h3>Vuosikulutus</h3>` became a `<p>` control
  label, matching the CompanyDetail decision that Vuosikulutus is a control
  label, not a content heading.
- A local gotcha hit during verification: `npm run build` replaces hashed CSS
  while the browser holds cached listing HTML (`max-age=300`) pointing at the
  old hash, so the page renders unstyled until a hard reload. Same mechanism as
  the documented production edge-cache race in `laravel/AGENTS.md`.
