<?php

declare(strict_types=1);

use Light\Db\Model;

/**
 * Test model for general testing
 */
class Testing extends Model
{
    public function getName(): string
    {
        return "a";
    }
}

/**
 * Test model for specific features
 */
class Testing2 extends Model
{
    // Add specific methods if needed
}

/**
 * Test model for additional testing
 */
class Testing3 extends Model
{
    // Add specific methods if needed
}

/**
 * User model for relationship testing
 */
class User extends Model
{
    // Add user-specific methods if needed
}

/**
 * UserList model for relationship testing
 */
class UserList extends Model
{
    // Add userlist-specific methods if needed
}

/**
 * UserGroup model for relationship testing
 */
class UserGroup extends Model
{
    // Add usergroup-specific methods if needed
}

/**
 * UserLog model for relationship testing
 */
class UserLog extends Model
{
    // Add userlog-specific methods if needed
}
