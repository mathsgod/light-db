<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Light\Db\FilterHelper;
use Laminas\Db\Sql\Predicate\Predicate;

/**
 * Comprehensive FilterHelper tests
 */
final class FilterHelperTest extends TestCase
{
    private string $fieldName = 'test_field';

    /**
     * Test basic equality filtering with simple values
     */
    public function testSimpleValueFiltering(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, 'test_value');
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check the expression data structure
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        $this->assertIsArray($expressionData);
        
        // The first expression should be an equality operation
        $firstExpression = $expressionData[0];
        $this->assertIsArray($firstExpression);
        $this->assertCount(3, $firstExpression);
        $specification = $firstExpression[0];
        $this->assertStringContainsString('=', $specification);
    }

    /**
     * Test numeric value filtering
     */
    public function testNumericValueFiltering(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, 123);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Should create equality predicate for numeric values
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
    }

    /**
     * Test null value filtering
     */
    public function testNullValueFiltering(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, null);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
    }

    /**
     * Test _eq operator
     */
    public function testEqualToOperator(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_eq' => 'test_value']);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check the expression data
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
    }

    /**
     * Test _ne operator
     */
    public function testNotEqualToOperator(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_ne' => 'test_value']);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check the expression data for NOT EQUAL operation
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        $firstExpression = $expressionData[0];
        $this->assertIsArray($firstExpression);
        $specification = $firstExpression[0];
        $this->assertStringContainsString('!=', $specification);
    }

    /**
     * Test comparison operators (_gt, _gte, _lt, _lte)
     */
    public function testComparisonOperators(): void
    {
        $operators = [
            '_gt' => 10,
            '_gte' => 10,
            '_lt' => 20,
            '_lte' => 20
        ];

        foreach ($operators as $operator => $value) {
            $predicate = FilterHelper::processFilterValue($this->fieldName, [$operator => $value]);
            
            $this->assertInstanceOf(Predicate::class, $predicate);
            
            $expressionData = $predicate->getExpressionData();
            $this->assertNotEmpty($expressionData, "Failed for operator: {$operator}");
        }
    }

    /**
     * Test _between operator
     */
    public function testBetweenOperator(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_between' => [10, 20]]);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check the expression data for BETWEEN operation
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have a BETWEEN expression
        $firstExpression = $expressionData[0];
        $specification = $firstExpression[0];
        $this->assertStringContainsString('BETWEEN', $specification);
    }

    /**
     * Test _notBetween operator
     */
    public function testNotBetweenOperator(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_notBetween' => [10, 20]]);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check the expression data for NOT BETWEEN operation
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have a NOT BETWEEN expression
        $firstExpression = $expressionData[0];
        $specification = $firstExpression[0];
        $this->assertStringContainsString('NOT BETWEEN', $specification);
    }

    /**
     * Test _in operator
     */
    public function testInOperator(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_in' => ['value1', 'value2', 'value3']]);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check the expression data for IN operation
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have an IN expression
        $firstExpression = $expressionData[0];
        $specification = $firstExpression[0];
        $this->assertStringContainsString('IN', $specification);
    }

    /**
     * Test _notIn operator
     */
    public function testNotInOperator(): void
    {
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_notIn' => ['value1', 'value2']]);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check the expression data for NOT IN operation
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have a NOT IN expression
        $firstExpression = $expressionData[0];
        $specification = $firstExpression[0];
        $this->assertStringContainsString('NOT IN', $specification);
    }

    /**
     * Test LIKE operators (_contains, _startsWith, _endsWith)
     */
    public function testLikeOperators(): void
    {
        // Test _contains
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_contains' => 'test']);
        $this->assertInstanceOf(Predicate::class, $predicate);
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have a LIKE expression
        $firstExpression = $expressionData[0];
        $specification = $firstExpression[0];
        $this->assertStringContainsString('LIKE', $specification);

        // Test _startsWith
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_startsWith' => 'test']);
        $this->assertInstanceOf(Predicate::class, $predicate);
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);

        // Test _endsWith
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_endsWith' => 'test']);
        $this->assertInstanceOf(Predicate::class, $predicate);
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
    }

    /**
     * Test NULL operators (_null, _notNull)
     */
    public function testNullOperators(): void
    {
        // Test _null
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_null' => true]);
        $this->assertInstanceOf(Predicate::class, $predicate);
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have an IS NULL expression
        $firstExpression = $expressionData[0];
        $specification = $firstExpression[0];
        $this->assertStringContainsString('IS NULL', $specification);

        // Test _notNull
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_notNull' => true]);
        $this->assertInstanceOf(Predicate::class, $predicate);
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have an IS NOT NULL expression
        $firstExpression = $expressionData[0];
        $specification = $firstExpression[0];
        $this->assertStringContainsString('IS NOT NULL', $specification);
    }

    /**
     * Test OR logical operator
     */
    public function testOrLogicalOperator(): void
    {
        $filter = [
            '_or' => [
                ['_eq' => 'value1'],
                ['_eq' => 'value2'],
                ['_gt' => 10]
            ]
        ];

        $predicate = FilterHelper::processFilterValue($this->fieldName, $filter);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check that we have multiple expressions (for OR logic)
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // OR logic should create nested predicates
        $this->assertGreaterThan(1, count($expressionData));
    }

    /**
     * Test AND logical operator
     */
    public function testAndLogicalOperator(): void
    {
        $filter = [
            '_and' => [
                ['_gt' => 10],
                ['_lt' => 20],
                ['_ne' => 15]
            ]
        ];

        $predicate = FilterHelper::processFilterValue($this->fieldName, $filter);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Check that we have multiple expressions (for AND logic)
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // AND logic should create nested predicates
        $this->assertGreaterThan(1, count($expressionData));
    }

    /**
     * Test array with simple values (default AND logic)
     */
    public function testSimpleArrayValues(): void
    {
        $filter = [
            'value1',
            'value2',
            'value3'
        ];

        $predicate = FilterHelper::processFilterValue($this->fieldName, $filter);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Should use AND logic by default for array values
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Multiple values should create nested predicates
        $this->assertGreaterThan(1, count($expressionData));
    }

    /**
     * Test multiple operators in single condition
     */
    public function testMultipleOperators(): void
    {
        $filter = [
            '_gt' => 10,
            '_lt' => 20,
            '_ne' => 15
        ];

        $predicate = FilterHelper::processFilterValue($this->fieldName, $filter);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Should contain multiple conditions
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
    }

    /**
     * Test complex nested conditions
     */
    public function testComplexNestedConditions(): void
    {
        $filter = [
            '_or' => [
                [
                    '_and' => [
                        ['_gt' => 10],
                        ['_lt' => 20]
                    ]
                ],
                ['_eq' => 25],
                ['_in' => [30, 35, 40]]
            ]
        ];

        $predicate = FilterHelper::processFilterValue($this->fieldName, $filter);
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        
        // Complex nested conditions should create multiple expression levels
        $expressionData = $predicate->getExpressionData();
        $this->assertNotEmpty($expressionData);
        
        // Should have nested structure
        $this->assertGreaterThan(1, count($expressionData));
    }

    /**
     * Test edge cases and invalid operators
     */
    public function testEdgeCases(): void
    {
        // Empty array
        $predicate = FilterHelper::processFilterValue($this->fieldName, []);
        $this->assertInstanceOf(Predicate::class, $predicate);

        // Boolean values
        $predicate = FilterHelper::processFilterValue($this->fieldName, true);
        $this->assertInstanceOf(Predicate::class, $predicate);

        $predicate = FilterHelper::processFilterValue($this->fieldName, false);
        $this->assertInstanceOf(Predicate::class, $predicate);

        // Empty string
        $predicate = FilterHelper::processFilterValue($this->fieldName, '');
        $this->assertInstanceOf(Predicate::class, $predicate);
    }

    /**
     * Test field expressions with different formats
     */
    public function testDifferentFieldExpressions(): void
    {
        $fieldExpressions = [
            'simple_field',
            'table.field',
            'schema.table.field',
            'field_with_underscore',
            'field123'
        ];

        foreach ($fieldExpressions as $fieldExpression) {
            $predicate = FilterHelper::processFilterValue($fieldExpression, 'test_value');
            $this->assertInstanceOf(Predicate::class, $predicate);
            
            $expressionData = $predicate->getExpressionData();
            $this->assertNotEmpty($expressionData, "Failed for field: {$fieldExpression}");
        }
    }

    /**
     * Test with various data types
     */
    public function testVariousDataTypes(): void
    {
        $testValues = [
            'string_value',
            123,
            123.45,
            true,
            false,
            null,
            '2024-01-01',
            '2024-01-01 12:00:00'
        ];

        foreach ($testValues as $value) {
            $predicate = FilterHelper::processFilterValue($this->fieldName, $value);
            $this->assertInstanceOf(Predicate::class, $predicate);
            
            $expressionData = $predicate->getExpressionData();
            $this->assertNotEmpty($expressionData, "Failed for value: " . var_export($value, true));
        }
    }

    /**
     * Test performance with large arrays
     */
    public function testPerformanceWithLargeArrays(): void
    {
        $largeArray = range(1, 1000);
        
        $start = microtime(true);
        $predicate = FilterHelper::processFilterValue($this->fieldName, ['_in' => $largeArray]);
        $duration = microtime(true) - $start;
        
        $this->assertInstanceOf(Predicate::class, $predicate);
        $this->assertLessThan(0.1, $duration, 'FilterHelper should handle large arrays efficiently');
    }

    /**
     * Test with special characters and Unicode
     */
    public function testSpecialCharacters(): void
    {
        $specialValues = [
            "test'quote",
            'test"doublequote',
            'test\backslash',
            'test%percent',
            'test_underscore',
            '测试中文',
            'тест кириллица',
            'test emoji 🔥'
        ];

        foreach ($specialValues as $value) {
            $predicate = FilterHelper::processFilterValue($this->fieldName, ['_eq' => $value]);
            $this->assertInstanceOf(Predicate::class, $predicate);
            
            $predicate = FilterHelper::processFilterValue($this->fieldName, ['_contains' => $value]);
            $this->assertInstanceOf(Predicate::class, $predicate);
        }
    }
}