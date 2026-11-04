# v1 vs v2: User::Query() → JSON

Reproduce from the new-major checkout:

```bash
python3 benchmarks/query-json/run.py 30 10000
```

Requires Docker Compose, Python 3, PHP >= 8.3/pdo_mysql and installed Composer dependencies in both checkouts. Defaults to the sibling `light-db` old checkout; override `LIGHT_DB_OLD_ROOT` if necessary. Port defaults to 33391 (`LIGHT_DB_QUERY_BENCH_PORT`). The old source is archived from its `main` Git ref into a temporary folder and autoloaded with the old dependencies, so it does not use the experimental GET bridge and does not change either production checkout. A third CLI argument selects another old Git ref.

## Method

Same isolated MySQL 8 server, two 10,000-row fixtures: seven ordinary fields (INT, strings, DECIMAL, DATETIME, payload), and the same fields plus a native `settings JSON` column containing an object, boolean and string list. Neither fixture has generated columns. Both call `User::Query()->toArray()` followed by `json_encode($rows)`, using their own Model JsonSerializable implementations.

30 randomized rounds × two libraries × cold/warm × two fixtures = 240 independent PHP workers. CLI OPcache is disabled. Autoload/connection/client setup is outside timing. Cold is the first query in a process; warm performs one full query + JSON encode, releases the result and then times the second. Database and OS caches are not flushed; MySQL data uses tmpfs.

Every field is validated outside timing. All final JSON checksums are identical between libraries and modes within each fixture, including value types and key order. Timing splits SELECT/materialization from JSON encoding; memory is PHP used-memory growth with all Models and the complete JSON response retained. It is not OS RSS or server memory. SQL call counts include metadata queries.

## Initial implementation results — PHP 8.5.12, MySQL 8.0.46

Old source: `5cc2226`. New source: `4bcd8b8`. Full samples and source hashes: `results-10000.json` (gitignored).

| Fixture | Mode | Version | Total median ms | Fetch/hydrate ms | JSON ms | Peak +MiB | SQL calls |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| plain | cold | old | 74.936 | 46.815 | 28.327 | 17.755 | 7 |
| plain | cold | new | 97.199 | 77.327 | 19.999 | 25.383 | 3 |
| plain | warm | old | 52.931 | 26.716 | 25.861 | 15.606 | 1 |
| plain | warm | new | 78.101 | 61.819 | 16.177 | 24.363 | 1 |
| json | cold | old | 97.228 | 56.976 | 39.426 | 19.304 | 7 |
| json | cold | new | 141.717 | 114.893 | 27.931 | 41.436 | 3 |
| json | warm | old | 75.572 | 36.616 | 38.888 | 17.155 | 1 |
| json | warm | new | 125.176 | 92.023 | 32.940 | 40.408 | 1 |

Warm plain: v2 takes 47.55% longer, with 56.11% more PHP peak memory growth.
Warm json: v2 takes 65.64% longer, with 135.55% more PHP peak memory growth.

The phase split places the additional time in materialization, not an increased number of warm SQL calls. V2 directly hydrates Model subclasses, converts JSON during hydration and creates independent original snapshots for Active Record dirty checking. V1 retains raw database JSON strings and decodes them during serialization or property access; it does not eagerly retain a separate decoded JSON snapshot for every row. This is a source-level explanation of work present in the timed phase, not a profiler attribution of each millisecond.

This compares the initial implementations as they stand. No production optimization was applied during the benchmark, and single-row GET results do not predict large-list behavior. Context/schema caching benefits both warm query paths; snapshot creation, conversion and object costs still scale with the result count.

Container and fixture data are removed when the runner exits.


## Optimized v2 A/B — 2026-11-04

Reproduce with `python3 benchmarks/query-json/run.py 30 10000 5cc2226 4bcd8b8` from the new-major root. The fourth argument archives the original v2 core as a third variant (`baseline`); `new` uses the current working tree. There are 360 independent workers, with identical final JSON across all three variants within each fixture. The source hashes in `results-ab-10000.json` identify the optimized implementation.

