# Changelog

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
