<?php

declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING);

use PHPUnit\Framework\TestCase;

use Light\Db\Table;
use Laminas\Db\Sql\Ddl\Column\Column;
use Laminas\Db\Sql\Ddl\CreateTable;
use Light\Db\Adapter;

final class AdapterTest extends TestCase
{
    public function testCreate()
    {
        $db = Testing::GetAdapter();
        $this->assertInstanceOf(Adapter::class, $db);
    }

    public function test_table()
    {
        $db = Testing::GetAdapter();
        $this->assertInstanceOf(Table::class, $db->getTable("Testing"));
    }

    public function testTable()
    {
        $db = Testing::GetAdapter();
        $table = $db->getTable("Testing");
        $this->assertInstanceOf(Table::class, $table);

        /*  $table = $db->table("Testing_NOT_EXIST");
        $this->assertNull($table);*/

        if ($db->hasTable("NEW_TABLE")) {
            $db->dropTable("NEW_TABLE");
        }



        $table = $db->createTable("NEW_TABLE", function (CreateTable $createTable) {
            $createTable->addColumn(new Column("testing"));
        });
        $this->assertTrue($db->hasTable("NEW_TABLE"));

        $db->dropTable("NEW_TABLE");
        $this->assertFalse($db->hasTable("NEW_TABLE"));
    }

    /*  public function testPrepare()
    {
        $s = Testing::GetSchema();
        $sth = $s->prepare("select * from User");
        $this->assertInstanceOf(\PDOStatement::class, $sth);
    } */
}
