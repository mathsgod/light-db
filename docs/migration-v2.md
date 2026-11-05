# Migrating from light-db 1.x to 2.x

light-db 2 replaces the Laminas-based implementation with an Active Record layer
built on light-entity 1.x. The `Light\Db` namespace and basic CRUD entrypoints
remain, but Laminas Query/Predicate, Adapter and TableGateway APIs are not emulated.
This guide describes the v2 branch; it does not announce a stable release.

## 1. Prepare the upgrade

Keep the previous application and `composer.lock`, and back up data before any
application-specific schema or data migration. Test custom Models, filters,
GraphQL integrations and transactions in staging.

The runtime requires PHP 8.3+, PDO and light-entity `^1.0`. Use `pdo_mysql` for
MySQL or `pdo_sqlite` for SQLite. These are the tested database backends; SQL
dialects elsewhere in light-entity do not imply additional light-db ORM support.

Once a stable v2 release is published, applications can require
`mathsgod/light-db:^2.0`. Applications testing the source branch before release
must configure their own source repository and development constraint/version
override. Do not label development source as a published stable release.

light-db itself now installs published light-entity 1.x; a sibling light-entity
checkout is not required. Remove old path repositories or version overrides that
would keep selecting light-db 1.x or an unrelated light-entity development branch.

## 2. Configure Database instead of Adapter

```php
use Light\Db\Database;

Database::setDefault(new Database($pdo));
$database = Database::getDefault();
$pdo = $database->connection();
```

The default Database can also be created from `DATABASE_DSN`, or the existing
`DATABASE_HOSTNAME`, `DATABASE_PORT`, `DATABASE_DATABASE`, `DATABASE_USERNAME`,
`DATABASE_PASSWORD` and `DATABASE_CHARSET` environment settings. The separate
username/password settings supply PDO credentials when using a DSN.

| Old API | Replacement |
| --- | --- |
| `Adapter::Create()` | `Database::getDefault()` |
| `Model::SetAdapter()/GetAdapter()` | Configure the default Database; use its `connection()` for PDO |
| `_table()->insert($values)` | `Model::Create($values)->save()` or parameterized PDO |
| `_table()->update($values, $where)` | `Model::Query($where)->update($values)` |
| `_table()->delete($where)` | `Model::Query($where)->delete()` |
| Laminas statement `execute()/current()` chains | PDO `prepare()/execute()/fetch()` or Query `first()/toArray()` |
| `_table()->columns()` / `__attributes()` | `Database::metadata(Model::class)` or light-entity's schema reader |

Query bulk writes bypass Model methods/hooks and leave loaded Models stale.
Replacing a raw TableGateway write with a Model write may invoke application
hooks that the old write bypassed; choose the intended behavior explicitly.

## 3. Review Model definitions and CRUD

```php
use Light\Db\Model;

class User extends Model {}

$user = User::Create(['name' => 'Mary']);
$user->save();
$user = User::Get(1); // Model or null
$user->name = 'Ada';
$user->save();
$user->delete();
```

The default table name is the exact short class name: `User` maps to `User`.
Override it with `protected static $_table = 'users';` or
`#[\Light\Entity\Table('users')]`. light-entity's plural naming convention does
not replace light-db's convention. Columns, primary keys, defaults and generated
columns are read from the database schema.

The base constructor is final and accepts an optional attribute array. Remove
custom constructors expecting Laminas primary-key/table/adapter arguments; use
explicit factories or application methods. Hydration bypasses constructors.
Dynamic attributes and public mutable typed properties are supported.

Check subclass method signatures: `bind(array $attributes): static`,
`save(): int` and `delete(): int`. An override accepting input objects can broaden
`bind()`'s parameter but must keep a compatible return type. The base `bind()`
accepts arrays of mapped model values and rejects unknown fields; it does not
provide framework-specific GraphQL DTO/Undefined handling.

`Get()` accepts a scalar key, a non-empty named condition map, or null. Composite
keys require a named map:

```php
$item = OrderItem::Get(['order_id' => 10, 'product_id' => 20]);
```

`_key()` returns a string for a single key or an array for composite keys.
`_id()` similarly returns the instance's scalar or named composite key. Review
code that assumes every Model has one integer ID. Primary-key and computed-column
modifications are rejected, and a deleted Model cannot be saved again.

Queries and `Get()` return fresh instances; they do not share an identity map
across reads. Existing Models retain their original Database binding if the
application changes the default. Call `clearMetadata()` after DDL and obtain fresh
Models rather than reusing objects with obsolete mappings.

## 4. Retain immutable Query results

Query is no longer a Laminas Select. Keep each returned query:

```php
$query = User::Query();
$query = $query->where(['age' => ['gte' => 18]]);
$query = $query->sort('name:asc')->limit(20)->offset(40);
$users = $query->toArray();
```

Calling `$query->where(...)` or `$query->limit(...)` without assignment leaves the
original query unchanged. This applies to filters, sorting, pagination,
read-only mode and ignored global filters.

Replace `$query->where->...` predicates with condition maps or light-linq
expressions. SQL strings are not accepted by `where()`:

```php
$query = $query->whereRaw('BINARY name = ?', [$name]);
```

Raw SQL must be trusted; bind values separately. Old `columns()`, `join()`,
`group()`, `having()`, `getSqlString()` and paginator wrappers are not exposed by
light-db Query. Use appropriate light-entity APIs or parameterized PDO for those
operations rather than treating Query as an SQL builder.

`count()` and aggregates count the filtered set before limit/offset. `first()`
returns Model or null; iteration and `toArray()` respect pagination. `toArray()`
returns a PHP array. Replace Query `map()`/Collection operations with
`asEnumerable()->select(...)` or ordinary PHP array functions.

