# Split large Livewire presentation responsibilities

**Priority:** P3

## Goal

Reduce the number of domain, query, SEO, and presentation responsibilities held by large Livewire components.

## Scope

- Extract contract-detail SEO metadata and schema generation.
- Extract contract-history loading and prepared history data.
- Move generated FAQ, verdict, and terms presentation to focused presenters where useful.
- Keep Livewire state, validation, and user actions in the component.

## Acceptance criteria

- ContractDetail no longer owns query, SEO, schema, history, and generated-copy policy in one class.
- Extracted units have focused tests without Livewire hydration.
- Public output and cache payloads remain compatible.
- The work is delivered in small reviewable steps, not one rewrite.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
