# Consolidate the contract listing pipeline

**Priority:** P2

## Goal

Use one contract filtering, pricing enrichment, eligibility, sorting, and pagination pipeline for base and SEO listings.

## Scope

- Centralize quarterly, time-of-use, and seasonal query scopes.
- Extract shared metric attachment, exclusion, sorting, and visible-contract loading.
- Keep SEO route constraints and Livewire state in their components.
- Align listing classification with statistics where the data basis permits it.

## Acceptance criteria

- The same interactive filters produce the same membership on base and SEO listings.
- Quarterly phrase rules exist in one place.
- Canonical and legacy list enrichment are not copied between components.
- Existing pagination and SEO URL behavior remain unchanged.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
