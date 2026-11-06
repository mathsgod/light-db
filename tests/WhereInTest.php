<?php
declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\{Database, Model};
use Light\Linq\Expr;
use PHPUnit\Framework\Attributes\DataProvider;

class MembershipUser extends Model { protected static $_table = 'User'; }
class MembershipRole extends Model
{
    protected static $_table = 'memberships';
    public static int $active = 1;
    protected static function queryFilterDefinitions(): array
    {
        return ['active' => fn () => ['b' => self::$active]];
    }
}

final class WhereInTest extends DatabaseTestCase
{
    public static function lists(): iterable
    {
        yield ['whereIn', [1, 3], [1, 3]];
        yield ['whereNotIn', [1, 3], [2]];
        yield ['whereIn', [], []];
        yield ['whereNotIn', [], [1, 2, 3]];
    }

    #[DataProvider('lists')]
    public function testListsComposeWithoutMutatingTheOriginal(string $method, array $values, array $expected): void
    {
        $root = MembershipUser::Query();
        $query = $root->$method('id', $values)->orderBy('id');
        self::assertSame($expected, array_column($query->toArray(), 'id'));
        self::assertSame(count($expected), $query->count());
        self::assertSame(3, $root->count());
    }

    public function testSubqueriesPreserveParametersAndPagination(): void
    {
        $subquery = $this->db->set(MembershipUser::class)->whereRaw('age >= ?', [20])->select(Expr::field('id'));
        $root = MembershipUser::Query()->whereRaw('age > ?', [0]);
        self::assertSame([1, 2], array_column($root->whereIn('id', $subquery)->orderBy('id')->toArray(), 'id'));
        self::assertSame([3], array_column($root->whereNotIn('id', $subquery)->toArray(), 'id'));
        self::assertSame(2, $root->whereIn('id', $subquery)->orderBy('id')->offset(1)->limit(1)->first()->id);
        self::assertSame(3, $root->count());
    }

    public function testSubqueryWithNoRows(): void
    {
        $subquery = $this->db->set(MembershipUser::class)->where(['id' => 99])->select(Expr::field('id'));
        self::assertSame(0, MembershipUser::Query()->whereIn('id', $subquery)->count());
        self::assertSame(3, MembershipUser::Query()->whereNotIn('id', $subquery)->count());
    }

    public function testSubqueryFromAnotherProviderOnTheSameConnectionIsAllowed(): void
    {
        $other = new Database($this->pdo);
        $subquery = $other->set(MembershipUser::class)->select(Expr::field('id'));
        self::assertSame(3, MembershipUser::Query()->whereIn('id', $subquery)->count());
    }

    public function testCrossModelSubqueriesKeepDynamicFiltersAndParameters(): void
    {
        MembershipRole::$active = 1;
        try {
            $this->pdo->exec("INSERT INTO memberships VALUES (1,1,'admins'),(2,0,'admins'),(3,1,'users')");
            $ids = $this->db->set(MembershipRole::class)->whereRaw('name = ?', ['admins'])->select(Expr::field('a'));
            $root = MembershipUser::Query()->whereRaw('age > ?', [0]);
            $query = $root->whereIn('id', $ids);
            self::assertSame([1], array_column($query->toArray(), 'id'));
            self::assertSame([2, 3], array_column($root->whereNotIn('id', $ids)->orderBy('id')->toArray(), 'id'));
            self::assertSame(2, $query->ignoreQueryFilters()->count());
            $unfiltered = $this->db->set(MembershipRole::class)->ignoreQueryFilters()->where(['name' => 'admins'])->select(Expr::field('a'));
            self::assertSame(2, $root->whereIn('id', $unfiltered)->count());
            MembershipRole::$active = 0;
            self::assertSame([2], array_column($query->toArray(), 'id'));
            self::assertSame(3, $root->count());
        } finally {
            MembershipRole::$active = 1;
        }
    }

    public function testSubqueryFromAnotherConnectionIsRejected(): void
    {
        if ($this->schema !== null) {
            $pdo = new \PDO(getenv('LIGHT_DB_TEST_DSN'), getenv('LIGHT_DB_TEST_USER') ?: 'root', getenv('LIGHT_DB_TEST_PASSWORD') ?: '');
            $pdo->exec('USE `'.$this->schema.'`');
        } else {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE User (id INTEGER PRIMARY KEY)');
        }
        $subquery = (new Database($pdo))->set(MembershipUser::class)->select(Expr::field('id'));
        $this->expectException(\InvalidArgumentException::class);
        MembershipUser::Query()->whereIn('id', $subquery);
    }

    public function testUnknownColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MembershipUser::Query()->whereIn('missing', [1]);
    }

    public function testAssociativeValuesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MembershipUser::Query()->whereNotIn('id', ['first' => 1]);
    }
}
