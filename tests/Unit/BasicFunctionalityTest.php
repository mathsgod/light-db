<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Simplified tests focusing on basic functionality
 */
final class BasicFunctionalityTest extends BaseTestCase
{
    /**
     * @group basic
     */
    public function testModelBasicOperations(): void
    {
        // Test create
        $model = Testing::Create(['name' => 'test_basic']);
        $this->assertInstanceOf(Testing::class, $model);
        
        // Test save
        $result = $model->save();
        $this->assertIsInt($result); // save() returns the number of affected rows, not boolean
        $this->assertNotNull($model->testing_id);
        
        // Test get
        $retrieved = Testing::Get($model->testing_id);
        $this->assertNotNull($retrieved);
        $this->assertEquals('test_basic', $retrieved->name);
        
        // Test update
        $retrieved->name = 'updated_basic';
        $retrieved->save();
        
        $updated = Testing::Get($model->testing_id);
        $this->assertEquals('updated_basic', $updated->name);
        
        // Test delete
        $deleteResult = $updated->delete();
        $this->assertIsInt($deleteResult); // delete() also returns affected rows count
        
        $deleted = Testing::Get($model->testing_id);
        $this->assertNull($deleted);
    }

    /**
     * @group basic
     */
    public function testQueryBasicOperations(): void
    {
        // Clear and seed data
        Testing::_table()->delete([]);
        
        // Create test data
        for ($i = 1; $i <= 3; $i++) {
            $model = Testing::Create(['name' => "query_test_{$i}"]);
            $model->save();
        }
        
        // Test basic query
        $query = Testing::Query();
        $this->assertEquals(3, $query->count());
        
        // Test query with condition
        $filtered = Testing::Query(['name' => 'query_test_1']);
        $this->assertEquals(1, $filtered->count());
        
        // Test first
        $first = $query->first();
        $this->assertInstanceOf(Testing::class, $first);
        
        // Test toArray
        $array = $query->toArray();
        $this->assertIsArray($array);
        $this->assertCount(3, $array);
    }

    /**
     * @group basic
     */
    public function testJsonBasicOperations(): void
    {
        $model = Testing::Create([
            'name' => 'json_test',
            'j' => ['key' => 'value', 'number' => 42]
        ]);
        $model->save();
        
        $retrieved = Testing::Get($model->testing_id);
        $this->assertEquals('value', $retrieved->j['key']);
        $this->assertEquals(42, $retrieved->j['number']);
        
        // Test modification
        $retrieved->j['new_key'] = 'new_value';
        $retrieved->save();
        
        $updated = Testing::Get($model->testing_id);
        $this->assertEquals('new_value', $updated->j['new_key']);
        
        $model->delete();
    }

    /**
     * @group basic
     */
    public function testTableBasicOperations(): void
    {
        $table = Testing::_table();
        
        // Test table name
        $this->assertNotEmpty($table->getTable());
        
        // Test insert
        $table->delete([]); // Clear first
        $result = $table->insert(['name' => 'table_test']);
        $this->assertIsInt($result);
        
        // Test count
        $count = Testing::Query()->count();
        $this->assertEquals(1, $count);
        
        // Test update
        $affected = $table->update(['name' => 'table_updated'], ['name' => 'table_test']);
        $this->assertEquals(1, $affected);
        
        // Test delete
        $deleted = $table->delete(['name' => 'table_updated']);
        $this->assertEquals(1, $deleted);
    }

    /**
     * @group basic
     */
    public function testErrorHandlingBasic(): void
    {
        // Test getting non-existent model
        $model = Testing::Get(999999);
        $this->assertNull($model);
        
        // Test empty query
        Testing::_table()->delete([]);
        $query = Testing::Query();
        $this->assertEquals(0, $query->count());
        $this->assertNull($query->first());
        $this->assertEmpty($query->toArray());
    }
}
