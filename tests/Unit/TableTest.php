<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Light\Db\Table;

/**
 * Table functionality tests
 */
final class TableTest extends BaseTestCase
{
    /**
     * @group table_basic
     */
    public function testTableInstance(): void
    {
        $table = Testing::_table();
        
        $this->assertInstanceOf(Table::class, $table);
        $this->assertEquals('Testing', $table->getTable());
    }

    /**
     * @group table_schema
     */
    public function testTableColumns(): void
    {
        $table = Testing::_table();
        $columns = $table->columns();
        
        // Columns might be a Collection or array depending on implementation
        if (is_object($columns) && method_exists($columns, 'toArray')) {
            $columnsArray = $columns->toArray();
        } else {
            $columnsArray = $columns;
        }
        
        $this->assertNotEmpty($columnsArray);
        
        // Check if primary key column exists
        $primaryKeys = $table->getPrimaryKey();
        $this->assertNotEmpty($primaryKeys);
    }

    /**
     * @group table_operations
     */
    public function testTableInsert(): void
    {
        $table = Testing::_table();
        $table->truncate();
        
        $result = $table->insert(['name' => 'table_insert_test']);
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
        
        $count = Testing::Query()->count();
        $this->assertEquals(1, $count);
    }

    /**
     * @group table_operations
     */
    public function testTableUpdate(): void
    {
        $table = Testing::_table();
        $table->truncate();
        
        // Insert test record
        $table->insert(['name' => 'original_name']);
        
        // Update
        $affected = $table->update(['name' => 'updated_name'], ['name' => 'original_name']);
        $this->assertEquals(1, $affected);
        
        // Verify update
        $record = Testing::Query(['name' => 'updated_name'])->first();
        $this->assertNotNull($record);
        $this->assertEquals('updated_name', $record->name);
    }

    /**
     * @group table_operations
     */
    public function testTableDelete(): void
    {
        $table = Testing::_table();
        $table->truncate();
        
        // Insert test records
        $table->insert(['name' => 'delete_test_1']);
        $table->insert(['name' => 'delete_test_2']);
        $table->insert(['name' => 'keep_test']);
        
        // Delete specific records
        $deleted = $table->delete(['name' => ['delete_test_1', 'delete_test_2']]);
        $this->assertEquals(2, $deleted);
        
        // Verify remaining record
        $remaining = Testing::Query()->count();
        $this->assertEquals(1, $remaining);
    }

    /**
     * @group table_operations
     */
    public function testTableTruncate(): void
    {
        $table = Testing::_table();
        
        // Insert some records
        $table->insert(['name' => 'truncate_test_1']);
        $table->insert(['name' => 'truncate_test_2']);
        
        $this->assertGreaterThan(0, Testing::Query()->count());
        
        // Truncate
        $table->truncate();
        
        $this->assertEquals(0, Testing::Query()->count());
    }

    /**
     * @group table_metadata
     */
    public function testTableMetadata(): void
    {
        $table = Testing::_table();
        
        // Test table name
        $tableName = $table->getTable();
        $this->assertIsString($tableName);
        $this->assertNotEmpty($tableName);
        
        // Test primary key
        $primaryKey = $table->getPrimaryKey();
        $this->assertIsArray($primaryKey);
        $this->assertNotEmpty($primaryKey);
        
        // Test adapter
        $adapter = $table->getAdapter();
        $this->assertNotNull($adapter);
    }
}
