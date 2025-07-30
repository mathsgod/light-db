<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Light\Db\Model;

/**
 * Base test case with common setup and teardown
 */
abstract class BaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Database is already set up by bootstrap.php
        // No need to insert additional data
    }

    protected function tearDown(): void
    {
        // Don't cleanup after each test to preserve data for subsequent tests
        parent::tearDown();
    }

    /**
     * Clean up test data after each test
     */
    protected function cleanupTestData(): void
    {
        try {
            Testing::_table()->delete([]);
            Testing2::_table()->delete([]);
            Testing3::_table()->delete([]);
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Create a test model with default data
     */
    protected function createTestModel(string $modelClass = Testing::class, array $data = []): Testing|Testing2|Testing3
    {
        $defaults = [
            'name' => 'test_' . uniqid(),
        ];
        
        return $modelClass::Create(array_merge($defaults, $data));
    }

    /**
     * Insert default test data
     */
    protected function insertDefaultTestData(): void
    {
        try {
            // Insert basic test data using Model::Create()
            Testing::Create([
                'name' => 'Test User 1',
                'email' => 'test1@example.com',
                'age' => 25,
                'price' => 10.50,
                'is_active' => 1,
                'birth_date' => '1998-01-01',
                'created_at' => '2024-01-01 10:00:00',
                'j' => '{"type": "user", "level": 1}',
                'status' => 'active'
            ])->save();
            
            Testing::Create([
                'name' => 'Test User 2',
                'email' => 'test2@example.com',
                'age' => 30,
                'price' => 15.75,
                'is_active' => 1,
                'birth_date' => '1993-05-15',
                'created_at' => '2024-01-01 11:00:00',
                'j' => '{"type": "admin", "level": 2}',
                'status' => 'active'
            ])->save();
            
            Testing::Create([
                'name' => 'Test User 3',
                'email' => 'test3@example.com',
                'age' => 35,
                'price' => 20.00,
                'is_active' => 0,
                'birth_date' => '1988-12-25',
                'created_at' => '2024-01-01 12:00:00',
                'j' => '{"type": "guest", "level": 0}',
                'status' => 'inactive'
            ])->save();
            
        } catch (Exception $e) {
            // Ignore if data already exists or creation fails
        }
    }

    /**
     * Assert that a model exists in database
     */
    protected function assertModelExists($model): void
    {
        $className = get_class($model);
        $primaryKeyField = $className::_key(); // Use _key() method instead
        $keyValue = $model->$primaryKeyField;
        $found = $className::Get($keyValue);
        
        $this->assertNotNull($found, "Model should exist in database");
    }

    /**
     * Assert that a model does not exist in database
     */
    protected function assertModelNotExists(string $modelClass, $id): void
    {
        $found = $modelClass::Get($id);
        $this->assertNull($found, "Model should not exist in database");
    }
}
