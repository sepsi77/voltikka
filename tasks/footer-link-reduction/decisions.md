# Decisions

- Reduced the site-wide footer's contract comparison links to higher-priority destinations only: all contracts, comparison landing page, cheapest contracts, offers, business contracts, and electricity companies.
- Removed footer-wide links to housing-type pages, energy-source pages, and lower-priority pricing/contract-type comparison pages because `/sahkosopimus` already links to most comparison pages.
- Replaced the old energy-source footer block with a separate `Data ja selvitykset` block containing sähkösopimusten hintakehitys, hintaennuste, pörssisähkö viability article, fixed-term viability article, and spot-price page.
- Kept only Helsinki, Tampere, Oulu, and Turku as direct city footer links, plus the all-locations link, matching the screenshot guidance.
- Renamed visible `Sähkölaskuri` labels to `Arvioi sähkönkulutus` so the link better describes the consumption-estimation tool. Updated the related article card helper copy to `Arvioi kotisi vuosikulutus`.
- Converted the main-menu `Hintatilastot` link into a desktop dropdown and mobile collapsible menu containing the same data/investigation pages as the footer data block: sähkösopimusten hintakehitys, sähkön hintaennuste, kannattaako pörssisähkö, kannattaako määräaikainen, and pörssisähkön hinta.
- Renamed the header data dropdown parent from `Hintatilastot` to `Sähködata`; this is shorter, more interesting, and broad enough to cover statistics, forecasts, spot prices, and analysis articles.
- Renamed visible `Hintakehitys` link labels to `Sähkösopimusten hintakehitys` in the header dropdown, mobile dropdown, and footer data block.
- Kept `/spot-price` grouped under `Sähködata` as `Pörssisähkön hinta` for data/price investigation, and added `Pörssisähkö` back to the `Sähkösopimukset` header dropdown as a contract-comparison link to `/sahkosopimus/porssisahko`.
- Validation: confirmed hintaennuste, spot-price, and pörssisähkö comparison routes exist. `php artisan view:cache` completed successfully, then compiled views were cleared with `php artisan view:clear` after each navigation change.
