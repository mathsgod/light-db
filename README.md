# Light-DB

Light-DB is a lightweight ORM/database access layer for PHP 8.1+ based on Laminas DB. It supports automatic Model mapping, querying, relationships, JSON column operations, and is suitable for rapid modern PHP application development.

## Features

- Based on Laminas DB, supports multiple databases
- Eloquent-like Model operation experience
- Automatic conversion and Proxy operation for JSON columns
- Supports relationship queries, dynamic queries, aggregation functions
- PHPUnit test support

## Installation

```bash
composer install
```

## Configuration

Create a `.env` file in your project root with the following content:

```
DATABASE_HOSTNAME=localhost
DATABASE_DATABASE=your_db
DATABASE_USERNAME=your_user
DATABASE_PASSWORD=your_password
DATABASE_PORT=3306
DATABASE_CHARSET=utf8mb4
DATABASE_DRIVER=pdo_mysql
```

## Basic Usage

### Define a Model

```php
use Light\Db\Model;

class User extends Model {}
```

### Create Data

```php
$user = User::Create([
    'name' => 'Raymond',
    'email' => 'raymond@example.com'
]);
$user->save();
```

### Query Data

```php
$user = User::Get(1);
$users = User::Query(['status' => 'active'])->toArray();
```

### Update Data

```php
$user = User::Get(1);
$user->name = 'New Name';
$user->save();
```

### Delete Data

```php
$user = User::Get(1);
$user->delete();
```

### JSON Column Operation

```php
$user = User::Get(1);
$user->profile['nickname'] = 'Ray';
$user->save();
```

### Relationship Query

Assuming you have a UserList Model with a user_id column:

```php
$user = User::Get(1);
$userLists = $user->UserList; // Get related UserList query object
```

## Testing

```bash
composer test
```

## License

MIT License
