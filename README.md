[![GitHub](https://img.shields.io/github/license/mathsgod/light-db)](https://github.com/mathsgod/light-db)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![Tests](https://img.shields.io/badge/tests-73%20passing-brightgreen.svg)](https://github.com/mathsgod/light-db)

# Light-DB

Light-DB is a lightweight PHP ORM/database access layer built on top of Laminas DB, designed for modern PHP 8.1+ applications. It provides an Eloquent-like Model operation experience with support for auto-mapping, dynamic queries, relationship queries, JSON field operations, and pagination features.

## ✨ Features

- 🚀 **Modern PHP**: Built on PHP 8.1+ features with type declarations and modern PHP syntax
- 🔗 **Multi-Database Support**: Based on Laminas DB, supports MySQL, PostgreSQL, SQLite, SQL Server and more
- 📦 **Eloquent-Style**: Familiar Active Record pattern with Eloquent-like API
- 🎯 **Smart Queries**: Support for complex conditional queries, sorting, grouping, and aggregation functions
- 📄 **Pagination Support**: Built-in Laminas Paginator integration for easy pagination
- 🔄 **JSON Fields**: Native support for JSON field operations with automatic serialization/deserialization
- 🔗 **Relationship Queries**: Support for inter-model relationship queries and dynamic property access
- 🧪 **Comprehensive Testing**: 73+ test cases ensuring code quality and stability
- ⚡ **High Performance**: Lazy loading and query optimization for excellent performance

## 📋 Requirements

- PHP 8.1 or higher
- PDO extension
- Supported databases: MySQL, PostgreSQL, SQLite, SQL Server, etc.

## 🚀 Installation

Install via Composer:

```bash
composer require mathsgod/light-db
```

## ⚙️ Configuration

Create a `.env` file in your project root:

```env
DATABASE_DRIVER=pdo_mysql
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
        'email' => ['like' => '%@gmail.com']
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
    'name' => ['like' => '%john%'],  // Like pattern
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
// Register custom sorting logic
User::RegisterOrder('popular', function($query) {
    return $query->order(['score DESC', 'views DESC']);
});

// Use custom sorting
$popularUsers = User::Query()->sort('popular')->toArray();
```

## 📊 Available Test Groups

This project includes a comprehensive test suite. You can run specific test groups:

```bash
# Run all tests
composer test

# Run basic functionality tests
composer test-basic

# Run CRUD operation tests
composer test-crud

# Run JSON field tests
composer test-json

# Run unit tests
composer test-unit

# Run integration tests
composer test-integration
```

## 🧪 Test Coverage

- **73+** test cases
- **269+** assertions
- Covers all core functionality
- Includes unit and integration tests
- Supports error handling and edge case testing

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
