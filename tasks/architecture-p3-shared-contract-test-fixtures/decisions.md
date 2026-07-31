# Decisions

## Initial decisions

- Migrate tests incrementally. Do not rewrite the complete test suite at once.
- Factories must not hide domain facts that affect pricing or eligibility.
- Keep intentionally malformed fixtures explicit and local to their tests.
- No implementation decision is final until the current behavior is confirmed with tests.
