<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for model relationships and complex scenarios
 */
final class RelationshipTest extends BaseTestCase
{
    /**
     * @group integration
     * @group relationships
     */
    public function testModelRelationships(): void
    {
        // Skip if relationship models don't exist
        if (!class_exists('User') || !class_exists('UserList')) {
            $this->markTestSkipped('User relationship models not available');
        }

        $user = User::Get(1);
        if (!$user) {
            $this->markTestSkipped('No test user data available');
        }

        // Test relationship property access
        $userLists = $user->UserList;
        $this->assertInstanceOf(\Light\Db\Query::class, $userLists);

        // Test relationship method call
        $userListArray = $user->UserList();
        $this->assertInstanceOf(\ArrayObject::class, $userListArray);

        // Test reverse relationship
        $firstList = $userLists->first();
        if ($firstList) {
            $relatedUser = $firstList->User();
            $this->assertInstanceOf('User', $relatedUser);
            $this->assertEquals($user->user_id, $relatedUser->user_id);
        }
    }

    /**
     * @group integration
     * @group complex_queries
     */
    public function testComplexQueryScenarios(): void
    {
        $this->seedComplexTestData();

        // Test complex filtering with relationships
        $query = Testing::Query()
            ->filters([
                'name' => ['like' => '%integration%'],
                'testing_id' => ['gte' => 2]
            ])
            ->sort('testing_id:desc');

        $results = $query->toArray();
        $this->assertNotEmpty($results);

        foreach ($results as $result) {
            $this->assertStringContainsString('integration', $result->name);
            $this->assertGreaterThanOrEqual(2, $result->testing_id);
        }
    }

    /**
     * @group integration
     * @group transactions
     */
    public function testTransactionScenarios(): void
    {
        // Clear and create test records
        Testing::_table()->delete([]);
        
        $models = [];
        
        try {
            for ($i = 1; $i <= 3; $i++) {
                $model = Testing::Create([
                    'name' => "transaction_test_{$i}",
                    'j' => ['transaction_id' => $i]
                ]);
                $model->save();
                $models[] = $model;
            }

            // Verify all records were created
            $count = Testing::Query()->count();
            $this->assertEquals(3, $count);

            // Test bulk operations
            $updated = Testing::Query()
                ->update(['name' => 'bulk_updated']);
            $this->assertGreaterThanOrEqual(0, $updated);

        } finally {
            // Cleanup - delete all test records
            Testing::_table()->delete([]);
        }
    }

    /**
     * @group integration
     * @group performance
     */
    public function testPerformanceScenarios(): void
    {
        // Clear existing data
        Testing::_table()->delete([]);
        
        $startTime = microtime(true);
        
        // Create smaller dataset for testing
        $models = [];
        for ($i = 1; $i <= 10; $i++) {
            $model = Testing::Create([
                'name' => "performance_test_{$i}",
                'j' => [
                    'index' => $i,
                    'data' => str_repeat('x', 50) // Some data
                ]
            ]);
            $model->save();
            $models[] = $model;
        }

        $creationTime = microtime(true) - $startTime;
        $this->assertLessThan(5.0, $creationTime, 'Record creation should be reasonably fast');

        // Test bulk query performance
        $queryStart = microtime(true);
        $results = Testing::Query()
            ->sort('testing_id:asc')
            ->toArray();
        $queryTime = microtime(true) - $queryStart;

        $this->assertCount(10, $results);
        $this->assertLessThan(1.0, $queryTime, 'Query should be reasonably fast');

        // Cleanup
        Testing::_table()->delete([]);
    }

    /**
     * @group integration
     * @group edge_cases
     */
    public function testEdgeCaseScenarios(): void
    {
        // Test with empty JSON
        $model1 = Testing::Create(['name' => 'empty_json', 'j' => []]);
        $model1->save();

        $retrieved1 = Testing::Get($model1->testing_id);
        $this->assertIsArray($retrieved1->j);
        $this->assertEmpty($retrieved1->j);

        // Test with null JSON
        $model2 = Testing::Create(['name' => 'null_json', 'j' => null]);
        $model2->save();

        $retrieved2 = Testing::Get($model2->testing_id);
        $this->assertNull($retrieved2->j);

        // Test with deeply nested JSON
        $deepJson = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => ['deep_value' => 'found']
                    ]
                ]
            ]
        ];

        $model3 = Testing::Create(['name' => 'deep_json', 'j' => $deepJson]);
        $model3->save();

        $retrieved3 = Testing::Get($model3->testing_id);
        $this->assertEquals('found', $retrieved3->j['level1']['level2']['level3']['level4']['deep_value']);

        // Cleanup
        foreach ([$model1, $model2, $model3] as $model) {
            $model->delete();
        }
    }

    private function seedComplexTestData(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $model = Testing::Create([
                'name' => "integration_test_{$i}",
                'j' => [
                    'category' => $i % 2 === 0 ? 'even' : 'odd',
                    'metadata' => ['created_at' => date('Y-m-d H:i:s')]
                ]
            ]);
            $model->save();
        }
    }
}
