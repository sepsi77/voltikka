# Decisions

## Why this keyword

`sähkösopimusten hintakehitys` is a high-intent Finnish query for "electricity contract price development/trend". The hintatilastot page already visualizes that exact thing (daily trend of energy prices and annual cost across contract types), so on-page optimization is a low-cost, on-topic win. No new content was needed — only surfacing the phrase in ranking-critical slots.

## Slot-by-slot copy choices

- **`<title>`**: `Sähkösopimusten hintakehitys ja hintatilastot Suomessa | Voltikka`
  - Leads with primary keyword. Combines with the existing supporting keyword `hintatilastot` because both share the same SERP intent and the combined phrase still reads naturally in Finnish.

- **Meta description**: `Sähkösopimusten hintakehitys ja hintatilastot Suomessa. Seuraa pörssi-, määräaikais- ja toistaiseksi voimassa olevien sopimusten hintojen kehitystä, hintaeroja ja vuosikustannuksia eri kulutustasoilla.`
  - Primary keyword at the start. Lists the contract-type axes that the page actually covers so the snippet matches user intent.

- **H1**: `Sähkösopimusten hintakehitys: mitä suomalaiset oikeasti maksavat sähköstä`
  - Keeps the original editorial "what people actually pay" hook from the redesign spec, but the keyword now opens the heading instead of trailing inside the dek.
  - Extra word "sähköstä" added so the rhythm matches the original `max-w-[28ch]` constraint.

- **Intro/dek**: leads with `sähkösopimusten hintakehitystä päivittäin` and explains what the trend actually shows. Replaces the previous "Aineisto kasvaa kuukausittain, näytämme sen mitä on kerätty" line, which leaked operational status (incomplete dataset) instead of describing the value.

- **JSON-LD Dataset**:
  - `description` updated to start with the target phrase.
  - `keywords` extended with `sähkösopimusten hintakehitys` and `sähkön hintakehitys`. Existing keywords kept.
  - Dataset type unchanged — page is genuinely a queryable dataset with a CSV distribution.

- **Lead chart caption** (lines 168–172): short prefix `Sähkösopimusten hintakehitys eri sopimustyypeissä —` added before the existing sentence. Lets crawlers see the phrase associated with the largest above-the-fold visualization.

## What we did NOT change and why

- **`getCitationsProperty()` title `'Sähkön hintatilastot'` (line 479)**: this string is embedded in citations that users have already copied to articles, Reddit threads, etc. Changing it would silently invalidate the canonical label used in attribution. The visible H1 changing is fine because Voltikka is the citation host — the page itself still resolves.
- **No new H2**: the redesign spec deliberately uses editorial section headings (`Hinnat sopimustyypeittäin`, `Vuosikustannus kulutuksen mukaan`, `Mistä luvut tulevat`). Stuffing a keyword-shaped H2 in would hurt the editorial feel; the H1 + intro + caption + JSON-LD coverage is sufficient.
- **Sitemap / routes**: page is already in `SitemapService::getMainPageUrls()`; no addition needed.

## Files touched

- `laravel/app/Livewire/ContractPriceStatistics.php` — `render()` + `getJsonLdProperty()`
- `laravel/resources/views/livewire/contract-price-statistics.blade.php` — H1, intro, lead chart caption
- `laravel/tests/Feature/ContractPriceStatisticsPageTest.php` — two `assertSee` strings

## Verification done

- `php artisan test --filter=ContractPriceStatisticsPageTest` — all tests pass after the H1 assertion update.
