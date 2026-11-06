# Closure translation in light-db

[Back to README](../README.md#query-closures)

light-db's Active Record queries can use restricted PHP closures through
light-entity. It reuses the underlying closure translator and SQL provider;
light-db does not implement another parser or execute these closures per Model.

## Filtering Models

```php
$users = User::Query()
    ->where(fn($user) => $user->status == 'active')
    ->orderBy('id')
    ->toArray();

// Equivalent initial predicate:
$users = User::Query(fn($user) => $user->status == 'active')->toArray();
```

The result contains `User` Models with normal attachment and persistence
behavior. `asReadOnly()` continues to apply. Assuming the Model maps to `users`,
the predicate is conceptually `WHERE status = :p0`, with `p0 = 'active'`; table
and identifier syntax follow the Model mapping and SQL dialect.

`Query::where()` accepts a `ValueExpression`, condition map, or `Closure`.
The closure passes to `DbSet::where()` unchanged. Maps keep their existing
light-db operator handling. Each call returns a new Query, preserving the
original query and any global filters, filter bypasses, read-only setting,
ordering or pagination.

Captured scalar/null values are bound as parameters. Values are captured when
the query closure is translated, not later when the query is enumerated:

```php
$status = 'active';
$query = User::Query()->where(fn($user) => $user->status == $status);
```

## Scalar and named projections

```php
$names = User::Query()->orderBy('id')
    ->select(fn($user) => $user->name)->toArray();

$rows = User::Query()->where(fn($user) => $user->status == 'active')
    ->orderBy('id')->offset(20)->limit(10)
    ->select(fn($user) => ['id' => $user->id, 'name' => $user->name])
    ->toArray();
```

`Query::select()` accepts one closure and delegates to `DbSet::select()`.
It returns a light-linq `Queryable`: a field selection yields scalar values,
and a named projection yields associative rows. These results are untracked;
they are not Model instances. Existing `select('name')`, `select('id', 'name')`
and single-`ValueExpression` forms remain supported. Mixing a closure with
column names, another closure or another expression throws
`InvalidArgumentException`.

Apply Model methods such as `orderBy('id')` and `limit()` before `select()`.
After selection, use Queryable methods and their expression/closure signatures:

```php
$rows = User::Query()->select(fn($user) => ['id' => $user->id, 'name' => $user->name])
    ->where(fn($row) => $row->id > 10)
    ->toArray();
```

## Reusing the underlying expression features

With compatible versions of both light-entity and light-linq:

```php
$rows = User::Query()
    ->where(fn($user) => str_starts_with(strtolower($user->name), 'a'))
    ->select(fn($user) => [
        'name' => $user->name ?? 'Guest',
        'group' => $user->age >= 18 ? 'adult' : 'minor',
        'bytes' => strlen($user->name),
    ])
    ->toArray();
```

These entry points inherit the underlying translator's support for comparisons,
logical and arithmetic operators, string matching, supported scalar functions,
coalesce and conditional expressions. Function signatures, collation,
byte-length encodings, trim character sets, rounding and SQL NULL behavior keep
the same provider limitations documented by light-entity.

A scalar `select()` result is an `IQueryable` and can be captured in a
`contains()` query when it shares the exact provider instance:

```php
$ids = User::Query()->where(['status' => 'active'])->select(fn($user) => $user->id);
$users = User::Query()->where(fn($user) => $ids->contains($user->id))->toArray();
```

A light-db `Query` itself is not `IQueryable`. Do not capture it directly as an
`any()`/`contains()` receiver. Cross-Model contexts may have different providers;
use the existing `whereIn()` API for supported cross-context IN queries.

## Limits and dependencies

- Closures require a readable PHP source file. `eval` closures are unsupported.
  Put separate closures on separate lines so reflection can identify them.
- Use one ordinary row parameter and an arrow expression or a single-return
  anonymous function. Capture anonymous-function variables with `use`.
- Only documented query syntax is accepted. Arbitrary functions, arbitrary
  method calls, assignments and loops are not translated. Unsupported syntax
  throws `ClosureTranslationException`; there is no automatic client fallback.
- Only `Model::Query()`, `Query::where()` and the single-selector form of
  `Query::select()` are extended here. Do not assume `orderBy()` or registered
  filter callbacks accept every closure form.
- SQL translation occurs underneath the wrapper; string column selectors still
  use light-db's existing validation. Query execution remains deferred until a
  terminal operation or enumeration.

For arbitrary PHP callbacks, use `asEnumerable()` explicitly. Those operations
run in PHP after fetching Models and are different from translated SQL queries.

light-db v2.1.0 requires stable `mathsgod/light-entity:^1.2`, which requires
`mathsgod/light-linq:^1.2`. Both dependencies are installed transitively, including
arithmetic, conditional, coalesce, string matching and scalar expression support.
Applications must update their Composer lockfile to use the new versions:

```sh
composer require 'mathsgod/light-db:^2.1' --with-all-dependencies
```

Remove temporary `dev-main` constraints and inline aliases from the application's
root requirements when moving to stable versions. Sibling checkouts are not
automatically loaded by Composer.

See [light-entity closure translation](https://github.com/mathsgod/light-entity/blob/main/docs/closure-translation.md)
and [light-linq closure translation](https://github.com/mathsgod/light-linq/blob/main/docs/closure-translation.md)
for the supported grammar and provider semantics.
