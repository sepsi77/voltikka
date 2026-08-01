# Decisions

## Initial decisions

- Use one focused SpotPriceImporter service.
- Do not introduce a generic external-data import framework.
- Keep official and forecast spot data in separate tables.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- The focused command baseline passes: 37 tests and 67 assertions.
- `FetchSpot` and `BackfillSpot` contain separate copies of persistence, VAT selection, and quarter-hour aggregation.
- Backfill currently skips a chunk when it finds any one hourly row in the chunk.
- Backfill continues after an HTTP failure but currently returns a success status.

## Implementation design

- Add `App\Services\SpotPriceImport\SpotPriceImporter` as the one official spot-price persistence service.
- Keep ENTSO-E range selection, monthly chunking, retry handling, progress output, average calculation, cache warming, and command messages in the commands.
- Preserve insert-only idempotency with `insertOrIgnore()`.
- Preserve the current arithmetic hourly average for quarter-hour input. Group by region and UTC hour.
- Put the Helsinki-local VAT boundary policy in the importer and test it with a focused data provider.
- Treat `[start, end)` as complete only when every expected UTC hourly timestamp exists for FI. One existing row is not complete coverage.
- Continue later backfill chunks after an HTTP or connection failure, but return `Command::FAILURE` after processing when any chunk failed.

## Implementation result

- `SpotPriceImporter` now owns the shared official-price persistence path used by both commands.
- Backfill coverage compares the stored FI timestamps with every exact expected UTC hour in the half-open chunk.
- Backfill keeps processing after exhausted request or connection failures and returns failure after all chunks when one or more chunks failed.
- The focused importer and command suite passes: 48 tests and 80 assertions.
- Pint passes for all changed PHP implementation and focused test files.
- The full Laravel suite ran 1,729 tests: 1,727 passed and 2 unrelated existing tests failed. Both failures reproduce alone: `ContractDetailPageTest::test_spot_faq_answers_the_variation_question_only_with_real_history` creates duplicate May rows when month subtraction runs on 31 July, and `ContractDetailPresenterTest::test_six_month_detail_copy_uses_the_real_term_benefit_not_the_annualized_benefit` has a pre-existing calculated discount assertion mismatch. Neither failure executes the shared importer path.
- Detailed importer rules now live in `laravel/app/Services/SpotPriceImport/AGENTS.md`, with pointers from `laravel/app/Services/AGENTS.md` and `laravel/AGENTS.md`.
