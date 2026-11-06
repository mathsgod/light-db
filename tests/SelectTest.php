<?php
declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\Model;
use Light\Linq\Expr;
use PHPUnit\Framework\Attributes\DataProvider;

class ProjectionUser extends Model { protected static $_table = 'User'; }
class FilteredProjectionUser extends Model
{
    protected static $_table = 'User';
    protected static function queryFilterDefinitions(): array
    {
        return ['adult' => ['age' => ['gte' => 20]]];
    }
}

final class SelectTest extends DatabaseTestCase
{
    public function testOneColumnReturnsScalarsAndMultipleColumnsReturnRows(): void
    {
        self::assertSame([1, 2, 3], ProjectionUser::Query()->orderBy('id')->select('id')->toArray());
        self::assertSame([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Cara'],
        ], ProjectionUser::Query()->orderBy('id')->select('id', 'name')->toArray());
    }

    public function testFiltersOrderingPaginationAndOriginalQueryArePreserved(): void
    {
        $root = ProjectionUser::Query();
        $query = $root->where(['age' => ['gte' => 20]])->orderBy('age')->offset(1)->limit(1);
        self::assertSame(['Ada'], $query->select('name')->toArray());
        self::assertSame([['id' => 1, 'name' => 'Ada']], $query->select('id', 'name')->toArray());
        self::assertSame(3, $root->count());
        self::assertSame('Ada', $root->where(['id' => 1])->first()->name);
    }

    public function testProjectionExpressionsAndGlobalFilters(): void
    {
        $root = FilteredProjectionUser::Query()->orderBy('id');
        self::assertSame([1, 2], $root->select(Expr::field('id'))->toArray());
        self::assertSame([
            ['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob'],
        ], $root->select(Expr::fields('id', 'name'))->toArray());
        self::assertSame([1, 2, 3], $root->ignoreQueryFilters()->select('id')->toArray());
    }

    public function testProjectionWorksAsACrossModelSubquery(): void
    {
        $ids = FilteredProjectionUser::Query()->select('id');
        self::assertSame([1, 2], ProjectionUser::Query()->whereIn('id', $ids)->orderBy('id')->select('id')->toArray());
        self::assertSame([3], ProjectionUser::Query()->whereNotIn('id', $ids)->select('id')->toArray());
    }

    public function testProjectedRowsCanBeComposedWithoutHydratingModels(): void
    {
        $query = ProjectionUser::Query()->select('id', 'name')->where(Expr::gt(Expr::field('id'), 1))->orderBy(Expr::field('id'));
        self::assertSame([['id' => 2, 'name' => 'Bob'], ['id' => 3, 'name' => 'Cara']], $query->toArray());
    }

    public static function invalidColumns(): iterable
    {
        yield [[]];
        yield [['unknown']];
        yield [['id', 'unknown']];
        yield [['id; DROP TABLE User']];
        yield [['id', Expr::field('name')]];
    }

    #[DataProvider('invalidColumns')]
    public function testInvalidColumnSelectionsAreRejected(array $columns): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectionUser::Query()->select(...$columns);
    }
}
