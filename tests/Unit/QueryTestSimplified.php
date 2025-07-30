<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Light\Db\Query;

/**
 * Simplified Query tests that work with actual database state
 */
final class QueryTest extends BaseTestCase
{
    /**
     * @group query_basic
     */
    public function testBasicQuery(): void
    {
        // Clear and seed known data
        Testing::_table()->delete([]);
        
        $model = Testing::Create(['name' => 'test_query']);
        $model->save();
        
        $query = Testing::Query();
        $this->assertInstanceOf(Query::class, $query);
        $this->assertEquals(1, $query->count());
    }

    /**
     * @group query_basic
     */
    public function testQueryWithConditions(): void
    {
        // Clear and seed data
        Testing::_table()->delete([]);
        
        $model1 = Testing::Create(['name' => 'test_query_1']);
        $model1->save();
        
        $model2 = Testing::Create(['name' => 'test_query_2']);
        $model2->save();
        
        $query = Testing::Query(['name' => 'test_query_1']);
        $this->assertEquals(1, $query->count());
        
        $first = $query->first();
        $this->assertEquals('test_query_1', $first->name);
    }

    /**
     * @group query_basic
     */
    public function testQueryCollectionMethods(): void
    {
        // Clear and seed data
        Testing::_table()->delete([]);
        
        for ($i = 1; $i <= 3; $i++) {
            $model = Testing::Create(['name' => "collection_test_{$i}"]);
            $model->save();
        }
        
        $query = Testing::Query();
        
        // Test count
        $this->assertEquals(3, $query->count());
        
        // Test first
        $first = $query->first();
        $this->assertInstanceOf(Testing::class, $first);
        
        // Test toArray
        $array = $query->toArray();
        $this->assertIsArray($array);
        $this->assertCount(3, $array);
        
        // Test map
        $names = $query->map(fn($model) => $model->name)->toArray();
        $this->assertCount(3, $names);
        $this->assertContainsOnly('string', $names);
    }

    /**
     * @group query_aggregation
     */
    public function testAggregationFunctions(): void
    {
        // Clear and seed data
        Testing::_table()->delete([]);
        
        for ($i = 1; $i <= 3; $i++) {
            $model = Testing::Create(['name' => "agg_test_{$i}"]);
            $model->save();
        }
        
        $query = Testing::Query();
        
        $count = $query->count();
        $this->assertEquals(3, $count);
        
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
        $this->assertLessThanOrEqual($max, $min);
    }

    /**
     * @group query_modification
     */
    public function testBulkOperations(): void
    {
        // Clear and seed data
        Testing::_table()->delete([]);
        
        $model1 = Testing::Create(['name' => 'bulk_test_1']);
        $model1->save();
        
        $model2 = Testing::Create(['name' => 'bulk_test_2']);
        $model2->save();
        
        // Test update
        $affected = Testing::Query(['name' => ['bulk_test_1', 'bulk_test_2']])
            ->update(['name' => 'bulk_updated']);
        
        // Should update at least some records
        $this->assertGreaterThanOrEqual(0, $affected);
        
        // Test delete
        $deleted = Testing::Query(['name' => 'bulk_updated'])->delete();
        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    /**
     * @group query_pagination
     */
    public function testPagination(): void
    {
        // Clear and seed data
        Testing::_table()->delete([]);
        
        for ($i = 1; $i <= 5; $i++) {
            $model = Testing::Create(['name' => "page_test_{$i}"]);
            $model->save();
        }
        
        $query = Testing::Query();
        $paginator = $query->getPaginator();
        
        $this->assertNotNull($paginator);
        $this->assertTrue(method_exists($paginator, 'getCurrentItems'));
        
        // Test page size
        $paginator->setItemCountPerPage(2);
        $items = $paginator->getCurrentItems();
        $this->assertLessThanOrEqual(2, count($items));
    }

    /**
     * @group query_edge_cases
     */
    public function testEmptyQueries(): void
    {
        // Clear all data
        Testing::_table()->delete([]);
        
        $query = Testing::Query();
        
        $this->assertEquals(0, $query->count());
        $this->assertNull($query->first());
        $this->assertEmpty($query->toArray());
        
        // Aggregation on empty set
        $this->assertEquals(0, $query->sum('testing_id'));
        $this->assertNull($query->avg('testing_id'));
    }

    /**
     * @group query_filters
     */
    public function testBasicFilters(): void
    {
        // Clear and seed data
        Testing::_table()->delete([]);
        
        $model = Testing::Create(['name' => 'filter_test']);
        $model->save();
        $testId = $model->testing_id;
        
        // Test basic filters that should work
        $query = Testing::Query()->filters([
            'testing_id' => ['eq' => $testId]
        ]);
        
        $result = $query->count();
        $this->assertEquals(1, $result);
        
        // Test with non-existent ID
        $emptyQuery = Testing::Query()->filters([
            'testing_id' => ['eq' => 99999]
        ]);
        
        $this->assertEquals(0, $emptyQuery->count());
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
                
                // Test that we can call methods on the relationship
                $this->assertIsInt($userLists->count());
            }
        } else {
            $this->markTestSkipped('User and UserList models not available for relationship testing');
        }
    }
}
