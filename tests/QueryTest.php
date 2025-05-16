<?php

declare(strict_types=1);
error_reporting(E_ALL);

use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    public function testFilters()
    {
        $q = User::Query()->filters([
            "user_id" => [
                "eq" => 1
            ]
        ]);

        $user = $q->first();
        $this->assertEquals(1, $user->user_id);
    }

    public function testSortAndMap()
    {
        $q = User::Query()->sort('user_id:desc');
        $arr = $q->map(fn($u) => $u->user_id)->toArray();
        $this->assertIsArray($arr);
    }

    public function testCount()
    {
        $q = User::Query()->filters([
            "user_id" => [
                "gt" => 0
            ]
        ]);
        $count = $q->count();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testToArray()
    {
        $q = User::Query()->filters([
            "user_id" => [
                "gt" => 0
            ]
        ]);
        $arr = $q->toArray();
        $this->assertIsArray($arr);
    }

    public function testFilter()
    {
        $q = User::Query();
        $filtered = $q->filter(fn($u) => $u->user_id === 1);
        $this->assertTrue($filtered->count() >= 0);
    }

    public function testAvgSumMinMax()
    {
        $q = User::Query()->filters([
            "user_id" => [
                "gt" => 0
            ]
        ]);
        $avg = $q->avg('user_id');
        $sum = $q->sum('user_id');
        $min = $q->min('user_id');
        $max = $q->max('user_id');
        $this->assertIsNumeric($avg);
        $this->assertIsNumeric($sum);
        $this->assertIsNumeric($min);
        $this->assertIsNumeric($max);
    }

    public function testDeleteUpdate()
    {
        // 新增一筆測試資料
        $user = Testing::Create(['testing_id' => 9999, 'name' => 'test']);
        $user->save();

        // 更新
        $q = Testing::Query(['testing_id' => 9999]);
        $affected = $q->update(['name' => 'updated']);
        $this->assertGreaterThanOrEqual(0, $affected);

        // 刪除
        $deleted = $q->delete();
        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    public function testPaginator()
    {
        $q = User::Query();
        $paginator = $q->getPaginator();
        $this->assertNotNull($paginator);
        $this->assertTrue(method_exists($paginator, 'getCurrentItems'));
    }
}
