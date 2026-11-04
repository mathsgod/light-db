<?php

declare(strict_types=1);
namespace Light\Db\Tests;

use Light\Db\Model;
use Light\Linq\Expr;

class GloballyFilteredUser extends Model
{
    protected static $_table = 'User';
    public static int $tenantId = 1;
    protected static function queryFilterDefinitions(): array
    {
        return [
            'softDelete'=>Expr::eq(Expr::field('is_deleted'),0),
            'tenant'=>fn () => ['tenant_id'=>static::$tenantId],
        ];
    }
}

final class GlobalQueryFilterTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GloballyFilteredUser::$tenantId=1;
        $this->pdo->exec('ALTER TABLE User ADD COLUMN is_deleted INT DEFAULT 0');
        $this->pdo->exec('ALTER TABLE User ADD COLUMN tenant_id INT DEFAULT 1');
        $this->pdo->exec('UPDATE User SET is_deleted=1 WHERE id=2');
        $this->pdo->exec('UPDATE User SET tenant_id=2 WHERE id=3');
    }

    public function testModelDefinitionsApplyToQueryGetAggregatesAndReadOnly(): void
    {
        $query=GloballyFilteredUser::Query();
        self::assertSame([1],array_map(fn ($u)=>$u->id,$query->toArray()));
        self::assertSame(1,$query->count());
        self::assertEquals(30,$query->sum('age'));
        self::assertNull(GloballyFilteredUser::Get(2));
        self::assertNull(GloballyFilteredUser::Get(3));
        self::assertTrue($query->asReadOnly()->first()->isReadOnly());
        self::assertSame([1],array_map(fn ($u)=>$u->id,iterator_to_array($query->cursor())));
        $normal=$query->first();
        $normal->name='Changed';
        self::assertSame(1,$normal->save());
        self::assertSame('Changed',$query->first()->name);
    }

    public function testIgnoreNamesIsImmutableAndPreservesReadOnly(): void
    {
        $root=GloballyFilteredUser::Query()->asReadOnly();
        self::assertSame(2,$root->ignoreQueryFilters(['softDelete'])->count());
        self::assertSame(2,$root->ignoreQueryFilters(['tenant'])->count());
        self::assertSame(3,$root->ignoreQueryFilters()->count());
        self::assertSame(1,$root->count());
        $hidden=$root->ignoreQueryFilters(['softDelete'])->where(['id'=>2])->first();
        self::assertTrue($hidden->isReadOnly());
        self::assertSame('Bob',$hidden->name);
        $this->expectException(\LogicException::class);
        $hidden->save();
    }

    public function testCachedReadContextUsesCurrentRuntimeValues(): void
    {
        $query=GloballyFilteredUser::Query();
        self::assertSame(1,$query->first()->id);
        GloballyFilteredUser::$tenantId=2;
        self::assertSame(3,$query->first()->id);
        self::assertNull(GloballyFilteredUser::Get(1));
        GloballyFilteredUser::$tenantId=1;
        self::assertSame(1,$query->first()->id);
    }

    public function testBulkWritesUseGlobalFiltersAndCanExplicitlyIgnore(): void
    {
        self::assertSame(1,GloballyFilteredUser::Query()->update(['age'=>99]));
        self::assertSame(20,(int)$this->pdo->query('SELECT age FROM User WHERE id=2')->fetchColumn());
        self::assertSame(10,(int)$this->pdo->query('SELECT age FROM User WHERE id=3')->fetchColumn());
        self::assertSame(2,GloballyFilteredUser::Query()->ignoreQueryFilters(['softDelete'])->delete());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM User')->fetchColumn());
    }

    public function testCustomCallbacksKeepIgnoreAndReadOnlyScopesAcrossTheChain(): void
    {
        GloballyFilteredUser::RegisterFilter('fresh',fn ($v,$q)=>GloballyFilteredUser::Query());
        GloballyFilteredUser::RegisterOrder('freshOrder',fn ($v,$q)=>GloballyFilteredUser::Query()->orderBy('id'));
        GloballyFilteredUser::RegisterFilter('ignore',fn ($v,$q)=>$q->ignoreQueryFilters()->asReadOnly());
        $query=GloballyFilteredUser::Query()->asReadOnly()->ignoreQueryFilters()->filters(['fresh'=>1])->order('freshOrder');
        self::assertSame(3,$query->count());
        self::assertTrue($query->first()->isReadOnly());
        $query=GloballyFilteredUser::Query()->filters(['ignore'=>1,'fresh'=>1]);
        self::assertSame(3,$query->count());
        self::assertTrue($query->first()->isReadOnly());
    }
}
