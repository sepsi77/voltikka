# Offline retail composition research

Only this folder may be written. `analysis.py` reads the pinned export and emits exact-date indices, issue-fixed pair targets, member audits, fixed-launch coverage and summaries. `verify.py` rebuilds raw medians independently with Decimal and checks saved outputs. `reproduce.py` runs both twice and checks artifact hashes.

Never select a cohort using future availability. Every supplier at issue has weight 1/N; missing any member rejects the whole required-date value. Keep lag-7 feature coverage separate from current/target coverage. Keep observed and canonical bases separate at July 27. A rolling issue-fixed cohort is not a coherent daily time series. Supplier name is the available identity, not a proven persistent product ID. Within-supplier variant and promotion mix remains uncontrolled. Smaller target changes do not show improved forecast accuracy. No app or model change is made here.
