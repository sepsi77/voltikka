# Decisions

- Keep implementation and provenance terms in code, but describe them in plain Finnish on the public page.
- Use `Ennustejakso` for the 30-day horizon and its end date.
- Explain the model as three familiar data groups: current contract prices, prior price history, and EEX futures.
- Replace `p20` and `p80` in public copy with the familiar concepts of an inexpensive and expensive market price level. Keep the exact quantiles in the data and table labels.
- Explain the weighted historical difference directly: compare past contract and futures prices, and give newer observations more weight. Do not expose the implementation term `eksponentiaalinen liukuva keskiarvo`.
- Remove the now-unused `usesCanonicalCurrentRetailInput` view variable. The plain-language description is accurate in both feature states.
- Verification: `FixedContractPriceForecastingTest` passed 10 tests with 75 assertions. Focused Pint, task JSON validation, and `git diff --check` passed.
