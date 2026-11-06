<?php
declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\{Database, Model};
use Light\Linq\Expr;
use PHPUnit\Framework\Attributes\DataProvider;

class MembershipUser extends Model { protected static $_table = 'User'; }

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

    public function testSubqueryFromAnotherProviderIsRejected(): void
    {
        $other = new Database($this->pdo);
        $subquery = $other->set(MembershipUser::class)->select(Expr::field('id'));
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
