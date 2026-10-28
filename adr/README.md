# Architecture decision records

Short records of decisions that were not obvious and would otherwise be
re-litigated. Each one states the context, the decision, and what it costs.

| # | Decision |
|---|---|
| [0001](0001-hpos-only.md) | HPOS only; legacy post storage unsupported |
| [0002](0002-analytics-tables-untouched.md) | Never touch the Analytics lookup tables |
| [0003](0003-hybrid-archive-format.md) | Hybrid archive: indexed columns + JSON payload |
| [0004](0004-verify-before-delete.md) | Verify the archived copy before deleting live rows |
| [0005](0005-ddev-for-local-development.md) | DDEV for local development |
