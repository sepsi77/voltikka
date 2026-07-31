# Decisions

## Initial decisions

- Start with ContractDetail because it has the highest responsibility count.
- Use presenters and query services only where a clear responsibility exists.
- Do not move interactive Livewire state into domain services.
- No implementation decision is final until the current behavior is confirmed with tests.
