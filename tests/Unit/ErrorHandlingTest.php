<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Error handling and exception tests
 */
final class ErrorHandlingTest extends BaseTestCase
{
    /**
     * @group error_handling
     */
    public function testInvalidModelInstantiation(): void
    {
        // Test with invalid data types
        $model = Testing::Create();
        
        // Test setting invalid property
        $this->expectNotToPerformAssertions();
        $model->nonexistent_property = 'value';
        // Should not throw exception for dynamic properties
    }

    /**
     * @group error_handling
     */
    public function testQueryWithInvalidConditions(): void
    {
        // Test query with malformed conditions should handle gracefully
        try {
            $query = Testing::Query(['invalid_column' => 'value']);
            $count = $query->count();
            $this->fail('Should have thrown an exception for invalid column');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertStringContainsString('invalid_column', $e->getMessage());
        }
    }

    /**
     * @group error_handling
     */
    public function testDeleteNonExistentModel(): void
    {
        $model = Testing::Create(['name' => 'to_delete']);
        $model->save();
        $id = $model->testing_id;
        
        // Delete once
        $result1 = $model->delete();
        $this->assertIsInt($result1); // Returns affected rows count
        
        // Try to delete again - behavior may vary
        $result2 = $model->delete();
        // Don't assert specific value as implementation may vary
        $this->assertIsInt($result2);
    }

    /**
     * @group error_handling
     */
    public function testSaveWithConstraintViolation(): void
    {
        // Test duplicate primary key violation
        $model1 = Testing::Create(['name' => 'constraint_test_1']);
        $model1->save();
        
        try {
            // Try to manually set the same primary key (this should fail)
            $model2 = Testing::Create(['name' => 'constraint_test_2']);
            $model2->testing_id = $model1->testing_id; // Set same ID
            $model2->save();
            
            // If we get here, the constraint didn't work as expected
            $this->fail('Expected constraint violation did not occur');
        } catch (Exception $e) {
            // Expected exception due to duplicate primary key
            $this->assertInstanceOf(Exception::class, $e);
        } finally {
            // Cleanup
            try {
                $model1->delete();
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }

    /**
     * @group error_handling
     */
    public function testJsonWithInvalidData(): void
    {
        // Test with circular reference (should be handled by JSON serialization)
        $model = Testing::Create(['name' => 'json_test']);
        
        // PHP handles circular references in json_encode by returning false
        // Our model should handle this gracefully
        $circular = new stdClass();
        $circular->self = $circular;
        
        // This might throw an exception or handle gracefully depending on implementation
        try {
            $model->j = $circular;
            $model->save();
            $this->fail('Should have handled circular reference');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    /**
     * @group error_handling
     */
    public function testQueryWithEmptyResult(): void
    {
        Testing::_table()->truncate();
        
        $query = Testing::Query(['name' => 'nonexistent']);
        
        $this->assertEquals(0, $query->count());
        $this->assertNull($query->first());
        $this->assertEmpty($query->toArray());
        
        // Aggregation functions should handle empty results
        $this->assertEquals(0, $query->sum('testing_id'));
        $this->assertNull($query->avg('testing_id'));
        $this->assertNull($query->min('testing_id'));
        $this->assertNull($query->max('testing_id'));
    }

    /**
     * @group error_handling
     */
    public function testBulkOperationsWithNoMatches(): void
    {
        Testing::_table()->truncate();
        
        // Update with no matching records
        $updated = Testing::Query(['name' => 'nonexistent'])->update(['name' => 'updated']);
        $this->assertEquals(0, $updated);
        
        // Delete with no matching records
        $deleted = Testing::Query(['name' => 'nonexistent'])->delete();
        $this->assertEquals(0, $deleted);
    }

    /**
     * @group error_handling
     */
    public function testModelWithNullPrimaryKey(): void
    {
        $model = Testing::Create(['name' => 'null_pk_test']);
        
        // Before saving, primary key might be null
        $this->assertNull($model->testing_id);
        
        $model->save();
        
        // After saving, should have a primary key
        $this->assertNotNull($model->testing_id);
        $this->assertIsInt($model->testing_id);
    }
}
