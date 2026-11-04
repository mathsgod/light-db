# light-db — light-entity major-version prototype

A small Active Record layer over light-entity. The runtime consists of four
classes: `Database`, `Model`, `Query`, and `ModelMetadata`. It has no Laminas,
Illuminate Collection, paginator, or GraphQL dependency.

```php
use Light\Db\{Database, Model};

// Optional when a DATABASE_DSN or the usual DATABASE_* .env variables are set.
Database::setDefault(new Database($pdo));

class User extends Model {}

foreach (User::Query() as $user) {
    echo $user->name;
}

$user = User::Create(['name' => 'Ada']);
$user->save();

$user = User::Get(1); // null when missing; named equality conditions also work
$user->name = 'Mary';
$user->settings['theme'] = 'dark';
$user->save();
$user->delete();
```

## Conventions and metadata

The default table name is the exact short class name (`User` maps to `User`).
Override with `protected static $_table = 'users';` or `#[Light\Entity\Table('users')]`.
Schema discovery supplies columns, primary/composite keys, defaults and generated
columns; no manual field list or base constructor call is required. Models can
mix dynamic attributes with public typed properties. The base constructor is
final and accepts an optional attribute array; hydration bypasses constructors.

Native JSON/JSONB declarations automatically receive a converter and comparer,
using PHP arrays for objects and lists. JSON changes through references are
tracked, including nested array changes. As in the old associative decoding,
empty JSON objects decode to empty arrays. SQL NULL remains null. SQLite TEXT
and MariaDB JSON aliases reported as LONGTEXT are not automatically recognized
as JSON in this prototype; configure a converter/comparer explicitly if needed.
`TINYINT(1)` remains integer. Decimal/date values follow light-entity's defaults.
Override `valueConverters()` / `valueComparers()` to customize property mappings.

`Database` caches metadata and no-tracking read contexts per Model class. Every
GET/query still reads the database, yielding a fresh Model. Existing Models keep
their original Database binding even if the application changes the default.
Call `$database->clearMetadata()` after DDL or changing the selected database on
the same PDO; obtain fresh Models after schema changes.

## Querying

```php
$query = User::Query()
    ->filters(['name' => ['contains' => 'ar'], 'age' => ['gte' => 18]])
    ->sort('age:desc,name:asc');

$total = $query->count(); // filtered total BEFORE limit/offset
$page = $query->limit(20)->offset(40)->toArray();

$first = $query->first(); // Model|null
$models = User::Query(['id' => [1, 2, 3]])->toArray();

$users = User::Query()->whereRaw('BINARY name = ?', [$name]);
$affected = User::Query(['age' => ['lt' => 18]])->update(['status' => 'minor']);
$deleted = User::Query(['status' => 'inactive'])->delete();

$names = User::Query()->asEnumerable()->select(fn ($user) => $user->name)->toArray();
```

Queries are immutable: keep the returned Query when adding conditions, sorting
or pagination. `where()` accepts condition maps or light-linq expressions.
Operators include `eq`, `neq`/`ne`, `gt`, `gte`, `lt`, `lte`, `in`, `notIn`,
`between`, `notBetween`, `null`, `notNull`, `like`, `contains`, `startsWith`,
`endsWith`, with optional leading `_`. Logical groups use `_and`, `_or`, `_not`.
String convenience operators escape literal wildcard characters; `like` accepts
a pattern. Conditions and order fields are validated against mapped columns.
`whereRaw()` takes trusted SQL and separate scalar provider-value parameters.

`count()`, `sum()`, `avg()`, `min()`, `max()` refer to the filtered set before
pagination. `first()`, `any()`, iteration and `toArray()` respect pagination.
Bulk writes execute immediately, bypass Model save/delete methods and leave
already-loaded Models stale. Paginated or ordered bulk writes are rejected.

Normal `foreach` materializes the result before yielding, allowing nested reads
and per-row `save()`. Explicit `cursor()` streams instead; MySQL's active
unbuffered result prevents other queries on that connection until it is closed.

## Custom filters and ordering

```php
use Light\Db\Query;
use Light\Linq\Expr;

User::RegisterFilter('adult', fn ($minimum) =>
    Expr::gte(Expr::field('age'), $minimum));

User::RegisterFilter('keyword', fn ($value, Query $query) =>
    $query->whereRaw('name LIKE ?', [$value . '%']));

User::RegisterOrder('byAge', fn ($direction) => Expr::field('age'));

$users = User::Query()->filters(['adult' => 18])->sort('byAge:desc');
```

Filters return an expression, condition map, or a Query for the same Model and
Database. Orders return an expression or such a Query. Callbacks receive the
value/direction first and the current Query second. A Query callback must return
its new Query; mutation of a Laminas Predicate is not supported. Model subclasses
may override protected `filterDefinitions()` and `orderDefinitions()` instead.
Custom named filters are applied at the top level; nested logical groups accept
ordinary mapped-column conditions.

## Persistence boundaries

Each Model save/delete uses its own light-entity unit of work and automatic
transaction, preserving the original snapshot and caller's object on failure.
The tracker receives an internal working copy; successful database-generated
values are copied back to the original Model. Saving one Model never flushes
another Model's unsaved changes. Primary-key and computed-column modifications
are rejected. Deleted Models cannot be saved again. `exists()`, `isDirty()`,
`toArray()`, `_id()` and `_key()` provide basic Model introspection.

Multi-Model transaction coordination and adopting externally opened PDO
transactions are not implemented yet; the native persistence layer rejects such
saves before executing writes. `Include`/relationship conventions, GraphQL mapper
integration, joins/projection wrappers, legacy `wasChanged()`/audit hooks and
schema-management wrappers are also outside this first core implementation.
The old Laminas Query/Predicate API is intentionally not emulated.

## Read-path optimization

For conventional dynamic Models, hydration already produces validated model
attributes. The original snapshot reuses that array with PHP copy-on-write,
avoiding a second per-column read/validation pass. The standard JSON decoder
produces arrays/scalars without references, so its hydrated snapshot can also
share storage until mutation rather than immediately encode/decode a second
copy. This optimization does not apply to custom converters or magic accessors;
the general validation/comparer path is retained for them and public typed
properties. Save-time snapshots keep their existing comparer behavior.

JSON serialization caches public-property metadata per class and reads values
per instance. Dynamic/custom-setter cases retain the generic serialization path.
`Query::toArray()` attaches Models directly to the materialized array rather
than collecting a second array through the iterator. Ordinary `foreach` still
finishes the database result before application code can issue other queries.

Benchmarks and before/after results: [query → JSON](benchmarks/query-json/README.md).

## Tests

PHP >= 8.3 is required. This development checkout uses the sibling light-entity
package through a Composer path repository.

```bash
composer install
composer test        # SQLite; install pdo_sqlite
composer test:mysql  # isolated MySQL 8 Docker fixture, default port 33390
composer analyse     # PHPStan level 5
```

Each test uses a fresh SQLite database or creates/drops its own randomly named
MySQL schema. No existing framework database is used. Override
`LIGHT_DB_TEST_PORT` to select another Docker port.
