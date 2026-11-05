<?php

declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\{Database, Model};
use PDO;

class TransactionUser extends Model { protected static $_table = 'User'; }
class TransactionMembership extends Model { protected static $_table = 'memberships'; }
class TransactionAudit extends Model { protected static $_table = 'immediate_audit_events'; }
class TransactionHookUser extends TransactionUser
{
    public static array $events = [];
    public static bool $fail = false;

    protected function afterPersistence(bool $deleted): void
    {
        self::$events[] = [$this->_id(), $deleted, $this->database()->connection()->inTransaction()];
        if (self::$fail) { throw new \RuntimeException('post-commit hook'); }
    }
}

final class TransactionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $key = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INT AUTO_INCREMENT' : 'INTEGER';
        $this->pdo->exec('CREATE TABLE immediate_audit_events (id '.$key.' PRIMARY KEY, user_id INT NOT NULL, action VARCHAR(100) NOT NULL UNIQUE, payload JSON)');
        TransactionHookUser::$events = [];
        TransactionHookUser::$fail = false;
    }

    public function testGeneratedIdCanBeUsedByAuditBeforeCommit(): void
    {
        $id = $this->db->transaction(function (): int {
            $user = TransactionUser::Create(['name' => 'Dora']);
            self::assertSame(1, $user->save());
            self::assertNotNull($user->_id());
            self::assertSame(18, $user->age);
            self::assertSame('Dora!', $user->label);
            self::assertTrue($user->exists());
            self::assertFalse($user->isDirty());
            self::assertSame('Dora', TransactionUser::Get($user->_id())->name);

            // This Model/table is first discovered after the transaction has started.
            $audit = TransactionAudit::Create([
                'user_id' => $user->_id(), 'action' => 'create', 'payload' => $user->toArray(),
            ]);
            self::assertSame(1, $audit->save());
            self::assertSame((int) $user->_id(), (int) $audit->user_id);
            self::assertSame('Dora', $audit->payload['name']);
            self::assertTrue($this->pdo->inTransaction());
            return (int) $user->_id();
        });

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('Dora', TransactionUser::Get($id)->name);
        self::assertSame($id, (int) TransactionAudit::Query()->first()->user_id);
    }

    public function testOtherConnectionCannotSeeInsertUntilCommit(): void
    {
        $path = null;
        $database = $this->db;
        if ($this->schema !== null) {
            $observer = new PDO(getenv('LIGHT_DB_TEST_DSN'), getenv('LIGHT_DB_TEST_USER') ?: 'root', getenv('LIGHT_DB_TEST_PASSWORD') ?: '');
            $observer->exec('USE `'.$this->schema.'`');
        } else {
            // The usual SQLite fixture is in-memory; use a file for two connections.
            $path = tempnam(sys_get_temp_dir(), 'light-db-transaction-');
            $connection = new PDO('sqlite:'.$path);
            $connection->exec('CREATE TABLE User (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
            $database = new Database($connection);
            Database::setDefault($database);
            $observer = new PDO('sqlite:'.$path);
        }
        try {
            $count = (int) $observer->query('SELECT COUNT(*) FROM User')->fetchColumn();
            $database->transaction(function () use ($observer, $count): void {
                TransactionUser::Create(['name' => 'Dora'])->save();
                self::assertSame($count, (int) $observer->query('SELECT COUNT(*) FROM User')->fetchColumn());
            });
            self::assertSame($count + 1, (int) $observer->query('SELECT COUNT(*) FROM User')->fetchColumn());
        } finally {
            Database::setDefault($this->db);
            $observer = null;
            $database = null;
            $connection = null;
            if ($path !== null) { unlink($path); }
        }
    }

    public function testAuditFailureRollsBackBusinessInsertAndEarlierAudit(): void
    {
        $new = TransactionUser::Create(['name' => 'Dora']);
        try {
            $this->db->transaction(function () use ($new): void {
                $new->save();
                TransactionAudit::Create(['user_id' => $new->_id(), 'action' => 'create'])->save();
                TransactionAudit::Create(['user_id' => $new->_id(), 'action' => 'create'])->save();
            });
            self::fail('Expected duplicate audit action.');
        } catch (\PDOException) {}

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(3, TransactionUser::Query()->count());
        self::assertSame(0, TransactionAudit::Query()->count());
        self::assertNull(TransactionUser::Get($new->_id()));
        // Like light-entity, rollback restores SQL, not already accepted PHP state.
        self::assertTrue($new->exists());
        self::assertNotNull($new->_id());
        self::assertSame('ready', $this->db->transaction(fn () => 'ready'));
        TransactionUser::Create(['name' => 'After failure'])->save();
        self::assertSame(4, TransactionUser::Query()->count());
    }

    public function testCallbackExceptionRollsBackUpdatesDeletesBulkAndRawSql(): void
    {
        $user = TransactionUser::Get(1); // A Model loaded before the transaction participates too.
        try {
            $this->db->transaction(function () use ($user): void {
                $user->name = 'Mary';
                $user->save();
                self::assertSame(1, TransactionUser::Get(2)->delete());
                self::assertSame(1, TransactionUser::Query(['id' => 3])->update(['age' => 99]));
                $this->pdo->prepare('INSERT INTO memberships(a,b,name) VALUES(?,?,?)')->execute([1, 2, 'raw']);
                self::assertSame('Mary', TransactionUser::Get(1)->name);
                self::assertNull(TransactionUser::Get(2));
                throw new \RuntimeException('abort');
            });
            self::fail('Expected callback failure.');
        } catch (\RuntimeException $error) { self::assertSame('abort', $error->getMessage()); }

        self::assertSame('Ada', TransactionUser::Get(1)->name);
        self::assertSame('Bob', TransactionUser::Get(2)->name);
        self::assertSame(10, TransactionUser::Get(3)->age);
        self::assertSame(0, TransactionMembership::Query()->count());
    }

    public function testEachSaveIsImmediateWithoutFlushingOtherModels(): void
    {
        $first = TransactionUser::Get(1);
        $second = TransactionUser::Get(2);
        $this->db->transaction(function () use ($first, $second): void {
            $first->name = 'Unsaved';
            $second->name = 'Second';
            $second->save();
            self::assertSame('Ada', TransactionUser::Get(1)->name);
            $second->name = 'Changed again';
            $second->save();
            self::assertSame('Changed again', TransactionUser::Get(2)->name);
            self::assertSame(0, $second->save());
        });
        self::assertTrue($first->isDirty());
        self::assertSame('Ada', TransactionUser::Get(1)->name);
        self::assertSame('Changed again', TransactionUser::Get(2)->name);
    }

    public function testCaughtSaveFailureDoesNotAbortOuterTransaction(): void
    {
        $this->db->transaction(function (): void {
            TransactionUser::Create(['name' => 'Dora'])->save();
            $duplicate = TransactionUser::Create(['name' => 'Bob']);
            try {
                $duplicate->save();
                self::fail('Expected duplicate name.');
            } catch (\PDOException) {}
            self::assertTrue($this->pdo->inTransaction());
            self::assertFalse($duplicate->exists());
            self::assertNull($duplicate->_id());
            $duplicate->name = 'Retry';
            $duplicate->save();
        });
        self::assertSame(5, TransactionUser::Query()->count());
        self::assertNotNull(TransactionUser::Query(['name' => 'Retry'])->first());
    }

    public function testCompositeKeyAndSaveThenDeleteAreImmediate(): void
    {
        $this->db->transaction(function (): void {
            $member = TransactionMembership::Create(['a' => 1, 'b' => 2, 'name' => 'member']);
            self::assertSame(1, $member->save());
            self::assertSame(['a' => 1, 'b' => 2], $member->_id());
            $member->name = 'updated';
            $member->save();
            self::assertSame('updated', TransactionMembership::Get(['a' => 1, 'b' => 2])->name);
            self::assertSame(1, $member->delete());
            self::assertFalse($member->exists());
            self::assertNull(TransactionMembership::Get(['a' => 1, 'b' => 2]));
        });
        self::assertSame(0, TransactionMembership::Query()->count());
    }

    public function testNestedTransactionsAreRejectedBeforeCallback(): void
    {
        $called = false;
        try {
            $this->db->transaction(function () use (&$called): void {
                $this->db->transaction(function () use (&$called): void { $called = true; });
            });
            self::fail('Expected nested transaction rejection.');
        } catch (\LogicException) {}
        self::assertFalse($called);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(42, $this->db->transaction(fn () => 42));
    }

    public function testExternalTransactionIsRejectedWithoutRollingItBack(): void
    {
        $called = false;
        $this->pdo->beginTransaction();
        try {
            try {
                $this->db->transaction(function () use (&$called): void { $called = true; });
                self::fail('Expected external transaction rejection.');
            } catch (\LogicException) {}
            self::assertFalse($called);
            self::assertTrue($this->pdo->inTransaction());
        } finally { $this->pdo->rollBack(); }
    }

    public function testHooksRunAfterCommitAndAreDiscardedOnRollback(): void
    {
        $this->db->transaction(function (): void {
            $user = TransactionHookUser::Get(1);
            $user->name = 'Mary';
            $user->save();
            $user->delete();
            self::assertSame([], TransactionHookUser::$events);
        });
        self::assertSame([[1, false, false], [1, true, false]], TransactionHookUser::$events);

        TransactionHookUser::$events = [];
        try {
            $this->db->transaction(function (): void {
                TransactionHookUser::Create(['name' => 'Discarded'])->save();
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException $error) { self::assertSame('abort', $error->getMessage()); }
        self::assertSame([], TransactionHookUser::$events);
    }

    public function testHookFailureAfterCommitDoesNotUndoWritesOrLeaveTransactionActive(): void
    {
        TransactionHookUser::$fail = true;
        try {
            $this->db->transaction(function (): void {
                TransactionHookUser::Create(['name' => 'Dora'])->save();
                TransactionAudit::Create(['user_id' => 1, 'action' => 'committed'])->save();
            });
            self::fail('Expected hook failure.');
        } catch (\RuntimeException $error) { self::assertSame('post-commit hook', $error->getMessage()); }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(4, TransactionUser::Query()->count());
        self::assertSame(1, TransactionAudit::Query()->count());
        self::assertSame('ready', $this->db->transaction(fn () => 'ready'));
    }
}
