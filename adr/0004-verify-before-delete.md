# 4. Verify the archived copy before deleting live rows

**Status:** accepted

## Context

Archiving is destructive in the middle: rows leave the live tables. If the
archive write is silently wrong — a truncated column, a collation mismatch
mangling bytes, a failed insert swallowed by a return value nobody checked —
the merchant has lost an order and will not find out for months.

## Decision

Per order, inside one transaction: build the payload, insert it, **read it back
out of MySQL**, compare checksums, and only then delete the live rows. A
mismatch rolls the whole thing back and leaves the live data untouched.

Restore mirrors this: checksum is verified before anything is written back.

## Consequences

- An extra read per order. Archiving is a background job measured in hours, so
  the cost is irrelevant next to the guarantee.
- A failed archive costs the merchant nothing — the order simply stays live and
  is logged as skipped.
- Requires InnoDB. `Support\Hpos::supportsTransactions()` checks, and the engine
  refuses to run on MyISAM rather than pretending the transaction did something.
- `wp coldstash verify` re-checks every archived payload on demand.
