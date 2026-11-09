# light-db

A PHP 8.3+ Active Record ORM built on light-entity 1.x. It provides automatic
schema mapping, immutable queries, JSON change tracking and atomic multi-Model
transactions through a familiar Model API.

`Database` manages the PDO connection and metadata, `Model` provides record
persistence, and `Query` handles filtering, sorting and pagination. light-entity
supplies the underlying mapping, change tracking and unit of work.

Upgrading from 1.x: see the [v2 migration guide](docs/migration-v2.md).

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

## v2.2.1

v2.2.0 inadvertently reintroduced deferred Model writes inside transactions.
v2.2.1 restores immediate `save()`/`delete()`: generated IDs/defaults
are available inside the callback, and Model, Query bulk and raw PDO writes on the
same connection commit or roll back together. The published v2.2.0 tag is unchanged.
See [the changelog](CHANGELOG.md) and [transaction migration guidance](docs/migration-v2.md#7-group-immediate-model-writes-with-transaction).

## v2.1.0

Requires light-entity `^1.2` and its transitive light-linq `^1.2` dependency.
Adds translated query closures and native LIKE expression filters.
See [CHANGELOG](CHANGELOG.md).

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
as JSON; configure a converter/comparer explicitly if needed.
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
or pagination. `where()` accepts condition maps, light-linq expressions, or
restricted query closures translated by light-entity. `Model::Query()` also
accepts an initial closure.
Operators include `eq`, `neq`/`ne`, `gt`, `gte`, `lt`, `lte`, `in`, `notIn`,
`between`, `notBetween`, `null`, `notNull`, `like`, `contains`, `startsWith`,
`endsWith`, with optional leading `_`. Logical groups use `_and`, `_or`, `_not`.
String convenience operators escape literal wildcard characters; `like` accepts
a pattern. These filters use light-entity `LikeExpression` nodes, so the query
provider handles identifier quoting, parameter binding and `ESCAPE`; light-db
does not build raw SQL for them. Requires a light-entity version containing
`LikeExpression` (update the application lockfile if necessary). Conditions and order fields are validated against mapped columns.
`whereRaw()` takes trusted SQL and separate scalar provider-value parameters.

`select()` returns a light-linq `Queryable` containing scalar values for one
column, or associative rows for multiple columns. It preserves filters, ordering
and pagination, and does not hydrate Models. It also accepts a single
`ValueExpression`, such as `Expr::fields('server_id', 'name')`, or a single
projection closure. A closure cannot be mixed with additional column selectors.

```php
$ids = Server::Query()->select('server_id')->toArray();
$rows = Server::Query()->select('server_id', 'name')->toArray();
$serverIds = ServerIP::Query()->select('server_id');
$servers = Server::Query()->whereIn('server_id', $serverIds)->toArray();
```

Apply Model query methods before `select()`; after it, use light-linq methods
with expressions or supported query closures. String column names are validated
against mapped properties.

### Query closures

```php
$users = User::Query()
    ->where(fn($user) => $user->status == 'active')
    ->toArray(); // User Models

$users = User::Query(fn($user) => $user->status == 'active')
    ->orderBy('id')->toArray();

$rows = User::Query()
    ->where(fn($user) => $user->status == 'active')
    ->select(fn($user) => ['id' => $user->id, 'name' => $user->name])
    ->toArray(); // Untracked associative rows
```

`where()` forwards the closure to the underlying `DbSet`; light-linq parses its
PHP source into expressions and light-entity compiles parameterized SQL. It is
not a per-Model PHP callback. No rows are fetched to evaluate it in PHP.
`select(fn($user) => $user->name)` returns scalar values. Query immutability,
global filters, ordering, paging and normal Model materialization are preserved.

light-db v2.1.0 requires light-entity `^1.2`, which requires light-linq `^1.2`.
Closures support arithmetic, string matching, scalar functions, `??` and full
ternary expressions with those stable dependencies. Existing applications should
update their Composer lockfile; no `dev-main` requirement or inline alias is needed.

See [Closure translation](docs/closure-translation.md) for supported entry
points, dependency requirements, examples and limitations.

`whereIn()` and `whereNotIn()` accept a value list or a single-column light-entity
`IQueryable` subquery. Lists follow the same conversion and validation rules as
`where(['id' => ['in' => $ids]])`; an empty IN list matches no rows, while an empty
NOT IN list matches all rows. Subqueries may select from a different Model as
long as both queries share the exact same PDO connection. Each Model's global
filters are preserved; SQL NULL semantics apply to subqueries. This uses
the cross-context IN support included in light-entity v1.2.0.

```php
$users = User::Query()->whereIn('id', [1, 2, 3])->toArray();
$others = User::Query()->whereNotIn('id', [1, 2, 3])->toArray();
$ids = $db->set(User::class)->where(['age' => ['gte' => 18]])
    ->select(\Light\Linq\Expr::field('id'));
$adults = User::Query()->whereIn('id', $ids)->toArray();

$serverIds = $db->set(ServerIP::class)->select(\Light\Linq\Expr::field('server_id'));
$servers = Server::Query()->whereIn('server_id', $serverIds)->toArray();
```

Use `orderByRaw()` to sort by a trusted scalar SQL expression or correlated
subquery. It accepts positional or named provider-value parameters and appends
to existing ordering, just like `orderBy()`:

```php
$groups = IPGroup::Query()->orderByRaw(
    '(SELECT name FROM DataCenter WHERE DataCenter.datacenter_id = IPGroup.datacenter_id)',
    parameters: [],
    descending: false,
)->orderBy('id')->toArray();
```

Pass the expression without `ORDER BY` or `ASC`/`DESC`; `descending: true` selects
DESC. Use SQL table/column names in the fragment and bind values separately.
This API requires a light-entity version containing `DbSet::orderByRaw()` and
`DbSet::thenByRaw()`.

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

### Filtering SQL functions and other expressions

`Filter::expression()` applies the same scalar/list/operator-map conditions as
mapped-column filters to any `ValueExpression`. It returns an expression usable
by `where()`, `RegisterFilter()` or `filterDefinitions()`:

```php
use Light\Db\Filter;
use Light\Entity\Sql\RawSqlValueExpression;

Domain::RegisterFilter('status', static fn ($condition) => Filter::expression(
    new RawSqlValueExpression('Domain_Status(domain_id)'),
    $condition,
));

$domains = Domain::Query()->filters(['status' => ['in' => [0, 1]]]);

$users = User::Query()->where(Filter::expression(
    new RawSqlValueExpression('ABS(age) + :offset', ['offset' => 5]),
    ['gte' => 20, 'lt' => 40],
))->toArray();
```

Supported conditions include scalar equality, lists for IN, and the existing
`eq`, `neq`/`ne`, comparison, range, null and string operators (including `_`
prefixes). Multiple operators combine with AND. Empty IN matches nothing;
empty NOT IN matches everything. Use `Expr::both()`, `Expr::either()` or
`Expr::not()` to compose predicates; `_and`/`_or`/`_not` remain Query condition-map
operators, not single-expression operators.

The API accepts an expression object, not a SQL string. Raw SQL fragments must
be application-owned; never let a request choose SQL text, identifiers or
function names. Both raw-expression parameters and filter values are bound.
Raw function results have no mapped-column converter, so supply provider values.
Mapped-column `Query::filters()` keeps its existing metadata validation and
value conversion, including converting escaped LIKE patterns exactly once.
String convenience operators escape literal `%`, `_` and `!`; `like` accepts
a pattern with `!` as the explicit escape character.

## Read-only queries

```php
$users = User::Query()->asReadOnly()->where(['age'=>['gte'=>18]])->toArray();
echo json_encode($users, JSON_THROW_ON_ERROR);
```

`asReadOnly()` returns a new Query. It keeps converters and User instances but
skips Model original snapshots. It applies to `first()`, `toArray()`, buffered
iteration and `cursor()`, including further filters/orders/pagination. Custom
callbacks returning another Query cannot remove an existing read-only flag.
Normal queries keep their existing persistence behavior.

Read-only Models report `isReadOnly() === true`; their fields remain mutable for
presentation. Model `save()`/`delete()` and Query `update()`/`delete()` throw
LogicException. `isDirty()` also throws because there is no original baseline.
`exists()`, `_id()`, `toArray()` and JSON serialization remain available.
This is an API persistence restriction; independent raw SQL or another query on
the same database is outside its scope.

## Persistence boundaries

Outside a Database transaction, each Model save/delete uses its own light-entity unit of work and automatic
transaction, preserving the original snapshot and caller's object on failure.
The tracker attaches the actual Model with its original model-value snapshot;
no working clone or attribute reassignment is required. Saving one Model never flushes
another Model's unsaved changes. Primary-key and computed-column modifications
are rejected. Deleted Models cannot be saved again. `exists()`, `isDirty()`,
`toArray()`, `_id()` and `_key()` provide basic Model introspection.

`Database::transaction()` executes Model writes immediately and commits them
atomically when its callback returns. This supports flows where a later write
needs an earlier insert's generated ID, such as an audit record:

```php
$db = Database::getDefault();
$id = $db->transaction(function () use ($values) {
    $user = User::Create($values);
    $user->save(); // INSERT now; ID, defaults and computed values are available.
    AuditEvent::Create([
        'entity_id' => $user->id,
        'action' => 'create',
    ])->save();
    return $user->id;
}); // Both writes commit here, or both roll back if the callback/commit fails.
```

Each `save()`/`delete()` executes immediately and returns its normal write count;
queries on this Database see those writes before commit. Models loaded before the
callback participate if bound to the same Database. Saving a Model still does not
flush other Models' unsaved edits. Repeated saves each execute SQL; saving then
deleting a new Model inserts and deletes its row within the transaction.
Query bulk writes and parameterized raw PDO writes on `connection()` also
participate. Writes through a different connection are independent. Do not
commit/roll back the connection or execute DDL inside the callback.

Each Model save uses a savepoint. An uncaught save exception rolls back the whole
transaction; if the callback deliberately catches it, only that failed save is
rolled back and the callback may continue. Nested transactions and externally
opened transactions are rejected.

After rollback, database changes are undone, but already accepted PHP Model state
(including generated IDs, `exists()` and original snapshots) is **not** rewound.
Discard those instances and obtain fresh Models before retrying; for rolled-back
inserts, create a new Model. This matches light-entity's explicit transaction
semantics. Persistence hooks run after the outer commit, once for each successful
Model save/delete in call order, and are discarded on rollback. Hooks see the
Model's final state, not a per-save copy; hook failures cannot undo committed data.
Keep audit writes that must be atomic inside the callback, rather than in hooks.

`Include`/relationship conventions, GraphQL mapper
integration, joins/projection wrappers, legacy `wasChanged()`/audit hooks and
schema-management wrappers are not provided by the light-db 2 API.
The old Laminas Query/Predicate API is intentionally not emulated.

## Read-path optimization

For conventional dynamic Models, hydration already produces validated model
attributes. The original snapshot reuses that array with PHP copy-on-write,
avoiding repeated magic-property reads while retaining model-value validation. The standard JSON decoder
produces arrays/scalars without references, so its hydrated snapshot can also
share storage until mutation rather than immediately encode/decode a second
copy. This optimization does not apply to custom converters or magic accessors;
the general validation/comparer path is retained for them and public typed
properties. Save-time snapshots keep their existing comparer behavior.

Entity owns dynamic attribute storage and reference access. Model delegates bulk
assignment and snapshots to EntityMapping, and directly attaches itself on save.
JSON serialization caches public-property metadata per class and reads values
per instance. Dynamic/custom-setter cases retain the generic serialization path.
`Query::toArray()` attaches Models directly to the materialized array rather
than collecting a second array through the iterator. Ordinary `foreach` still
finishes the database result before application code can issue other queries.

Benchmarks and before/after results: [query → JSON](benchmarks/query-json/README.md).

## Tests

GitHub Actions runs the same suite against SQLite and MySQL 8.0 on PHP 8.3,
8.4 and 8.5 for pushes and pull requests, with manual dispatch also available.
CI verifies the PDO connection and fails on skipped tests. The SQLite PHP 8.3
job also runs Composer validation and PHPStan. See
[the test workflow](.github/workflows/tests.yml).

PHP >= 8.3 is required. Composer installs the published light-entity 1.x package;
no sibling checkout or local path repository is required.

```bash
composer install
composer test        # SQLite; install pdo_sqlite
composer test:mysql  # isolated MySQL 8 Docker fixture, default port 33390
composer analyse     # PHPStan level 5
```

Each test uses a fresh SQLite database or creates/drops its own randomly named
MySQL schema. No existing framework database is used. Override
`LIGHT_DB_TEST_PORT` to select another Docker port.

The PHPUnit suite covers Model lifecycle, converters/snapshot isolation,
computed and composite-key fields, read-only guards, structured filter operators,
malformed filters, literal wildcard escaping, incompatible filter/order callbacks,
fresh-query behavior, and pagination boundaries. A 100-record fixture with no
JSON columns checks ordinary/read-only Model output against PDO rows and verifies
that ordinary Models still save changes independently. Both database backends
run the same tests through the shared `DatabaseTestCase` fixture.

To check isolation with a reproducible shuffled order:

```bash
vendor/bin/phpunit --order-by=random --random-order-seed=20261104
composer test:mysql -- --order-by=random --random-order-seed=20261104
```

## Global query filters

Model definitions are registered through the native light-entity
`onModelCreating()`/ModelBuilder pipeline:

```php
class User extends \Light\Db\Model
{
    protected static function queryFilterDefinitions(): array
    {
        return [
            'softDelete' => \Light\Linq\Expr::eq(\Light\Linq\Expr::field('is_deleted'), 0),
            // Resolve application request state when the query executes:
            'tenant' => fn () => ['tenant_id' => CurrentTenant::id()],
        ];
    }
}

User::Query()->toArray(); // Both filters apply.
User::Query()->asReadOnly()->toArray(); // Same filters, no Model snapshots.
User::Query()->ignoreQueryFilters(['softDelete'])->toArray(); // Keep tenant scope.
User::Query()->ignoreQueryFilters()->toArray(); // Disable all query filters.
```

Filters affect Get, query reads, count/aggregates, cursor and bulk update/delete.
They use ordinary compiler parameter binding and converter rules. Ignoring filters
is query-local, accumulates named exclusions and preserves read-only behavior.
Registered filter/order callbacks returning a fresh Query retain those scopes.
Existing RegisterFilter/filterDefinitions remain explicitly selected search filters;
queryFilterDefinitions are automatic predicates. Native saveChanges semantics are
unchanged: an explicitly loaded writable Model can save its changes even if it was
loaded by ignoring a filter. Soft-delete writes require separate application logic.

`CurrentTenant` above represents your application's tenant resolver. Ordinary
queries without queryFilterDefinitions retain their existing behavior. Full native
filter and relationship semantics are documented in light-entity's README.

### Persistence extension hook

Model subclasses may override `protected function afterPersistence(bool $deleted): void`
to run work after a successful write commits. Transactions queue hooks until the
outer commit, after all participating Models have accepted their saved state.
Hooks do not run for failed writes or rolled-back transactions. A hook exception
propagates after the database commit; it does not roll back data. External writes
made by a hook are not part of the original transaction. The protected
`database()` accessor returns the
Database bound to that Model, even if the application changes its default.

`filters()` also accepts a list of condition maps, combined with AND, for example
`filters([['age' => ['gte' => 18]], ['name' => ['contains' => 'ar']]])`.
Empty maps are ignored, so the frontend placeholder `filters([[]])` leaves the
query unchanged. Nested lists and registered filters follow the same rules;
non-array list members are rejected.
