# Market-reset fee boundary fix

Status: local implementation complete, with existing full-suite and style failures verified against unchanged HEAD. See decisions.md for evidence and unchanged monthly-bin limits.

Keep monthly-fee promotions in billing and benefits, but exclude fee-only transitions from known energy coverage and reset reference selection. Preserve effective inherited energy rates, real finite energy phases, recurring boundaries, multi-rate tariffs, and feature-off behavior. Cover the Aalto quarterly and monthly September 11, 2026 shapes with annual and monthly regression checks. Correct Finnish reset copy to the next 12 months. Bump calculated-cost cache schema once. Update local context, run relevant tests and production asset build. No production writes, deployment, historical-row changes, commit, or push.
