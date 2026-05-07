# Spec

Audit remaining Laravel model/relation usage for likely N+1 query issues after recent Sentry reports.

Goals:
- Search Livewire components, Blade views, services, and models for relation access in loops or helpers.
- Identify high-risk lazy-loading paths involving Eloquent relations.
- Implement small safe fixes if clear and covered by focused tests; otherwise report findings.
