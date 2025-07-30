<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Light\Db\Query;

/**
 * Improved Query tests with comprehensive coverage
 */
final class QueryTest extends BaseTestCase
{
    private function seedTestData(int $count = 5): array
    {
        Testing::_table()->delete([]); // Clear existing data first
        
        $models = [];
        for ($i = 1; $i <= $count; $i++) {
            $model = Testing::Create([
                'name' => "test_user_{$i}"
            ]);
            $model->save();
            $models[] = $model;
        }
        return $models;
    }

    /**
     * @group query_basic
     */
    public function testBasicQuery(): void
    {
        $this->seedTestData(3);
        
        $query = Testing::Query();
        $this->assertInstanceOf(Query::class, $query);
        $this->assertEquals(3, $query->count());
    }

    /**
     * @group query_filters
     */
    public function testQueryWithConditions(): void
    {
        $this->seedTestData(5);
        
        $query = Testing::Query(['name' => 'test_user_1']);
        $this->assertEquals(1, $query->count());
        
        $first = $query->first();
        $this->assertEquals('test_user_1', $first->name);
    }

    /**
     * @group query_filters
     * @dataProvider filterOperatorProvider
     */
    public function testAdvancedFilters(array $filter, int $expectedCount): void
    {
        $this->seedTestData(5);
        
        $query = Testing::Query()->filters($filter);
        $result = $query->count();
        
        $this->assertEquals($expectedCount, $result);
    }

    public static function filterOperatorProvider(): array
    {
        return [
            'equals' => [['testing_id' => ['eq' => 1]], 1],
            'greater_than' => [['testing_id' => ['gt' => 3]], 2],
            'greater_equal' => [['testing_id' => ['gte' => 3]], 3],
            'less_than' => [['testing_id' => ['lt' => 3]], 2],
            'less_equal' => [['testing_id' => ['lte' => 3]], 3],
            'in_array' => [['testing_id' => ['in' => [1, 3, 5]]], 3],
        ];
    }

    /**
     * @group query_sorting
     */
    public function testSorting(): void
    {
        $models = $this->seedTestData(5);
        
        // Test ascending sort
        $ascQuery = Testing::Query()->sort('testing_id:asc');
        $ascResults = $ascQuery->toArray();
        $this->assertNotEmpty($ascResults);
        
        // Check that first ID is less than or equal to last ID
        $firstId = $ascResults[0]->testing_id;
        $lastId = $ascResults[count($ascResults) - 1]->testing_id;
        $this->assertLessThanOrEqual($lastId, $firstId);
        
        // Test descending sort
        $descQuery = Testing::Query()->sort('testing_id:desc');
        $descResults = $descQuery->toArray();
        $this->assertNotEmpty($descResults);
        
        // Check that first ID is greater than or equal to last ID
        $firstId = $descResults[0]->testing_id;
        $lastId = $descResults[count($descResults) - 1]->testing_id;
        $this->assertGreaterThanOrEqual($lastId, $firstId);
    }

    /**
     * @group query_aggregation
     */
    public function testAggregationFunctions(): void
    {
        $models = $this->seedTestData(5);
        
        $query = Testing::Query();
        
        $count = $query->count();
        $this->assertEquals(5, $count);
        
        $sum = $query->sum('testing_id');
        $avg = $query->avg('testing_id');
        $min = $query->min('testing_id');
        $max = $query->max('testing_id');
        
        $this->assertIsNumeric($sum);
        $this->assertIsNumeric($avg);
        $this->assertIsNumeric($min);
        $this->assertIsNumeric($max);
        
        // Basic validation
        $this->assertGreaterThan(0, $sum);
        $this->assertGreaterThan(0, $avg);
        $this->assertLessThanOrEqual($max, $sum); // sum should be >= max
    }

