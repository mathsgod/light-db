<?php

declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\{Database, Model, Query};
use Light\Entity\Migrations\{MigrationBuilder, TableBuilder};
use Light\Linq\Expr;
use PDO;
use PHPUnit\Framework\TestCase;

class User extends Model {}
class Membership extends Model { protected static $_table = 'memberships'; }
class AliasUser extends Model { protected static $_table = 'User'; }
class Collision extends Model {}
class DefinedUser extends Model
{
    protected static $_table = 'User';
    protected static function filterDefinitions(): array { return ['adult'=>fn ($v) => ['age'=>['gte'=>$v]]]; }
}

#[\Light\Entity\Table('User')]
class TypedUser extends Model
{
    public int $id;
    public string $name;
    public ?array $settings;
}

class ConvertedUser extends Model
{
    protected static $_table = 'User';
    public static function valueConverters(): array
    {
        return ['name'=>new \Light\Entity\ValueConverter(fn ($v)=>strtolower($v),fn ($v)=>strtoupper($v))];
    }
}

final class CoreTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private ?string $schema = null;

    protected function setUp(): void
    {
        $dsn = getenv('LIGHT_DB_TEST_DSN');
        if ($dsn) {
            $this->pdo = new PDO($dsn, getenv('LIGHT_DB_TEST_USER') ?: 'root', getenv('LIGHT_DB_TEST_PASSWORD') ?: '', [PDO::ATTR_EMULATE_PREPARES=>false]);
            $this->schema = 'light_db_v2_'.bin2hex(random_bytes(8));
            $this->pdo->exec('CREATE DATABASE `'.$this->schema.'` CHARACTER SET utf8mb4');
            $this->pdo->exec('USE `'.$this->schema.'`');
        } else {
            if (!in_array('sqlite', PDO::getAvailableDrivers(),true)) { self::markTestSkipped('pdo_sqlite or LIGHT_DB_TEST_DSN required.'); }
            $this->pdo = new PDO('sqlite::memory:');
        }
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE User (id '.($mysql?'INT AUTO_INCREMENT':'INTEGER').' PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, age INT DEFAULT 18, settings '.'JSON'.", label VARCHAR(110) GENERATED ALWAYS AS (".($mysql?"CONCAT(name,'!')":"name || '!'").') VIRTUAL)');
        $this->pdo->exec('CREATE TABLE memberships(a INT,b INT,name VARCHAR(100),PRIMARY KEY(a,b))');
        $this->pdo->exec('CREATE TABLE Collision(id INT PRIMARY KEY, original VARCHAR(100), attributes VARCHAR(100), owner VARCHAR(100))');
        $insert = $this->pdo->prepare('INSERT INTO User(name,age,settings) VALUES(?,?,?)');
        $insert->execute(['Ada',30,'{"theme":"light","nested":{"tags":["a"]}}']);
        $insert->execute(['Bob',20,null]);
        $insert->execute(['Cara',10,null]);
        $this->db = new Database($this->pdo);
        Database::setDefault($this->db);
    }
    protected function tearDown(): void
    {
        Database::setDefault(null);
        if ($this->schema !== null) { $this->pdo->exec('DROP DATABASE `'.$this->schema.'`'); }
        parent::tearDown();
    }

    public function testClassConventionGetCreateSaveDeleteAndDefaults(): void
    {
        self::assertSame('User',$this->db->metadata(User::class)->table);
        self::assertSame($this->db->metadata(User::class),$this->db->metadata(User::class));
        $user = User::Get(1);
        self::assertInstanceOf(User::class,$user);
        self::assertSame('Ada',$user->name);
        self::assertSame('Ada!',$user->label);
        self::assertFalse($user->isDirty());
        self::assertSame(0,$user->save());
        $new = User::Create(['name'=>'New']);
        self::assertFalse($new->exists());
        self::assertSame(1,$new->save());
        self::assertTrue($new->exists());
        self::assertSame(18,$new->age);
        self::assertSame('New!',$new->label);
        self::assertSame('id',User::_key());
        self::assertSame(4,$new->_id());
        self::assertSame(1,$new->delete());
        self::assertSame(0,$new->delete());
        self::assertNull(User::Get(4));
        self::assertNull(User::Get(null));
    }

    public function testSaveOnlyWritesOneModelAndFreshReadsIgnoreUnsavedChanges(): void
    {
        $a = User::Get(1); $b = User::Get(2);
        $a->name='Changed'; $b->name='Unsaved';
        self::assertTrue($a->isDirty('name'));
        self::assertSame('Ada',User::Get(1)->name);
        self::assertSame(1,$a->save());
        self::assertSame('Changed',User::Get(1)->name);
        self::assertSame('Bob',User::Get(2)->name);
        self::assertFalse($a->isDirty());
        self::assertTrue($b->isDirty());
    }

    public function testJsonReferenceMutationAndIndependentSnapshots(): void
    {
        $user=User::Get(1);
        self::assertSame('light',$user->settings['theme']);
        $user->settings['nested']['tags'][]='b';
        self::assertTrue($user->isDirty('settings'));
        self::assertSame(1,$user->save());
        self::assertSame(['a','b'],User::Get(1)->settings['nested']['tags']);
        self::assertSame(0,$user->save());
        self::assertSame('Ada',json_decode(json_encode($user,JSON_THROW_ON_ERROR),true)['name']);
        self::assertArrayNotHasKey('owner',$user->toArray());
    }

    public function testQueryIsImmutableAndCountIgnoresPagination(): void
    {
        $root=User::Query();
        $adults=$root->where(['age'=>['gte'=>20]])->sort('age:desc')->limit(1)->offset(1);
        self::assertSame(3,$root->count());
        self::assertSame(2,$adults->count());
        self::assertSame('Bob',$adults->first()->name);
        self::assertCount(1,$adults->toArray());
        self::assertNull(User::Query()->limit(0)->first());
        self::assertSame([1,2,3],array_map(fn ($u)=>$u->id,$root->order('id ASC')->toArray()));
        self::assertEquals(60,$root->sum('age'));
        self::assertEquals(20,$root->avg('age'));
        self::assertSame(10,$root->min('age'));
        self::assertSame(30,$root->max('age'));
        self::assertTrue($root->any());
        self::assertSame(User::class,$root->getClassName());
    }

    public function testFiltersRegisteredCallbacksAndRawSql(): void
    {
        User::RegisterFilter('older',fn ($value)=>Expr::gte(Expr::field('age'),$value));
        User::RegisterFilter('keyword',fn ($value,Query $q)=>$q->whereRaw('name LIKE ?',[$value.'%']));
        User::RegisterOrder('byAge',fn ($direction)=>Expr::field('age'));
        self::assertSame('Ada',User::Query()->filters(['older'=>25,'keyword'=>'A'])->first()->name);
        self::assertSame('Cara',User::Query()->sort('byAge:asc')->first()->name);
        self::assertSame(2,DefinedUser::Query()->filters(['adult'=>20])->count());
        self::assertSame(1,User::Query()->filters(['name'=>['contains'=>'d']])->count());
        self::assertSame(2,User::Query()->where(['_or'=>[['age'=>['lt'=>15]],['name'=>'Ada']]])->count());
        self::assertSame(2,User::Query()->where(['id'=>[1,3]])->count());
        self::assertSame(1,User::Query()->where(['settings'=>null,'age'=>['between'=>[15,25]]])->count());
        self::assertSame(0,User::Query()->whereRaw('name = ?',["x' OR 1=1 --"])->count());
        self::assertSame('Ada',AliasUser::Get(1)->name);
    }

    public function testBulkWritesAndBufferedIterationAllowSavingInsideForeach(): void
    {
        foreach (User::Query() as $user) { $user->age += 1; $user->save(); }
        self::assertEquals(63,User::Query()->sum('age'));
        self::assertSame(2,User::Query()->where(['age'=>['gt'=>15]])->update(['age'=>50]));
        self::assertSame(2,User::Query()->where(['age'=>50])->delete());
        self::assertSame(1,User::Query()->count());
        self::assertSame(['Cara'],User::Query()->asEnumerable()->select(fn ($u)=>$u->name)->toArray());
    }

    public function testCompositeKeysAndInternalNamesDoNotCollide(): void
    {
        $row=Membership::Create(['a'=>1,'b'=>2,'name'=>'Member']);
        self::assertSame(1,$row->save());
        self::assertSame(['a'=>1,'b'=>2],$row->_id());
        self::assertSame('Member',Membership::Get(['a'=>1,'b'=>2])->name);
        $model=Collision::Create(['id'=>1,'original'=>'Original','attributes'=>'Attributes','owner'=>'Owner']);
        self::assertSame(1,$model->save());
        self::assertSame('Owner',Collision::Get(1)->owner);
        $model->original='Edited'; self::assertSame(1,$model->save());
        self::assertSame('Edited',Collision::Get(1)->original);
    }

    public function testFailedSaveCanRetryWithoutLosingChanges(): void
    {
        $user=User::Get(1); $user->name='Bob';
        try { $user->save(); self::fail('Expected duplicate name.'); }
        catch (\PDOException) { self::assertTrue($user->isDirty()); }
        $user->name='Retry'; self::assertSame(1,$user->save());
        self::assertSame('Retry',User::Get(1)->name);
    }

    public function testMetadataInvalidation(): void
    {
        $before=$this->db->metadata(User::class);
        $m=new MigrationBuilder(); $m->addColumn('User',fn (TableBuilder $t)=>$t->string('nickname')->nullable());
        (new \Light\Entity\DbContext($this->pdo))->database->executeMigration($m);
        $this->db->clearMetadata();
        self::assertNotSame($before,$this->db->metadata(User::class));
        self::assertArrayHasKey('nickname',User::Get(1)->toArray());
    }

    public function testTypedPublicPropertiesAndDirectConstruction(): void
    {
        $model=new TypedUser(['name'=>'Typed','settings'=>['theme'=>'dark']]);
        self::assertTrue($model->isDirty());
        self::assertSame(1,$model->save());
        self::assertSame(4,$model->id);
        self::assertSame('Typed',TypedUser::Get(4)->name);
        $model->settings['theme']='light';
        self::assertTrue($model->isDirty('settings'));
        self::assertSame(1,$model->save());
        self::assertFalse($model->isDirty());
    }

    public function testLoadedModelsStayBoundToTheirOriginalDatabase(): void
    {
        $model=User::Get(1);
        if ($this->schema !== null) {
            $other=new PDO(getenv('LIGHT_DB_TEST_DSN'),getenv('LIGHT_DB_TEST_USER') ?: 'root',getenv('LIGHT_DB_TEST_PASSWORD') ?: '');
            $other->exec('CREATE DATABASE `'.$this->schema.'_other`');
            $other->exec('USE `'.$this->schema.'_other`');
        } else { $other=new PDO('sqlite::memory:'); }
        try {
            $other->exec('CREATE TABLE User(id INT PRIMARY KEY,name VARCHAR(100))');
            $other->exec("INSERT INTO User VALUES(1,'Other database')");
            Database::setDefault(new Database($other));
            $model->name='Original database';
            self::assertSame(1,$model->save());
            self::assertSame('Original database',$this->pdo->query('SELECT name FROM User WHERE id=1')->fetchColumn());
            self::assertSame('Other database',User::Get(1)->name);
        } finally {
            if ($this->schema !== null) { $other->exec('DROP DATABASE `'.$this->schema.'_other`'); }
            Database::setDefault($this->db);
        }
    }

    public function testWriteGuardsAndInvalidFilters(): void
    {
        $operations=[
            fn () => User::Query()->limit(1)->delete(),
            fn () => User::Query()->offset(1)->update(['age'=>0]),
            fn () => User::Query()->limit(-1),
            fn () => User::Query()->sort('name; DROP TABLE User'),
            fn () => User::Query()->where(['unknown'=>1]),
            fn () => User::Query()->where(['age'=>['unexpected'=>1]]),
            fn () => User::Get([]),
            fn () => User::Create(['unknown'=>1]),
        ];
        foreach ($operations as $operation) {
            try { $operation(); self::fail('Expected invalid input rejection.'); }
            catch (\LogicException $error) { self::assertNotSame('', $error->getMessage()); }
        }
        self::assertSame(3,User::Query()->count());
        $user=User::Get(1); $user->label='Cannot write computed column';
        $this->expectException(\LogicException::class); $user->save();
    }

    public function testCursorAndDeletionState(): void
    {
        $ids=[];
        foreach (User::Query()->order('id')->cursor() as $user) { $ids[]=$user->id; }
        self::assertSame([1,2,3],$ids);
        $user=User::Get(1); self::assertSame(1,$user->delete());
        self::assertFalse($user->exists());
        $this->expectException(\LogicException::class); $user->save();
    }

    public function testCustomConvertersApplyToReadWriteAndConditions(): void
    {
        $user=ConvertedUser::Create(['name'=>'DELTA']);
        self::assertSame(1,$user->save());
        self::assertSame('DELTA',$user->name);
        self::assertSame('delta',$this->pdo->query('SELECT name FROM User WHERE id=4')->fetchColumn());
        self::assertSame(4,ConvertedUser::Get(['name'=>'DELTA'])->id);
        self::assertSame(1,ConvertedUser::Query(['name'=>'DELTA'])->update(['name'=>'EPSILON']));
        self::assertSame('EPSILON',ConvertedUser::Get(4)->name);
    }

    public function testExternalTransactionsAreRejectedWithoutPartialWrites(): void
    {
        $user=User::Get(1); $user->name='Pending';
        $this->pdo->beginTransaction();
        try {
            try { $user->save(); self::fail('Expected unsupported outer transaction.'); }
            catch (\LogicException) { self::assertTrue($this->pdo->inTransaction()); }
            self::assertSame('Ada',$this->pdo->query('SELECT name FROM User WHERE id=1')->fetchColumn());
        } finally { $this->pdo->rollBack(); }
        self::assertTrue($user->isDirty());
        self::assertSame(1,$user->save());
    }

    public function testKeyChangesAreRejected(): void
    {
        $user=User::Get(1); $user->id=99;
        $this->expectException(\LogicException::class); $user->save();
    }
}
