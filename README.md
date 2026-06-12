[![GitHub](https://img.shields.io/github/license/mathsgod/light-db)](https://github.com/mathsgod/light-db)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![CI](https://github.com/mathsgod/light-db/actions/workflows/tests.yml/badge.svg)](https://github.com/mathsgod/light-db/actions)
[![Tests](https://img.shields.io/badge/tests-96%20passing-brightgreen.svg)](https://github.com/mathsgod/light-db)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-blue.svg)](https://www.mysql.com/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11%20%7C%2011.4-blue.svg)](https://mariadb.org/)

# Light-DB

Light-DB is a lightweight PHP ORM/database access layer built on top of Laminas DB, designed for modern PHP 8.2+ applications. It provides an Eloquent-like Active Record experience with support for auto-mapping, dynamic queries, relationship queries, JSON field operations, and pagination — with first-class compatibility for both **MySQL 8.0** and **MariaDB 10.11 / 11.4**.

## ✨ Features

- 🚀 **Modern PHP**: Built on PHP 8.2+ features with type declarations and modern PHP syntax
- 🔗 **Multi-Database Support**: Based on Laminas DB — supports MySQL 8.0, MariaDB 10.11/11.4, PostgreSQL, SQLite, SQL Server
- 🧩 **MariaDB-Aware**: Detects MariaDB at runtime and adjusts column metadata (JSON detection, default-value parsing) automatically
- 📦 **Eloquent-Style**: Familiar Active Record pattern with an Eloquent-like API powered by `illuminate/collections`
- 🎯 **Smart Queries**: Complex conditional queries, sorting, grouping, and aggregation functions
- 📄 **Pagination Support**: Built-in Laminas Paginator integration
- 🔄 **JSON Fields**: Native JSON field operations with automatic serialization/deserialization
- 🔗 **Relationship Queries**: Inter-model relationship queries and dynamic property access
- ✅ **CI-Tested**: Automated test matrix on GitHub Actions across PHP 8.2 / 8.3 / 8.4 / 8.5 × MySQL 8.0 / MariaDB 10.11 / MariaDB 11.4

## 📋 Requirements

- PHP 8.2 or higher
- PDO extension
- A supported database (tested against):
  - MySQL 8.0
  - MariaDB 10.11 / 11.4

## 🚀 Installation

Install via Composer:

```bash
composer require mathsgod/light-db
```

## ⚙️ Configuration

Create a `.env` file in your project root:

```env
DATABASE_DRIVER=Pdo_Mysql
DATABASE_HOSTNAME=localhost
DATABASE_PORT=3306
DATABASE_DATABASE=your_database
DATABASE_USERNAME=your_username
DATABASE_PASSWORD=your_password
DATABASE_CHARSET=utf8mb4
```

## 🎯 Basic Usage

### Defining Models

```php
<?php

use Light\Db\Model;

class User extends Model
{
    // Uses class name as table name by default
    // Customize with: protected static $_table = 'custom_table_name';
}

class Post extends Model
{
    protected static $_table = 'posts';
}
```

### CRUD Operations

#### Creating Records

```php
// Method 1: Create + Save
$user = User::Create([
    'name' => 'Raymond Chong',
    'email' => 'raymond@example.com',
    'age' => 30
]);
$user->save();

// Method 2: Direct property assignment
$user = User::Create();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->save();
```

#### Querying Records

```php
// Query by primary key
$user = User::Get(1);

// Basic queries
$users = User::Query(['status' => 'active'])->toArray();

// Complex queries
$activeUsers = User::Query()
    ->filters([
        'age' => ['gte' => 18, 'lte' => 65],
        'status' => ['in' => ['active', 'premium']],
        'email' => ['contains' => '@gmail.com']
    ])
    ->sort('created_at:desc')
    ->toArray();

// Get first record
$firstUser = User::Query(['status' => 'active'])->first();
```

#### Updating Records

```php
// Single record update
$user = User::Get(1);
$user->name = 'Updated Name';
$user->email = 'updated@example.com';
$user->save();

// Batch update
$affected = User::Query(['status' => 'inactive'])
    ->update(['status' => 'archived']);
```

#### Deleting Records

```php
// Single record deletion
$user = User::Get(1);
$user->delete();

// Batch deletion
$deleted = User::Query(['status' => 'spam'])
    ->delete();
```

### 🔍 Advanced Queries

#### Query Conditions

```php
$query = User::Query()->filters([
    'age' => ['eq' => 25],           // Equal to
    'score' => ['gt' => 80],         // Greater than
    'salary' => ['gte' => 50000],    // Greater than or equal
    'rating' => ['lt' => 5],         // Less than
    'points' => ['lte' => 100],      // Less than or equal
    'status' => ['in' => ['active', 'premium']], // In array
    'name' => ['contains' => 'john'],  // Contains pattern
    'category' => ['ne' => 'spam']   // Not equal
]);
```

#### Sorting and Limiting

```php
$users = User::Query()
    ->sort('created_at:desc,name:asc')  // Multi-field sorting
    ->limit(10)                         // Limit results
    ->offset(20)                        // Offset
    ->toArray();
```

#### OR / AND Logic with `filters()`

Use the `_or` and `_and` magic keys inside `filters()` to build boolean logic.

```php
use Light\Db\Model;

class User extends Model {}

// WHERE (age >= 18 OR name = 'Peter')
$users = User::Query()->filters([
    '_or' => [
        ['age' => ['gte' => 18]],
        ['name' => 'Peter']
    ]
])->toArray();
```

Mixing outer `where()` with inner `_or` / `_and`:

```php
// WHERE status = 'active' AND (age >= 30 OR name = 'Test User 1')
$users = User::Query()
    ->where(['status' => 'active'])
    ->filters([
        '_or' => [
            ['age' => ['gte' => 30]],
            ['name' => 'Test User 1']
        ]
    ])
    ->toArray();
```

Nested `_or` and `_and` for deeper boolean trees:

```php
// WHERE (age > 28 AND status = 'active')
//    OR (name = 'Test User 3' AND status = 'inactive')
$users = User::Query()->filters([
    '_or' => [
        [
            '_and' => [
                ['age' => ['gt' => 28]],
                ['status' => 'active']
            ]
        ],
        [
            '_and' => [
                ['name' => 'Test User 3'],
                ['status' => 'inactive']
            ]
        ]
    ]
])->toArray();
```

Alternatively, the underlying `Laminas\Db\Sql\Select::where()` accepts a combination operator:

```php
use Laminas\Db\Sql\Predicate\PredicateSet;

// WHERE (age >= 30 OR name = 'Test User 1')
$users = User::Query()
    ->where(['age >= ?' => 30], PredicateSet::OP_OR)
    ->where(['name = ?' => 'Test User 1'], PredicateSet::OP_OR)
    ->toArray();
```

> **Tip**: `filters(['_or' => ...])` is generally easier to read and supports arbitrary nesting. Use the direct `where(..., OP_OR)` form when you need fine-grained control over a single predicate.

#### Aggregate Functions

```php
$userCount = User::Query()->count();
$avgAge = User::Query()->avg('age');
$totalSalary = User::Query()->sum('salary');
$minAge = User::Query()->min('age');
$maxAge = User::Query()->max('age');
```

### 📄 Pagination

```php
$query = User::Query(['status' => 'active']);
$paginator = $query->getPaginator();

// Set items per page
$paginator->setItemCountPerPage(20);
$paginator->setCurrentPageNumber(1);

// Get current page data
$currentItems = $paginator->getCurrentItems();
$totalItems = $paginator->getTotalItemCount();
$totalPages = $paginator->getPages()->pageCount;
```

### 🔄 JSON Field Operations

```php
// Create record with JSON data
$user = User::Create([
    'name' => 'John',
    'profile' => [
        'avatar' => 'avatar.jpg',
        'settings' => [
            'theme' => 'dark',
            'notifications' => true
        ],
        'tags' => ['developer', 'php', 'mysql']
    ]
]);
$user->save();

// Read JSON data
$user = User::Get(1);
echo $user->profile['settings']['theme']; // 'dark'

// Update JSON data
$user->profile['settings']['theme'] = 'light';
$user->profile['tags'][] = 'javascript';
$user->save();
```

> **Note**: MariaDB stores JSON columns as `LONGTEXT` with a `JSON_VALID()` CHECK constraint. Light-DB detects this at runtime and treats them as `json` data type for transparent encoding/decoding.

### 🔗 Relationship Queries

```php
// Assuming UserList model with user_id column
$user = User::Get(1);

// Get related UserList query object
$userLists = $user->UserList;  // Returns Query object

// Further querying
$activeLists = $user->UserList
    ->filters(['status' => 'active'])
    ->sort('created_at:desc')
    ->toArray();
```

### 🛠️ Advanced Features

#### Declarative Filters & Sorts (`Model::boot()`)

A model's relation-based or computed filters and sorts can be **declared on the model itself** instead of being registered ad-hoc from controllers. Light-DB auto-invokes the model's `boot()` lifecycle method the first time `Model::Query()` is called for the class, so registrations are guaranteed to be in place before any filter is resolved.

Override `filterDefinitions()` and / or `orderDefinitions()` to return an `[name => callable]` map:

```php
use Light\Db\Model;

class Schedule extends Model
{
    protected static function filterDefinitions(): array
    {
        return [
            'Letter' => function ($value) {
                return "letter_id IN (SELECT letter_id FROM letter WHERE subject = " . $value . ")";
            },
        ];
    }

    protected static function orderDefinitions(): array
    {
        return [
            'recent' => fn($dir) => "created_at $dir",
        ];
    }
}
```

After this, **any** caller of `Schedule::Query()->filters(['Letter' => $v])` — including sub-queries from unrelated code paths — gets the right SQL, without the controller having to call `RegisterFilter()` first. Boot is idempotent per process per class, so multiple `Query::Query()` invocations don't double-register.

Inheritance via `parent::`:

```php
class Letter extends Schedule
{
    protected static function filterDefinitions(): array
    {
        return array_merge(parent::filterDefinitions(), [
            'Tag' => fn($v) => "letter_id IN (SELECT letter_id FROM letter_tag WHERE tag = $v)",
        ]);
    }
}
```

Borrow a filter from another class:

```php
'Schedule' => Schedule::filterDefinitions()['Letter'],
```

> The legacy `Model::RegisterFilter()` / `Model::RegisterOrder()` APIs are still supported and remain the right choice when the registration depends on request-scoped state (current user, request params, etc.) that the static `filterDefinitions()` hook cannot see. Both styles can coexist on the same model.

#### Collection Operations

```php
$users = User::Query(['status' => 'active']);

// Map operation
$names = $users->map(fn($user) => $user->name)->toArray();

// Filter operation
$premiumUsers = $users->filter(fn($user) => $user->type === 'premium');

// Method chaining
$emailList = User::Query()
    ->filters(['status' => 'active'])
    ->map(fn($user) => $user->email)
    ->toArray();
```

#### Custom Sorting

```php
// Legacy: register a sort ad-hoc from anywhere (still supported)
User::RegisterOrder('popular', function($query) {
    return $query->order(['score DESC', 'views DESC']);
});

// Use custom sorting
$popularUsers = User::Query()->sort('popular')->toArray();
```

> Prefer `orderDefinitions()` on the model itself for new code — see [Declarative Filters & Sorts](#declarative-filters--sorts-modelboot).

#### Binding Input Parameters to Prepared Statements

Both `cursor()` and `execute()` accept an optional `array $input_parameters = []` argument. These parameters are bound to the underlying `Laminas\Db\Adapter\ParameterContainer` and matched against the `?` placeholders that Light-DB/Laminas auto-generates for where conditions. Both methods iterate the same way and return hydrated model instances.

```php
// cursor() returns a Laravel LazyCollection — perfect for streaming large result sets
foreach (User::Query()->cursor() as $user) {
    echo $user->name;
}

// execute() returns a regular Collection eagerly materialized
$users = User::Query()->execute()->toArray();
```

`input_parameters` is a thin pass-through to the prepared statement. Use it when you want to be explicit about what gets bound:

```php
// The framework already binds values from where() conditions automatically,
// so this is normally a no-op. It exists for symmetry and advanced cases
// (e.g. when the Select contains Expression objects with named placeholders).
User::Query(['status' => 'active'])->execute([
    'minAge' => 18,
])->toArray();
```

> **Note**: If you pass keys that don't match any `?` token in the query, the database driver will reject the statement with `HY093` (PDO: "number of bound variables does not match number of tokens"). The placeholder count is determined by your where/filter/join expressions — Light-DB does not invent extra `?` tokens for unused parameters.

## 🗄️ Database Compatibility

| Database | Version | Status |
|---|---|---|
| MySQL    | 8.0     | ✅ Fully supported |
| MariaDB  | 10.11   | ✅ Fully supported |
| MariaDB  | 11.4    | ✅ Fully supported |

Light-DB automatically detects MariaDB at connection time by inspecting `SELECT VERSION()`. Internally, this enables:

- **JSON column detection** — MariaDB reports `json` columns as `longtext` in `INFORMATION_SCHEMA`; Light-DB queries `CHECK_CONSTRAINTS` for `json_valid()` clauses to recover the real type
- **Default-value parsing** — MariaDB quotes string defaults (e.g. `'foo'`) while MySQL 8.0 does not; Light-DB normalizes both formats
- **Connection setup** — `utf8mb4_0900_ai_ci` collation is only set on MySQL 8.x

## 🧪 Running Tests

A `.env` file with valid database credentials is required (see [Configuration](#️-configuration)).

```bash
# Run all tests
composer test

# Run basic functionality tests
composer test-basic

# Run CRUD operation tests
composer test-crud

# Run JSON field tests
composer test-json

# Run unit tests only
composer test-unit

# Run integration tests only
composer test-integration
```

## ✅ Continuous Integration

Tests run automatically on every push and pull request via [GitHub Actions](https://github.com/mathsgod/light-db/actions). The CI matrix covers:

- **PHP**: 8.2, 8.3, 8.4, 8.5
- **Database**: MySQL 8.0, MariaDB 10.11, MariaDB 11.4

That gives **12 parallel jobs** ensuring compatibility across the supported matrix.

## 📊 Test Coverage

- **96** test cases
- **380** assertions
- Covers all core functionality
- Includes unit and integration tests
- Supports error handling and edge case testing

## 📦 Dependencies

| Package | Version | Purpose |
|---|---|---|
| `laminas/laminas-db` | `^2.20` | Database abstraction layer |
| `laminas/laminas-paginator` | `^2.20` | Pagination support |
| `illuminate/collections` | `^11.0 \|\| ^12.0` | Eloquent-style collections |
| `vlucas/phpdotenv` | `^5.6` | Environment variable loading |

## 📝 License

This project is licensed under the [MIT License](LICENSE).

## 👨‍💻 Author

**Raymond Chong**
- Email: mathsgod@yahoo.com
- GitHub: [@mathsgod](https://github.com/mathsgod)

## 🤝 Contributing

Issues and Pull Requests are welcome!

1. Fork the project
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📚 More Examples

Check the test files in the `tests/` directory for more usage examples and best practices.