    /**
     * @group query_collection
     */
    public function testCollectionMethods(): void
    {
        $models = $this->seedTestData(5);
        
        $query = Testing::Query();
        
        // Test map
        $ids = $query->map(fn($model) => $model->testing_id)->toArray();
        $this->assertCount(5, $ids);
        $this->assertContainsOnly('int', $ids);
        
        // Test filter
        $allModels = $query->toArray();
        $midPoint = count($allModels) > 0 ? $allModels[intval(count($allModels)/2)]->testing_id : 1;
        $filtered = $query->filter(fn($model) => $model->testing_id > $midPoint);
        $this->assertLessThanOrEqual(5, $filtered->count());
        
        // Test first
        $first = $query->first();
        $this->assertInstanceOf(Testing::class, $first);
        
        // Test toArray
        $array = $query->toArray();
        $this->assertIsArray($array);
        $this->assertCount(5, $array);
    }

    /**
     * @group query_modification
     */
    public function testBulkUpdate(): void
    {
        $this->seedTestData(3);
        
        $affected = Testing::Query(['testing_id' => ['lte' => 2]])
            ->update(['name' => 'updated_name']);
        
        $this->assertEquals(2, $affected);
        
        // Verify updates
        $updated = Testing::Query(['name' => 'updated_name'])->count();
        $this->assertEquals(2, $updated);
    }

    /**
     * @group query_modification
     */
    public function testBulkDelete(): void
    {
        $this->seedTestData(5);
        
        $deleted = Testing::Query(['testing_id' => ['gt' => 3]])->delete();
        
        $this->assertEquals(2, $deleted);
        $this->assertEquals(3, Testing::Query()->count());
    }

    /**
     * @group query_pagination
     */
    public function testPagination(): void
    {
        $this->seedTestData(10);
        
        $query = Testing::Query();
        $paginator = $query->getPaginator();
        
        $this->assertNotNull($paginator);
        $this->assertTrue(method_exists($paginator, 'getCurrentItems'));
        
        // Test page size
        $paginator->setItemCountPerPage(3);
        $items = $paginator->getCurrentItems();
        $this->assertCount(3, $items);
    }

    /**
     * @group query_performance
     */
    public function testQueryChaining(): void
    {
        $this->seedTestData(10);
        
        $result = Testing::Query()
            ->filters(['testing_id' => ['gte' => 3]])
            ->sort('testing_id:desc')
            ->limit(3)
            ->toArray();
        
        $this->assertCount(3, $result);
        $this->assertEquals(10, $result[0]->testing_id);
        $this->assertEquals(8, $result[2]->testing_id);
    }

    /**
     * @group query_edge_cases
     */
    public function testEmptyQueries(): void
    {
        // No data seeded
        $query = Testing::Query();
        
        $this->assertEquals(0, $query->count());
        $this->assertNull($query->first());
        $this->assertEmpty($query->toArray());
        
        // Aggregation on empty set
        $this->assertEquals(0, $query->sum('testing_id'));
        $this->assertNull($query->avg('testing_id'));
    }

    /**
     * @group query_complex
     */
    public function testComplexFilters(): void
    {
        $this->seedTestData(10);
        
        // Complex filter with multiple conditions
        $query = Testing::Query()->filters([
            'testing_id' => ['gte' => 3, 'lte' => 7],
            'name' => ['like' => '%user%']
        ]);
        
        $results = $query->toArray();
        $this->assertCount(5, $results); // IDs 3, 4, 5, 6, 7
        
        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(3, $result->testing_id);
            $this->assertLessThanOrEqual(7, $result->testing_id);
            $this->assertStringContainsString('user', $result->name);
        }
    }

    /**
     * @group query_relationships
     */
    public function testRelationshipQueries(): void
    {
        // This test assumes User and UserList models exist with proper relationships
        if (class_exists('User') && class_exists('UserList')) {
            $user = User::Get(1);
            if ($user) {
                $userLists = $user->UserList;
                $this->assertInstanceOf(Query::class, $userLists);
                
                $firstList = $userLists->first();
                if ($firstList) {
                    $this->assertInstanceOf('UserList', $firstList);
                }
            }
        } else {
            $this->markTestSkipped('User and UserList models not available for relationship testing');
        }
    }
}
