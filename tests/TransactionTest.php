<?php
declare(strict_types=1);
namespace Light\Db\Tests;
use Light\Db\Model;
class TransactionUser extends Model { protected static $_table = 'User'; }
class TransactionMembership extends Model { protected static $_table = 'memberships'; }
class FailingHookUser extends TransactionUser
{
    protected function afterPersistence(bool $deleted): void
    {
        throw new \RuntimeException('post-commit hook');
    }
}
final class TransactionTest extends DatabaseTestCase
{
    public function testDeferredMixedWritesAndCallbackResult(): void
    {
        $user = TransactionUser::Get(1);
        $deleted = TransactionUser::Get(2);
        $new = TransactionUser::Create(['name'=>'Dora']);
        $membership = TransactionMembership::Create(['a'=>1,'b'=>2,'name'=>'member']);
        $result = $this->db->transaction(function () use ($user, $deleted, $new, $membership) {
            $user->name = 'Mary';
            self::assertSame(0, $user->save());
            $user->save();
            $deleted->delete();
            $new->save();
            $membership->save();
            self::assertSame('Ada', TransactionUser::Get(1)->name);
            self::assertNull($new->_id());
            return 'done';
        });
        self::assertSame('done', $result);
        self::assertSame('Mary', TransactionUser::Get(1)->name);
        self::assertNull(TransactionUser::Get(2));
        self::assertTrue($new->exists());
        self::assertNotNull($new->_id());
        self::assertFalse($user->isDirty());
        self::assertFalse($deleted->exists());
        self::assertSame('member', TransactionMembership::Get(['a'=>1,'b'=>2])->name);
    }
    public function testCallbackExceptionDiscardsWritesAndAllowsRetry(): void
    {
        $user = TransactionUser::Get(1);
        try {
            $this->db->transaction(function () use ($user) {
                $user->name='Mary'; $user->save();
                throw new \RuntimeException('abort');
            });
            self::fail('Expected exception');
        } catch (\RuntimeException $e) { self::assertSame('abort', $e->getMessage()); }
        self::assertSame('Ada', TransactionUser::Get(1)->name);
        self::assertTrue($user->isDirty());
        $user->save();
        self::assertSame('Mary', TransactionUser::Get(1)->name);
    }
    public function testFailedCommitRollsBackAllModelsAndCanRetry(): void
    {
        $user=TransactionUser::Get(1);
        $new=TransactionUser::Create(['name'=>'Bob']);
        $write=function () use ($user,$new) { $user->name='Mary'; $user->save(); $new->save(); };
        try { $this->db->transaction($write); self::fail('Expected constraint failure'); }
        catch (\PDOException) {}
        self::assertSame('Ada',TransactionUser::Get(1)->name);
        self::assertTrue($user->isDirty());
        self::assertFalse($new->exists());
        self::assertNull($new->_id());
        $new->name='Dora';
        $this->db->transaction($write);
        self::assertSame('Mary',TransactionUser::Get(1)->name);
        self::assertTrue($new->exists());
    }
    public function testNestedTransactionsAreRejectedAndEmptyCallbackWorks(): void
    {
        self::assertSame(42,$this->db->transaction(fn()=>42));
        $this->expectException(\LogicException::class);
        $this->db->transaction(fn()=>$this->db->transaction(fn()=>null));
    }
    public function testBulkWritesCannotEscapeRollbackBoundary(): void
    {
        try {
            $this->db->transaction(fn()=>TransactionUser::Query()->update(['age'=>99]));
            self::fail('Expected rejection');
        } catch (\LogicException) {}
        self::assertSame(30,TransactionUser::Get(1)->age);
    }
    public function testExternalTransactionIsRejectedBeforeCallback(): void
    {
        $called=false;
        $this->pdo->beginTransaction();
        try {
            try {
                $this->db->transaction(function () use (&$called) { $called=true; });
                self::fail('Expected rejection');
            } catch (\LogicException) {}
            self::assertFalse($called);
            self::assertTrue($this->pdo->inTransaction());
        } finally { $this->pdo->rollBack(); }
    }
    public function testNewModelSaveThenDeleteCancelsInsert(): void
    {
        $new=TransactionUser::Create(['name'=>'Dora']);
        $this->db->transaction(function () use ($new) { $new->save(); $new->delete(); });
        self::assertSame(3,TransactionUser::Query()->count());
        self::assertFalse($new->exists());
    }
    public function testPostCommitHookFailureKeepsEveryModelStateCommitted(): void
    {
        $first = FailingHookUser::Create(['name'=>'First']);
        $second = TransactionUser::Create(['name'=>'Second']);
        try {
            $this->db->transaction(function () use ($first,$second) { $first->save(); $second->save(); });
            self::fail('Expected hook failure');
        } catch (\RuntimeException $e) { self::assertSame('post-commit hook',$e->getMessage()); }
        self::assertTrue($first->exists());
        self::assertTrue($second->exists());
        self::assertFalse($first->isDirty());
        self::assertFalse($second->isDirty());
        self::assertSame(5,TransactionUser::Query()->count());
        self::assertSame('ready',$this->db->transaction(fn()=>'ready'));
    }
}