Normal `foreach` buffers results before yielding, allowing nested reads and
per-row saves. Explicit `cursor()` returns a streaming Generator. On MySQL, an
active unbuffered cursor prevents other queries on that connection until closed;
do not call `save()` or lazy getters inside such a stream.

## 5. Migrate filters and custom callbacks

```php
$query = User::Query()->filters([
    ['age' => ['gte' => 18]],
    ['name' => ['contains' => 'ar']],
]);
```

Top-level filter lists combine their maps with AND. Empty frontend placeholders
such as `[{}]` become `[[]]` in PHP and leave the query unchanged. Non-array list
members are rejected. Named-column arrays such as `['id' => [1, 2]]` mean IN in
v2; use explicit operators/logical groups instead of relying on old field-list
predicate behavior.

Supported operators include `eq`, `neq`/`ne`, `gt`, `gte`, `lt`, `lte`, `in`,
`notIn`, `between`, `notBetween`, `null`, `notNull`, `like`, `contains`,
`startsWith` and `endsWith`, with optional leading `_`. Logical groups use `_and`,
`_or` and `_not`. Null tests require booleans, range operands require two values,
and unknown columns/operators are rejected. String convenience operators escape
literal wildcard characters; `like` accepts a pattern. Validate real frontend
payloads rather than assuming every old FilterHelper shape is supported.

Custom callbacks receive the value first and the current Query second:

```php
User::RegisterFilter('adult', static fn ($age) => ['age' => ['gte' => $age]]);
User::RegisterFilter('keyword', static fn ($value, \Light\Db\Query $query) =>
    $query->whereRaw('name LIKE ?', [$value . '%']));
```

Return a condition map, expression or a new Query for the same Model/Database.
Do not mutate a Laminas Predicate, return a literal SQL string or omit the returned
Query. Order callbacks likewise return an expression or compatible Query.
Named custom filters apply at the top level; nested logical groups use ordinary
mapped-column conditions.

`queryFilterDefinitions()` provides automatic named global filters. It is
separate from explicitly selected `RegisterFilter()` search filters. Global
filters affect reads, counts and bulk writes, but do not add authorization checks
to `save()/delete()`. Keep application write permissions explicit.

## 6. Check value conversion and lifecycle state

- Native JSON/JSONB columns decode to arrays/scalars. Nested reference edits are
  tracked. Pass model values to bulk updates rather than pre-encoded JSON.
  SQL NULL stays null; empty JSON objects decode to empty PHP arrays.
- SQLite TEXT and MariaDB JSON aliases reported as LONGTEXT are not automatically
  treated as JSON. Configure `valueConverters()/valueComparers()` where needed.
- MySQL `TINYINT(1)` remains integer, including values other than 0 and 1.
  Decimal/date values follow light-entity defaults; review assumptions about
  booleans, floating-point decimals and date objects.
- Omitted insert fields allow DB defaults. Explicit null sends NULL. Generated
  IDs, defaults and computed values are applied after successful persistence.
- `exists()` reports persistence state; `isDirty()` compares pending values with
  the original snapshot. Removed `wasChanged()/getDirty()/GetWithEntity()` and
  legacy audit hooks are not compatibility aliases. Implement required
  application behavior using the new lifecycle rather than renaming calls blindly.
- `asReadOnly()` skips original snapshots. Its Models can be changed for
  presentation, but `save()/delete()/isDirty()` and Query bulk writes are rejected.

## 7. Group Model writes with transaction()

```php
$database->transaction(function () use ($user, $notification) {
    $user->name = 'Mary';
    $user->save();
    $notification->save();
});
```

Inside the callback, Model writes are queued and return `0`. One final
light-entity `saveChanges()` commits the unit atomically. Models loaded before
the callback can participate if bound to that Database. Queries do not see queued
changes, and new generated IDs/defaults are unavailable until commit. There is
no automatic new-Model relationship/key propagation in this API.

Repeated writes of one instance use its final values and last operation. Saving
then deleting a new Model cancels its insert. Only explicitly queued Models
participate. Callback errors discard queued operations; commit failures roll back
writes and retain edits for retry. Outside the callback, Model writes remain
immediate and isolated from other Models' unsaved changes.

Nested transactions and existing PDO transactions are rejected. Query bulk writes
inside the callback are rejected; raw PDO writes or writes through another
Database are outside this deferred boundary. Do not wrap Model saves in an
external PDO `beginTransaction()` and expect them to join it.

`afterPersistence(bool $deleted): void` is a protected extension hook invoked
after successful persistence. Deferred transactions accept every queued Model's
state before invoking hooks. Hooks do not run for failed writes or a cancelled
new insert. Hook failures propagate after commit and cannot undo committed data;
external audit writes from hooks are not atomic with the original transaction.
The protected `database()` accessor returns the Model's bound Database.

## 8. Update integrations and validate

GraphQL is a separate package. Use the v2 light-db GraphQLite mapper for immutable
pagination; do not retain the old mapper that ignores Query return values.
Framework namespace changes belong to Light's own migration guide: this ORM
continues to use `Light\Db` and its entities use `Light\Entity`.

Run `composer test` with `pdo_sqlite`, `composer test:mysql` for the isolated
MySQL fixture and `composer analyse` for static analysis. Skipped database tests
are not a complete validation. Never substitute an application database for the
isolated test fixture. Run application tests for custom filters, pagination,
JSON mutation, generated/composite keys, failed saves/retry and transaction hooks.

Compare the actual GraphQL/API schema and exercise the frontend in staging.
Deploy matching application code and lock files, refresh schema/type caches
according to the application's cache policy and restart long-running workers.
The package upgrade does not automatically apply application DDL or data rewrites.
Rollback requires the previous code/lock file plus any separately planned reversal
or restore of incompatible schema/data changes.
