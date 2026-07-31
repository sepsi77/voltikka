# Decisions

## Initial decisions

- Do not replace canonical calculation DTOs.
- The new type is a read model for consumers, not a new pricing engine.
- Keep arrays at serialization boundaries only.
- No implementation decision is final until the current behavior is confirmed with tests.
