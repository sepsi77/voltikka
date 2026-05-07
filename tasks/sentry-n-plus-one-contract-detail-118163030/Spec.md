# Spec

Fix Sentry Issue 118163030: N+1 queries in `ContractDetail` Livewire component on `/sahkosopimus/sopimus/{contractId}` pages.

Goals:
- Identify repeated relation queries in contract detail rendering, especially replacement/history chains.
- Batch eager-load relations used by detail view and metadata.
- Preserve inactive contract replacement redirect behavior and historical chain display.
- Add focused regression coverage where practical.
