# Changelog

## v2.2.0 — 2026-11-06

## Transaction behavior changes

`Database::transaction()` now queues Model writes and flushes them together
through one light-entity unit of work after the callback returns.

- `save()` and `delete()` inside the callback return `0`.
- New IDs/defaults become available after commit; queries in the callback do not
  see queued changes.
- Repeated writes to one Model use its final values and last operation.
- Saving then deleting a new Model cancels its insert.
- Callback errors discard queued writes; failed commits preserve edits for retry.
- Bulk Query writes are rejected inside a deferred Model transaction.
- Persistence hooks run after participating Models accept their committed state.
- Outside the callback, Model writes remain immediate.

Applications that use an inserted ID inside the transaction callback must adapt
before upgrading. See `docs/migration-v2.md` for the current contract.

## Validation

SQLite and MySQL: 125 tests and 955 assertions each, no skips. PHPStan level 5 passed.
Dependencies remain light-entity ^1.2 and its transitive light-linq ^1.2.

## v2.1.0 — 2026-11-06

- Require stable light-entity ^1.2, which supplies light-linq ^1.2 transitively.
  No development dependency alias is required.
- Accept translated closures in Model::Query(), Query::where() and single-selector
  Query::select(), preserving filters, pagination and Model materialization.
- Reuse light-linq 1.2 arithmetic, string, scalar, coalesce and conditional nodes.
- Use native LikeExpression for like/contains/startsWith/endsWith map filters,
  preserving escaping and applying mapped value conversion exactly once.
- Retain scalar/multi-column projections, cross-Model IN helpers and bound raw
  ordering introduced during the 2.1 release candidates.
- Add closure documentation and regression coverage.