| Fixture | Mode | Variant | Total median ms | Fetch/hydrate ms | JSON ms | Peak +MiB |
| --- | --- | --- | ---: | ---: | ---: | ---: |
| plain | cold | old | 73.078 | 45.398 | 27.618 | 17.755 |
| plain | cold | baseline | 96.589 | 75.892 | 20.739 | 25.383 |
| plain | cold | new | 66.326 | 55.744 | 10.607 | 13.098 |
| plain | warm | old | 52.512 | 26.995 | 26.210 | 15.606 |
| plain | warm | baseline | 76.215 | 60.230 | 16.128 | 24.363 |
| plain | warm | new | 52.102 | 43.338 | 8.631 | 12.003 |
| json | cold | old | 95.565 | 56.450 | 38.718 | 19.304 |
| json | cold | baseline | 136.015 | 109.934 | 25.864 | 41.436 |
| json | cold | new | 87.725 | 76.905 | 10.574 | 21.218 |
| json | warm | old | 75.230 | 37.205 | 37.990 | 17.155 |
| json | warm | baseline | 124.777 | 92.393 | 32.528 | 40.408 |
| json | warm | new | 74.825 | 63.512 | 11.389 | 20.189 |

Warm plain: optimized v2 takes 31.64% less time than initial v2, with 50.73% less PHP peak memory growth.

Warm json: optimized v2 takes 40.03% less time than initial v2, with 50.04% less PHP peak memory growth.

Optimizations: reuse normalized attributes for plain dynamic Model snapshots, share default decoded JSON arrays with copy-on-write, cache serialization metadata, and attach Models in the returned array without a second collection. Custom converters, comparers, accessors, typed public fields and dynamic public properties retain the required generic behavior. Snapshot isolation and failed-save retry are tested.

Versus v1, warm totals are now similar in these fixtures. JSON peak memory remains higher than v1 (20.189 vs 17.155 MiB) because v2 retains decoded JSON data on Models; the plain fixture uses less memory (12.003 vs 15.606 MiB). The JSON warm paired delta (new minus v1) has a bootstrap 95% interval crossing zero, so this run does not establish a stable JSON speed advantage over v1. It establishes a large improvement over the initial v2 implementation.

Validation: SQLite and MySQL each pass 21 tests / 132 assertions; PHPStan level 5 passes. Timed SQL calls remain one per warm query. No application database or production checkout was swapped. Raw samples and paired comparisons are in `results-ab-10000.json` (gitignored).

## Entity storage refactor — 2026-11-04

Reproduce with `python3 benchmarks/query-json/run.py 30 10000 5cc2226 9b6ecea`.
The baseline is the previously optimized v2 Model. All variants use the same
local light-entity dependency; this comparison isolates the Model adapter change.
All 360 workers produced identical final JSON within each fixture.

| Fixture | Mode | v1 ms | Previous v2 ms | Refactored v2 ms | Previous/refactored peak MiB |
| --- | --- | ---: | ---: | ---: | ---: |
| plain | cold | 75.646 | 66.937 | 71.312 | 13.118 / 13.042 |
| plain | warm | 53.607 | 50.878 | 57.142 | 12.003 / 12.003 |
| json | cold | 95.467 | 89.017 | 95.088 | 21.240 / 21.240 |
| json | warm | 74.937 | 78.430 | 82.026 | 20.189 / 20.189 |

The refactor removes Model-owned attribute storage and clone/reassignment on
save. EntityMapping now validates model values and builds snapshots. This
adds read-path work compared with the former adapter shortcut; timings above
show the measured cost rather than a speed improvement. Default JSON snapshots
keep copy-on-write storage without assigning unchanged values into the array.
Warm queries still execute one SQL statement.

## Direct light-entity comparison — 2026-11-04

