# Decisions Log

## 2026-01-19: Memory Optimization for ContractsList

**Decision:** Use database-level filtering with EXISTS subquery instead of eager loading `availabilityPostcodes` relationship.

**Context:**
- The `contract_postcode` pivot table has 22,195 records
- Loading all these records for 488 contracts exceeded 128MB memory limit
- The relationship is only needed for postcode filtering

**Alternatives Considered:**
1. **Pagination** - Would require significant UI changes and doesn't solve the underlying problem
2. **Lazy loading** - Still loads all pivot records when accessed, just deferred
3. **Database-level filtering** (chosen) - Never loads pivot records into memory, filters using SQL EXISTS

**Rationale:**
- Database-level filtering is the most efficient approach
- The EXISTS subquery only checks for existence, doesn't fetch actual records
- Memory usage reduced from >128MB to ~12MB
- No UI changes required
- Maintains full functionality with postcode filtering

