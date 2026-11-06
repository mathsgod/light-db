<?php

declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\Model;
use Light\Linq\ClosureTranslationException;
use Light\Linq\Expressions\ScalarFunctionExpression;
use PHPUnit\Framework\Attributes\DataProvider;

class ClosureUser extends Model { protected static $_table = 'User'; }
class FilteredClosureUser extends Model
{
    protected static $_table = 'User';
    protected static function queryFilterDefinitions(): array
    {
        return ['adult' => ['age' => ['gte' => 20]]];
    }
}

final class ClosureQueryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("ALTER TABLE User ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
        $this->pdo->exec("UPDATE User SET status = 'inactive' WHERE id = 3");
    }

    public function testWhereClosureReturnsModelsAndLeavesOriginalQueryUnchanged(): void
    {
        $root = ClosureUser::Query();
        $active = $root->where(fn($user) => $user->status == 'active');
        self::assertNotSame($root, $active);
        self::assertSame(3, $root->count());
        self::assertSame(2, $active->count());
        $models = $active->orderBy('id')->toArray();
        self::assertInstanceOf(ClosureUser::class, $models[0]);
        self::assertSame([1, 2], array_map(fn($user) => $user->id, $models));
    }

    public function testModelQueryAcceptsAnInitialClosureAndCapturedParameters(): void
    {
        $status = 'active';
        $query = ClosureUser::Query(fn($user) => $user->status == $status);
        self::assertSame(['Ada', 'Bob'], $query->orderBy('id')->select('name')->toArray());
        self::assertSame('Ada', $query->where(['id' => 1])->first()->name);
        self::assertTrue($query->any());
    }

    public function testScalarAndNamedClosureProjectionsPreservePaging(): void
    {
        $root = ClosureUser::Query();
        $query = $root->where(fn($user) => $user->status == 'active')->orderBy('id')->offset(1)->limit(1);
        self::assertSame(['Bob'], $query->select(fn($user) => $user->name)->toArray());
        self::assertSame([['id' => 2, 'name' => 'Bob']], $query->select(fn($user) => ['id' => $user->id, 'name' => $user->name])->toArray());
        self::assertSame(3, $root->count());
        self::assertInstanceOf(ClosureUser::class, $query->first());
    }

    public function testClosurePreservesGlobalFiltersAndTheirExplicitBypass(): void
    {
        $root = FilteredClosureUser::Query()->where(fn($user) => $user->age < 30)->orderBy('id');
        self::assertSame([2], $root->select(fn($user) => $user->id)->toArray());
        self::assertSame([2, 3], $root->ignoreQueryFilters()->select(fn($user) => $user->id)->toArray());
    }

    public function testValuesAreBoundAndProjectionCanComposeAnotherClosure(): void
    {
        $status = "' OR 1=1 --";
        $query = ClosureUser::Query()->where(fn($user) => $user->status == $status)
            ->select(fn($user) => ['id' => $user->id, 'name' => $user->name]);
        $compiled = $query->provider()->compile($query->expression());
        self::assertStringNotContainsString($status, $compiled->sql);
        self::assertContains($status, $compiled->parameters);
        self::assertSame([], $query->toArray());
        $selected = ClosureUser::Query()->select(fn($user) => ['id' => $user->id, 'name' => $user->name]);
        self::assertSame([['id' => 2, 'name' => 'Bob']], $selected->where(fn($row) => $row->id == 2)->toArray());
    }

    public function testCapturedScalarProjectionWorksAsAContainsSubquery(): void
    {
        $ids = ClosureUser::Query()->where(['status' => 'active'])->select(fn($user) => $user->id);
        self::assertSame(['Ada', 'Bob'], ClosureUser::Query()->where(fn($user) => $ids->contains($user->id))
            ->orderBy('id')->select(fn($user) => $user->name)->toArray());
    }

    public function testUnsupportedPhpIsRejectedWithoutExecutingIt(): void
    {
        $called = false;
        $custom = function ($user) use (&$called) { $called = true; return true; };
        try {
            ClosureUser::Query()->where(fn($user) => $custom($user));
            self::fail('Expected closure translation to fail.');
        } catch (ClosureTranslationException) {
            self::assertFalse($called);
        }
    }

    public function testLatestScalarCoalesceAndConditionalFeaturesPassThrough(): void
    {
        if (!class_exists(ScalarFunctionExpression::class)) {
            self::markTestSkipped('A light-linq version with ScalarFunctionExpression support is required.');
        }
        $rows = ClosureUser::Query()->where(fn($user) => str_starts_with(strtolower(trim($user->name)), 'a'))
            ->select(fn($user) => [
                'id' => $user->id,
                'name' => $user->name ?? 'Guest',
                'size' => $user->age >= 20 ? 'adult' : 'minor',
                'bytes' => strlen($user->name),
                'age' => round(abs($user->age), 0),
            ])->toArray();
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertSame('Ada', $rows[0]['name']);
        self::assertSame('adult', $rows[0]['size']);
        self::assertSame(3, $rows[0]['bytes']);
        self::assertEquals(30, $rows[0]['age']);
    }

    public static function mixedSelectors(): iterable
    {
        yield 'closure plus name' => [fn($user) => $user->name, 'id'];
        yield 'two closures' => [fn($user) => $user->id, fn($row) => $row->name];
    }

    #[DataProvider('mixedSelectors')]
    public function testClosureProjectionMustBeTheOnlySelector(\Closure $selector, string|\Closure $extra): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClosureUser::Query()->select($selector, $extra);
    }
}
