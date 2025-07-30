<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive Model tests using well-defined test tables
 */
final class ModelTest extends BaseTestCase
{
    /**
     * @group crud
     */
    public function testModelCreation(): void
    {
        $model = Testing::Create([
            'name' => 'Test Model',
            'email' => 'test@example.com',
            'age' => 25,
            'score' => 85.5,
            'is_active' => true
        ]);
        
        $this->assertInstanceOf(Testing::class, $model);
        $this->assertEquals('Test Model', $model->name);
        $this->assertEquals('test@example.com', $model->email);
        $this->assertEquals(25, $model->age);
        $this->assertEquals(85.5, $model->score);
        $this->assertTrue($model->is_active);
    }

    /**
     * @group crud
     */
    public function testModelSave(): void
    {
        $model = Testing::Create([
            'name' => 'Save Test',
            'email' => 'save@test.com',
            'age' => 30
        ]);
        
        $result = $model->save();
        $this->assertIsInt($result);
        $this->assertNotNull($model->testing_id);
        
        // Verify in database
        $retrieved = Testing::Get($model->testing_id);
        $this->assertNotNull($retrieved);
        $this->assertEquals('Save Test', $retrieved->name);
    }

    /**
     * @group crud
     */
    public function testModelUpdate(): void
    {
        $model = Testing::Create(['name' => 'Original Name', 'email' => 'original@test.com']);
        $model->save();
        
        $model->name = 'Updated Name';
        $model->email = 'updated@test.com';
        $model->save();
        
        $retrieved = Testing::Get($model->testing_id);
        $this->assertEquals('Updated Name', $retrieved->name);
        $this->assertEquals('updated@test.com', $retrieved->email);
    }

    /**
     * @group crud
     */
    public function testModelDelete(): void
    {
        $model = Testing::Create(['name' => 'Delete Test']);
        $model->save();
        $id = $model->testing_id;
        
        $result = $model->delete();
        $this->assertIsInt($result);
        
        $deleted = Testing::Get($id);
        $this->assertNull($deleted);
    }

    /**
     * @group json
     */
    public function testJsonColumnOperations(): void
    {
        $jsonData = [
            'profile' => [
                'nickname' => 'TestUser',
                'preferences' => ['theme' => 'dark', 'language' => 'zh-TW']
            ],
            'scores' => [85, 92, 78],
            'metadata' => [
                'created_by' => 'system',
                'tags' => ['important', 'test']
            ]
        ];
        
        $model = Testing::Create([
            'name' => 'JSON Test',
            'j' => $jsonData
        ]);
        $model->save();

        // Test retrieval
        $retrieved = Testing::Get($model->testing_id);
        $this->assertEquals('TestUser', $retrieved->j['profile']['nickname']);
        $this->assertEquals('dark', $retrieved->j['profile']['preferences']['theme']);
        $this->assertEquals([85, 92, 78], $retrieved->j['scores']);
        $this->assertContains('important', $retrieved->j['metadata']['tags']);

        // Test modification
        $retrieved->j['profile']['nickname'] = 'UpdatedUser';
        $retrieved->j['new_field'] = 'new_value';
        $retrieved->save();

        // Verify changes persisted
        $updated = Testing::Get($model->testing_id);
        $this->assertEquals('UpdatedUser', $updated->j['profile']['nickname']);
        $this->assertEquals('new_value', $updated->j['new_field']);
    }

    /**
     * @group data_types
     * @dataProvider dataTypeProvider
     */
    public function testDataTypes($field, $value, $expected = null): void
    {
        $expected = $expected ?? $value;
        
        $model = Testing::Create(['name' => 'Data Type Test']);
        $model->$field = $value;
        $model->save();
        
        $retrieved = Testing::Get($model->testing_id);
        
        if (is_float($expected)) {
            $this->assertEqualsWithDelta($expected, $retrieved->$field, 0.01);
        } else {
            $this->assertEquals($expected, $retrieved->$field);
        }
    }

    public static function dataTypeProvider(): array
    {
        return [
            'integer_age' => ['age', 25, 25],
            'decimal_score' => ['score', 85.75, 85.75],
            'boolean_true' => ['is_active', true, 1],
            'boolean_false' => ['is_active', false, 0],
            'date_birth' => ['birth_date', '1995-05-15', '1995-05-15'],
            'float_salary' => ['salary', 50000.50, 50000.50],
            'enum_status_active' => ['status', 'active', 'active'],
            'enum_status_inactive' => ['status', 'inactive', 'inactive'],
            'text_description' => ['description', 'This is a long description for testing text fields.', 'This is a long description for testing text fields.'],
        ];
    }