Run `python3 benchmarks/query-json/run-entity.py 30 10000`. The same User
class, schema mapping, JSON converter and PDO connection configuration are used
to isolate adapter overhead. Direct queries bypass Model attachRecord/snapshots;
they do not initialize Active Record persistence state. These figures do not
measure a separately optimized plain Entity class. No-tracking cases have 30
fresh-process rounds each; warm means schema/query initialization has run once.
Tracked queries have just one cold observation per fixture because they are
currently expensive. All complete JSON outputs match within each fixture.

| Fixture | Warm API | Query/toArray median ms | Including JSON median ms |
| --- | --- | ---: | ---: |
| plain | new | 47.474 | 55.461 |
| plain | entity-no-tracking | 39.672 | 49.843 |
| json | new | 68.729 | 80.677 |
| json | entity-no-tracking | 52.236 | 68.255 |

Default tracked `set(User::class)->toArray()` cold observations:

- plain: 28.455 s query, 28.465 s including JSON.
- json: 37.429 s query, 37.443 s including JSON.

The current ChangeTracker performs linear identity scans while adding each
entity, producing quadratic work across a large result. No-tracking bypasses
this. No-tracking light-db additionally captures the original snapshot and
attaches its Database/mapping so Models can later save independently.
Raw measurements: `results-entity-10000.json` (gitignored).

## 100-record bottleneck investigation — 2026-11-04

Run `python3 benchmarks/query-json/run.py 50 100 5cc2226` for v1 vs current
v2 (50 randomized rounds; 400 independent workers). Also run
`python3 benchmarks/query-json/run-entity.py 30 100` for direct no-tracking
queries and stage timings. All complete JSON outputs match per fixture.
Connection/autoload bootstrap are excluded; cold still includes first use of
query classes and schema discovery. Warm SQL calls remain one.

Two hydration barriers caused unnecessary work: the streaming iterator was also
used to materialize `DbSet::toArray()`, bypassing native bulk hydration; the bulk
eligibility gate also excluded every Model constructor and all value converters.
Bulk materialization now skips constructors, applies converters once to raw
provider values, and initializes dynamic Entity storage in one operation.
Streaming iteration and general typed/custom-setter/raw SQL paths are preserved.
Snapshot processing now visits only configured comparers.

| Fixture | Mode | v1 query ms | v2 query ms | v1 including JSON ms | v2 including JSON ms |
| --- | --- | ---: | ---: | ---: | ---: |
| plain | cold | 17.174 | 8.408 | 19.511 | 8.481 |
| plain | warm | 1.084 | 0.951 | 1.326 | 1.013 |
| json | cold | 17.172 | 8.529 | 19.681 | 8.629 |
| json | warm | 1.110 | 1.239 | 1.451 | 1.319 |

Warm SELECT → JSON time is 23.6% lower for plain fields and 9.1% lower with
JSON. Paired round-delta bootstrap 95% intervals (5,000 resamples, seed 42)
are [-0.377, -0.260] ms and [-0.268, -0.135] ms respectively; both favor v2.
Before these changes, a separate sequential 50-round run measured v2 warm totals
of 1.349 ms (plain) and 1.574 ms (JSON). These separate runs are not paired A/B.

JSON query-only still costs more than v1: the v1 Model decodes JSON lazily on
access/serialization, whereas v2 converters decode during hydration. Complete
JSON output measures both implementations doing that work. This is not a claim
that every Query workload is faster.

The stage profiler reproduces the no-filter pipeline with clocks around setup,
native set materialization and Model attach/snapshot. It omits Query wrapper
construction and is a separate run, so medians must not be subtracted from the
v1 comparison. Stage medians (100 rows):

| Fixture | Setup ms | SQL + hydration ms | Model attach/snapshot ms |
| --- | ---: | ---: | ---: |
| plain | 0.007 | 0.931 | 0.058 |
| json | 0.007 | 1.064 | 0.086 |

Raw results: `results-100.json`, `results-before-100.json` and
`results-entity-100.json` (gitignored). These record working-tree source hashes;
no performance fix has been committed automatically.
