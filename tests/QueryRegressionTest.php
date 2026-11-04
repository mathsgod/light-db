<?php

declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\{Database, Model, Query};
use PHPUnit\Framework\Attributes\DataProvider;
use PDO;

class RegressionUser extends Model { protected static $_table = 'User'; }
class OtherRegressionUser extends Model { protected static $_table = 'User'; }
class PlainRegressionUser extends Model { protected static $_table = 'plain_users'; }

final class QueryRegressionTest extends DatabaseTestCase
{
    public static function filters(): iterable
    {
        yield 'equal' => [['id'=>1], [1]];
        yield 'not equal alias' => [['id'=>['ne'=>2]], [1,3]];
        yield 'greater than' => [['age'=>['gt'=>20]], [1]];
        yield 'greater or equal' => [['age'=>['gte'=>20]], [1,2]];
        yield 'less than' => [['age'=>['lt'=>20]], [3]];
        yield 'less or equal' => [['age'=>['lte'=>20]], [2,3]];
        yield 'implicit IN' => [['id'=>[1,3]], [1,3]];
        yield 'explicit IN' => [['id'=>['in'=>[1,3]]], [1,3]];
        yield 'NOT IN' => [['id'=>['notIn'=>[1,3]]], [2]];
        yield 'empty IN' => [['id'=>['in'=>[]]], []];
        yield 'empty NOT IN' => [['id'=>['notIn'=>[]]], [1,2,3]];
        yield 'inclusive between' => [['age'=>['between'=>[20,30]]], [1,2]];
        yield 'not between' => [['age'=>['notBetween'=>[20,30]]], [3]];
        yield 'null equality' => [['settings'=>null], [2,3]];
        yield 'null predicate' => [['settings'=>['null'=>true]], [2,3]];
        yield 'negated null predicate' => [['settings'=>['null'=>false]], [1]];
        yield 'not null predicate' => [['settings'=>['notNull'=>true]], [1]];
        yield 'contains' => [['name'=>['contains'=>'d']], [1]];
        yield 'starts with' => [['name'=>['startsWith'=>'B']], [2]];
        yield 'ends with' => [['name'=>['endsWith'=>'ra']], [3]];
        yield 'LIKE pattern' => [['name'=>['like'=>'C%']], [3]];
        yield 'empty AND' => [['_and'=>[]], [1,2,3]];
        yield 'empty OR' => [['_or'=>[]], []];
        yield 'NOT group' => [['_not'=>['age'=>['gte'=>20]]], [3]];
        yield 'nested AND OR NOT' => [['_and'=>[['_or'=>[['id'=>1],['age'=>['lt'=>25]]]],['_not'=>['name'=>'Cara']]]], [1,2]];
        yield 'multiple conditions on one field' => [['age'=>['gte'=>15,'lt'=>30]], [2]];
    }

    #[DataProvider('filters')]
    public function testStructuredFiltersReturnExpectedRecords(array $filter, array $expected): void
    {
        $query = RegressionUser::Query()->where($filter)->orderBy('id');
        self::assertSame($expected, array_map(fn ($u) => $u->id, $query->toArray()));
        self::assertSame(count($expected), $query->count());
        self::assertSame($expected !== [], $query->any());
    }

    public static function invalidFilters(): iterable
    {
        yield 'unknown field' => [['missing'=>1]];
        yield 'unknown operator' => [['age'=>['bad'=>1]]];
        yield 'IN scalar' => [['id'=>['in'=>1]]];
        yield 'IN associative array' => [['id'=>['in'=>['key'=>1]]]];
        yield 'between one endpoint' => [['age'=>['between'=>[10]]]];
        yield 'between three endpoints' => [['age'=>['between'=>[10,20,30]]]];
        yield 'between scalar' => [['age'=>['between'=>20]]];
        yield 'null requires bool' => [['settings'=>['null'=>'yes']]];
        yield 'string filter requires string' => [['name'=>['contains'=>123]]];
        yield 'ordered null comparison' => [['age'=>['gt'=>null]]];
        yield 'AND requires list' => [['_and'=>['age'=>20]]];
        yield 'OR requires array' => [['_or'=>false]];
        yield 'NOT requires map' => [['_not'=>'bad']];
    }

