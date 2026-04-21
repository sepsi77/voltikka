# AGENTS.md

Context for services under `laravel/app/Services`.

This file should stay short. It is a pointer file for service subtrees, not a dumping ground for detailed service-specific documentation.

See also:
- `../../AGENTS.md` for Laravel-level guidance
- `../../../AGENTS.md` for project-level guidance

## Important directory rule for services

When services gain non-trivial domain logic, matching rules, import behavior, decision-heavy logic, or their own local context needs:
- group them into a **logical feature/domain subdirectory** under `app/Services/`
- do **not** default to one subfolder per service/class if several services belong together
- create or update an `AGENTS.md` inside that subdirectory
- keep this root service-level `AGENTS.md` as a high-level pointer to those subdirectories

## Important decision: do not let `app/Services/AGENTS.md` become a giant service encyclopedia

Reason:
- `app/Services` can grow quickly
- if all detailed service decisions live here, this file becomes long, noisy, and less useful
- local service documentation is easier for agents to discover and maintain when it lives beside the relevant code

Preferred pattern:
- `app/Services/SomeFeature/`
  - `FooService.php`
  - `BarService.php`
  - `BazService.php`
  - `AGENTS.md`

The grouping unit should be a cohesive feature/domain, not an individual class unless that truly makes sense.

## Current service subtrees

### Contract replacement
Directory:
- `ContractReplacement/`

Purpose:
- detect high-confidence replacement contracts for inactive contracts
- persist replacement links for historical chains and SEO redirects

Read first:
- `ContractReplacement/AGENTS.md`

Related files outside services:
- `../Models/ElectricityContract.php`
- `../Livewire/ContractDetail.php`
- `../Console/Commands/DetectReplacementContracts.php`
- `../Console/Commands/LinkReplacementContracts.php`
- `../Console/Commands/FetchContracts.php`

## Documentation rule for this subtree

If you touch files directly under `app/Services/` and they are becoming decision-heavy:
- move them into an appropriately named **feature/domain** subdirectory
- group related services together when they belong to the same area
- create/update a local `AGENTS.md` there
- add a short pointer section to this file