    /**
     * @group validation
     */
    public function testNullableFields(): void
    {
        $model = Testing2::Create([
            'name' => 'Null Test',
            'null_field' => null,
            'not_null_field' => null  // Should be converted to empty string
        ]);
        $model->save();
        
        $retrieved = Testing2::Get($model->testing2_id);
        $this->assertNull($retrieved->null_field);
        $this->assertEquals('', $retrieved->not_null_field);
    }

    /**
     * @group validation
     * @dataProvider booleanValueProvider
     */
    public function testBooleanHandling($value, $expected): void
    {
        $model = Testing2::Create(['name' => 'Boolean Test']);
        $model->b = $value;
        $model->save();
        
        $retrieved = Testing2::Get($model->testing2_id);
        $this->assertEquals($expected, (bool)$retrieved->b);
    }

    public static function booleanValueProvider(): array
    {
        return [
            'true_boolean' => [true, true],
            'false_boolean' => [false, false],
            'one_integer' => [1, true],
            'zero_integer' => [0, false],
        ];
    }

    /**
     * @group state
     */
    public function testDirtyTracking(): void
    {
        $model = Testing::Create(['name' => 'Dirty Test']);
        $model->save();
        
        // Initially not dirty after save
        $this->assertFalse($model->isDirty());
        $this->assertFalse($model->isDirty('name'));
        
        // Make changes
        $model->name = 'Changed Name';
        $model->age = 30;
        
        // Should be dirty
        $this->assertTrue($model->isDirty());
        $this->assertTrue($model->isDirty('name'));
        $this->assertTrue($model->isDirty('age'));
        
        // Check dirty fields
        $dirty = $model->getDirty();
        $this->assertArrayHasKey('name', $dirty);
        $this->assertArrayHasKey('age', $dirty);
        $this->assertEquals('Changed Name', $dirty['name']);
        $this->assertEquals(30, $dirty['age']);
    }

    /**
     * @group array_serialization
     */
    public function testArraySerialization(): void
    {
        $model = Testing::Create([
            'name' => 'Array Test',
            'tags' => ['tag1', 'tag2', 'tag3']  // Should be serialized as comma-separated
        ]);
        $model->save();
        
        $retrieved = Testing::Get($model->testing_id);
        // Depending on implementation, might be stored as comma-separated string
        $this->assertTrue(
            $retrieved->tags === 'tag1,tag2,tag3' || 
            is_array($retrieved->tags)
        );
    }

    /**
     * @group edge_cases
     */
    public function testEmptyAndSpecialValues(): void
    {
        $model = Testing::Create([
            'name' => '',  // Empty string
            'email' => null,  // Null value
            'age' => 0,  // Zero value
            'score' => 0.0,  // Zero float
            'description' => '   ',  // Whitespace
        ]);
        $model->save();
        
        $retrieved = Testing::Get($model->testing_id);
        $this->assertEquals('', $retrieved->name);
        $this->assertNull($retrieved->email);
        $this->assertEquals(0, $retrieved->age);
        $this->assertEquals(0.0, $retrieved->score);
        $this->assertEquals('   ', $retrieved->description);
    }

    /**
     * @group metadata
     */
    public function testModelMetadata(): void
    {
        $key = Testing::_key();
        $this->assertEquals('testing_id', $key);
        
        $table = Testing::_table();
        $this->assertEquals('Testing', $table->getTable());
        
        $attributes = Testing::__attribute();
        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);
        
        // The attributes might be Column objects, so we need to handle that
        $columnNames = [];
        foreach ($attributes as $key => $attribute) {
            if (is_object($attribute) && method_exists($attribute, 'getName')) {
                $columnNames[] = $attribute->getName();
            } elseif (is_string($attribute)) {
                $columnNames[] = $attribute;
            } elseif (is_string($key)) {
                $columnNames[] = $key;
            }
        }
        
        $this->assertContains('name', $columnNames);
        $this->assertContains('email', $columnNames);
    }

    /**
     * @group timestamps
     */
    public function testTimestamps(): void
    {
        $model = Testing::Create(['name' => 'Timestamp Test']);
        $model->save();
        
        $retrieved = Testing::Get($model->testing_id);
        
        // Should have automatic timestamps
        $this->assertNotNull($retrieved->created_at);
        $this->assertNotNull($retrieved->updated_at);
        
        // Update and check if updated_at changes
        $originalUpdatedAt = $retrieved->updated_at;
        sleep(1); // Ensure time difference
        $retrieved->name = 'Updated Name';
        $retrieved->save();
        
        $updated = Testing::Get($model->testing_id);
        $this->assertNotEquals($originalUpdatedAt, $updated->updated_at);
    }
}