    #[DataProvider('invalidFilters')]
    public function testMalformedFiltersAreRejected(array $filter): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RegressionUser::Query()->where($filter)->toArray();
    }

    public function testStringFiltersEscapeLiteralWildcardAndEscapeCharacters(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO User(name,age) VALUES(?,?)');
        $insert->execute(['%_!suffix',40]);
        $insert->execute(['prefix%_!',40]);
        $insert->execute(['prefixABC',40]);
        self::assertSame([4,5], array_map(fn ($u) => $u->id, RegressionUser::Query()->where(['name'=>['contains'=>'%_!']])->orderBy('id')->toArray()));
        self::assertSame(4, RegressionUser::Query()->where(['name'=>['startsWith'=>'%_!']])->first()->id);
        self::assertSame(5, RegressionUser::Query()->where(['name'=>['endsWith'=>'%_!']])->first()->id);
        self::assertSame(0, RegressionUser::Query()->where(['name'=>['contains'=>"' OR 1=1 --"]])->count());
    }

    public function testOneHundredPlainRecordsMatchPdoAndSupportBothQueryModes(): void
    {
        $this->pdo->exec('CREATE TABLE plain_users(id INT PRIMARY KEY,name VARCHAR(100),email VARCHAR(150),age INT,score INT,created_at DATETIME,payload VARCHAR(255))');
        $insert = $this->pdo->prepare('INSERT INTO plain_users VALUES(?,?,?,?,?,?,?)');
        $expected = [];
        for ($id=1; $id<=100; ++$id) {
            $row = ['id'=>$id,'name'=>'User '.$id,'email'=>'user'.$id.'@example.test','age'=>18+$id%63,'score'=>$id*10,'created_at'=>'2026-01-01 12:00:00','payload'=>str_repeat('x',128)];
            $insert->execute(array_values($row));
            $expected[] = $row;
        }
        self::assertSame($expected, $this->pdo->query('SELECT * FROM plain_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $normal = PlainRegressionUser::Query()->orderBy('id')->toArray();
        $readonly = PlainRegressionUser::Query()->asReadOnly()->orderBy('id')->toArray();
        self::assertCount(100, $normal);
        self::assertCount(100, $readonly);
        self::assertSame($expected, json_decode(json_encode($normal, JSON_THROW_ON_ERROR),true));
        self::assertSame($expected, json_decode(json_encode($readonly, JSON_THROW_ON_ERROR),true));
        foreach ($normal as $i=>$user) {
            self::assertInstanceOf(PlainRegressionUser::class, $user);
            self::assertFalse($user->isReadOnly());
            self::assertFalse($user->isDirty());
            self::assertTrue($readonly[$i]->isReadOnly());
            self::assertSame($i+1, $readonly[$i]->_id());
        }
        $normal[49]->score = 999;
        self::assertSame(1, $normal[49]->save());
        self::assertSame(999, PlainRegressionUser::Get(50)->score);
        self::assertSame(500, $readonly[49]->score);
    }

    public function testRepeatedQueriesReturnFreshInstancesAndData(): void
    {
        $query = RegressionUser::Query()->where(['id'=>1]);
        $first = $query->first();
        $first->name = 'Unsaved';
        $this->pdo->exec("UPDATE User SET name='Changed in database' WHERE id=1");
        $second = $query->first();
        self::assertNotSame($first, $second);
        self::assertSame('Unsaved', $first->name);
        self::assertSame('Changed in database', $second->name);
        self::assertFalse($second->isDirty());
        self::assertTrue($first->isDirty());
    }

    public function testReadOnlyGuardsRunBeforeAnyDatabaseAccess(): void
    {
        $query = RegressionUser::Query()->asReadOnly();
        $user = $query->first();
        $this->pdo->exec('DROP TABLE User');
        foreach ([fn () => $user->save(), fn () => $user->delete(), fn () => $query->update(['age'=>99]), fn () => $query->delete()] as $write) {
            try { $write(); self::fail('Expected read-only rejection.'); }
            catch (\LogicException $error) { self::assertStringContainsString('read-only', $error->getMessage()); }
        }
    }

    public function testEmptyQueriesAndPaginationBoundariesRemainConsistent(): void
    {
        $query = RegressionUser::Query()->asReadOnly()->where(['id'=>999]);
        self::assertNull($query->first());
        self::assertSame([], $query->toArray());
        self::assertSame([], iterator_to_array($query->cursor()));
        self::assertSame(0, $query->count());
        self::assertFalse($query->any());
        $root = RegressionUser::Query()->asReadOnly()->orderBy('id');
        self::assertSame([], $root->limit(0)->toArray());
        self::assertFalse($root->limit(0)->any());
        self::assertSame([], $root->offset(3)->toArray());
        self::assertSame(3, $root->offset(3)->count());
        self::assertSame([2], array_map(fn ($u) => $u->id, $root->skip(1)->take(1)->toArray()));
        self::assertCount(3, $root->toArray());
    }
    public static function invalidCallbacks(): iterable
    {
        foreach (['filter','order'] as $kind) {
            foreach (['return type','different Model','different Database'] as $case) {
                yield $kind.' '.$case => [$kind,$case];
            }
        }
    }

    #[DataProvider('invalidCallbacks')]
    public function testCallbacksRejectIncompatibleQueriesAndInvalidReturnTypes(string $kind, string $case): void
    {
        $foreignDatabase = new Database($this->pdo);
        $callback = match ($case) {
            'return type' => fn ($value, $query) => 123,
            'different Model' => fn ($value, $query) => OtherRegressionUser::Query(),
            'different Database' => fn ($value, $query) => new Query($foreignDatabase, RegressionUser::class),
        };
        if ($kind === 'filter') { RegressionUser::RegisterFilter('incompatible', $callback); }
        else { RegressionUser::RegisterOrder('incompatible', $callback); }
        $this->expectException(\LogicException::class);
        if ($kind === 'filter') { RegressionUser::Query()->filters(['incompatible'=>1])->toArray(); }
        else { RegressionUser::Query()->order('incompatible')->toArray(); }
    }

}
