<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Light\Db\Query;

/**
 * Comprehensive Query tests using well-defined test tables
 */
final class QueryTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up existing data and insert our test data
        $this->cleanupTestData();
        $this->insertTestData();
    }
    
    protected function tearDown(): void
    {
        // Clean up after each test
        $this->cleanupTestData();
        parent::tearDown();
    }
    
    private function insertTestData(): void
    {
        // Insert specific test data for QueryTest
        $testData = [
            [
                'name' => 'Test User 1',
                'email' => 'test1@example.com',
                'age' => 25,
                'score' => 85.5,
                'is_active' => 1,
                'birth_date' => '1998-01-15',
                'status' => 'active',
                'salary' => 50000.00
            ],
            [
                'name' => 'Test User 2',
                'email' => 'test2@example.com',
                'age' => 30,
                'score' => 92.3,
                'is_active' => 1,
                'birth_date' => '1993-05-20',
                'status' => 'active',
                'salary' => 60000.00
            ],
            [
                'name' => 'Test User 3',
                'email' => 'test3@example.com',
                'age' => 28,
                'score' => 78.9,
                'is_active' => 0,
                'birth_date' => '1995-08-10',
                'status' => 'inactive',
                'salary' => 45000.00
            ],
        ];
        
        foreach ($testData as $data) {
            $model = Testing::Create($data);
            $model->save();
        }
    }

    /**
     * @group query_basic
     */
    public function testBasicQuery(): void
    {
        // Create fresh query instance after database setup
        $query = Testing::Query();
        $this->assertInstanceOf(Query::class, $query);
        
        // Should have our 3 test records
        $count = $query->count();
        $this->assertGreaterThanOrEqual(3, $count); // We have 3 default records
    }

    /**
     * @group query_basic
     */
    public function testQueryWithConditions(): void
    {
        // Query for active users
        $activeQuery = Testing::Query(['status' => 'active']);
        $activeCount = $activeQuery->count();
        $this->assertGreaterThanOrEqual(2, $activeCount);
        
        // Query for specific user
        $userQuery = Testing::Query(['name' => 'Test User 1']);
        $this->assertEquals(1, $userQuery->count());
        
        $user = $userQuery->first();
        $this->assertEquals('Test User 1', $user->name);
        $this->assertEquals('test1@example.com', $user->email);
    }

    /**
     * @group query_filters
     * @dataProvider filterOperatorProvider
     */
    public function testAdvancedFilters(array $filter, string $description): void
    {
        $query = Testing::Query()->filters($filter);
        $result = $query->count();
        
        // All filters should return some reasonable result
        $this->assertGreaterThanOrEqual(0, $result, "Filter failed: {$description}");
        
        if ($result > 0) {
            $first = $query->first();
            $this->assertInstanceOf(Testing::class, $first);
        }
    }

    public static function filterOperatorProvider(): array
    {
        return [
            'age_equals_25' => [['age' => ['eq' => 25]], 'Age equals 25'],
            'age_greater_than_20' => [['age' => ['gt' => 20]], 'Age greater than 20'],
            'age_greater_equal_25' => [['age' => ['gte' => 25]], 'Age greater or equal to 25'],
            'age_less_than_35' => [['age' => ['lt' => 35]], 'Age less than 35'],
            'score_between' => [['score' => ['gte' => 70, 'lte' => 90]], 'Score between 70-90'],
            'status_in_array' => [['status' => ['in' => ['active', 'pending']]], 'Status in array'],
            'email_like' => [['email' => ['like' => '%test%']], 'Email contains test'],
        ];
    }

    /**
     * @group query_sorting
     */
    public function testSorting(): void
    {
        // Test ascending sort by age
        $ascQuery = Testing::Query()->sort('age:asc');
        $ascResults = $ascQuery->toArray();
        $this->assertNotEmpty($ascResults);
        
        // Verify ascending order
        for ($i = 1; $i < count($ascResults); $i++) {
            $this->assertLessThanOrEqual($ascResults[$i]->age, $ascResults[$i-1]->age);
        }
        
        // Test descending sort by score
        $descQuery = Testing::Query()->sort('score:desc');
        $descResults = $descQuery->toArray();
        $this->assertNotEmpty($descResults);
        
        // Verify descending order
        for ($i = 1; $i < count($descResults); $i++) {
            $this->assertGreaterThanOrEqual($descResults[$i]->score, $descResults[$i-1]->score);
        }
        
        // Test multiple column sort
        $multiQuery = Testing::Query()->sort('status:asc,age:desc');
        $multiResults = $multiQuery->toArray();
        $this->assertNotEmpty($multiResults);
    }

    /**
     * @group query_aggregation
     */
    public function testAggregationFunctions(): void
    {
        $query = Testing::Query();
        
        $count = $query->count();
        $this->assertGreaterThan(0, $count);
        
        // Test numeric aggregations
        $avgAge = $query->avg('age');
        $sumAge = $query->sum('age');
        $minAge = $query->min('age');
        $maxAge = $query->max('age');
        
        $this->assertIsNumeric($avgAge);
        $this->assertIsNumeric($sumAge);
        $this->assertIsNumeric($minAge);
        $this->assertIsNumeric($maxAge);
        
        // Logical validations
        $this->assertGreaterThan(0, $avgAge);
        $this->assertGreaterThan(0, $sumAge);
        $this->assertLessThanOrEqual($maxAge, $avgAge);
        $this->assertGreaterThanOrEqual($minAge, $avgAge);
        
        // Test decimal aggregations
        $avgScore = $query->avg('score');
        $maxScore = $query->max('score');
        $this->assertIsNumeric($avgScore);
        $this->assertIsNumeric($maxScore);
    }

    /**
     * @group query_collection
     */
    public function testCollectionMethods(): void
    {
        $query = Testing::Query();
        
        // Test map - extract names
        $names = $query->map(fn($model) => $model->name)->toArray();
        $this->assertNotEmpty($names);
        $this->assertContainsOnly('string', $names);
        $this->assertContains('Test User 1', $names);
        
        // Test filter - active users only
        $activeUsers = $query->filter(fn($model) => $model->status === 'active');
        $this->assertGreaterThan(0, $activeUsers->count());
        
        // Test first
        $first = $query->first();
        $this->assertInstanceOf(Testing::class, $first);
        $this->assertNotEmpty($first->name);
        
        // Test toArray
        $array = $query->toArray();
        $this->assertIsArray($array);
        $this->assertNotEmpty($array);
        $this->assertContainsOnlyInstancesOf(Testing::class, $array);
    }

    /**
     * @group query_modification
     */
    public function testBulkUpdate(): void
    {
        // Insert test records first
        $testModels = [];
        for ($i = 1; $i <= 3; $i++) {
            $model = Testing::Create([
                'name' => "Bulk Test {$i}",
                'email' => "bulk{$i}@test.com",
                'status' => 'pending'
            ]);
            $model->save();
            $testModels[] = $model;
        }
        
        // Update all pending records
        $affected = Testing::Query(['status' => 'pending'])
            ->update(['status' => 'active']);
        
        $this->assertGreaterThanOrEqual(3, $affected);
        
        // Verify updates
        $updatedCount = Testing::Query(['status' => 'active'])->count();
        $this->assertGreaterThanOrEqual(3, $updatedCount);
        
        // Cleanup
        foreach ($testModels as $model) {
            $model->delete();
        }
    }

    /**
     * @group query_modification
     */
    public function testBulkDelete(): void
    {
        // Insert test records
        $testModels = [];
        for ($i = 1; $i <= 3; $i++) {
            $model = Testing::Create([
                'name' => "Delete Test {$i}",
                'email' => "delete{$i}@test.com",
                'status' => 'inactive'
            ]);
            $model->save();
            $testModels[] = $model;
        }
        
        $initialCount = Testing::Query()->count();
        
        // Delete all inactive records
        $deleted = Testing::Query(['status' => 'inactive'])->delete();
        $this->assertGreaterThanOrEqual(3, $deleted);
        
        // Verify deletion
        $finalCount = Testing::Query()->count();
        $this->assertEquals($initialCount - $deleted, $finalCount);
        
        // Verify specific records are gone
        $inactiveCount = Testing::Query(['status' => 'inactive'])->count();
        $this->assertEquals(0, $inactiveCount);
    }

    /**
     * @group query_pagination
     */
    public function testPagination(): void
    {
        $query = Testing::Query();
        $paginator = $query->getPaginator();
        
        $this->assertNotNull($paginator);
        $this->assertTrue(method_exists($paginator, 'getCurrentItems'));
        
        // Test page size
        $paginator->setItemCountPerPage(2);
        $items = $paginator->getCurrentItems();
        $this->assertLessThanOrEqual(2, count($items));
        
        // Test page navigation
        $paginator->setCurrentPageNumber(1);
        $page1Items = $paginator->getCurrentItems();
        
        if (count($page1Items) > 0) {
            $this->assertContainsOnlyInstancesOf(Testing::class, $page1Items);
        }
        
        // Test total count
        $totalItems = $paginator->getTotalItemCount();
        $this->assertGreaterThan(0, $totalItems);
    }

    /**
     * @group query_complex
     */
    public function testComplexQueries(): void
    {
        // Complex query with multiple conditions
        $complexQuery = Testing::Query()->filters([
            'age' => ['gte' => 20, 'lte' => 35],
            'is_active' => ['eq' => 1],
            'email' => ['like' => '%test%']
        ])->sort('score:desc');
        
        $results = $complexQuery->toArray();
        $this->assertNotEmpty($results);
        
        // Verify all conditions are met
        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(20, $result->age);
            $this->assertLessThanOrEqual(35, $result->age);
            $this->assertEquals(1, $result->is_active);
            $this->assertStringContainsString('test', $result->email);
        }
        
        // Verify sorting (descending by score)
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual($results[$i]->score, $results[$i-1]->score);
        }
    }

    /**
     * @group query_json
     */
    public function testJsonQueries(): void
    {
        // Create test record with JSON data
        $model = Testing::Create([
            'name' => 'JSON Query Test',
            'j' => [
                'settings' => ['theme' => 'dark', 'notifications' => true],
                'scores' => [85, 92, 78],
                'tags' => ['important', 'test']
            ]
        ]);
        $model->save();
        
        try {
            // Test JSON field queries (if supported)
            $jsonQuery = Testing::Query(['name' => 'JSON Query Test']);
            $result = $jsonQuery->first();
            
            if ($result && $result->j) {
                $this->assertIsArray($result->j);
                $this->assertEquals('dark', $result->j['settings']['theme']);
                $this->assertTrue($result->j['settings']['notifications']);
                $this->assertContains(85, $result->j['scores']);
            }
        } finally {
            // Cleanup
            $model->delete();
        }
    }

    /**
     * @group query_edge_cases
     */
    public function testEmptyQueries(): void
    {
        // Query for non-existent records
        $emptyQuery = Testing::Query(['name' => 'NonExistentUser']);
        
        $this->assertEquals(0, $emptyQuery->count());
        $this->assertNull($emptyQuery->first());
        $this->assertEmpty($emptyQuery->toArray());
        
        // Aggregation on empty set
        $this->assertEquals(0, $emptyQuery->sum('age'));
        $this->assertNull($emptyQuery->avg('age'));
        $this->assertNull($emptyQuery->min('age'));
        $this->assertNull($emptyQuery->max('age'));
    }

    /**
     * @group query_performance
     */
    public function testQueryPerformance(): void
    {
        $start = microtime(true);
        
        // Perform multiple queries
        $queries = [
            Testing::Query(['status' => 'active']),
            Testing::Query()->sort('age:desc'),
            Testing::Query()->filters(['age' => ['gte' => 20]]),
        ];
        
        foreach ($queries as $query) {
            $results = $query->toArray();
            $this->assertIsArray($results);
        }
        
        $duration = microtime(true) - $start;
        $this->assertLessThan(2.0, $duration, 'Queries should complete in reasonable time');
    }
}
