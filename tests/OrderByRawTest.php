<?php
declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\Model;

class RawOrderUser extends Model { protected static $_table = 'User'; }

final class OrderByRawTest extends DatabaseTestCase
{
    public function testDirectionsParametersAndImmutableQueries(): void
    {
        $root = RawOrderUser::Query();
        $query = $root->whereRaw('age > ?', [0])->orderByRaw(
            '(SELECT age FROM User AS other WHERE other.id = User.id) + :offset',
            parameters: ['offset' => 0],
            descending: true,
        );
        self::assertSame([1, 2, 3], array_column($query->toArray(), 'id'));
        self::assertSame([3, 2, 1], array_column($root->orderByRaw('age')->toArray(), 'id'));
        self::assertSame(3, $root->count());
        self::assertSame([2], array_column($query->offset(1)->limit(1)->toArray(), 'id'));
    }

    public function testRawAndNormalOrderingAppendInBothDirections(): void
    {
        $query = RawOrderUser::Query()->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [2])
            ->orderBy('age', descending: true);
        self::assertSame([2, 1, 3], array_column($query->toArray(), 'id'));
        $query = RawOrderUser::Query()->orderByRaw('CASE WHEN id > 0 THEN 1 ELSE 1 END')
            ->orderByRaw('CASE WHEN id = :id THEN 0 ELSE 1 END', ['id' => 3])
            ->orderBy('id');
        self::assertSame([3, 1, 2], array_column($query->toArray(), 'id'));
        $query = RawOrderUser::Query()->orderBy('age')->orderByRaw('id', descending: true);
        self::assertSame([3, 2, 1], array_column($query->toArray(), 'id'));
    }
}
