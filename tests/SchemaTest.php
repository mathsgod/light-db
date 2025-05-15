<?php

declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING);

use PHPUnit\Framework\TestCase;

use Light\Db\Schema;
use Light\Db\Table;
use R\DB\Query;
use Exception;
use Laminas\Db\Sql\Ddl\Column\Column;
use Laminas\Db\Sql\Ddl\CreateTable;

final class SchemaTest extends TestCase
{
    public function testCreate()
    {
        $db = Testing::GetSchema();
        $this->assertInstanceOf(Schema::class, $db);
    }

    public function test_table()
    {
        $db = Testing::GetSchema();
        $this->assertInstanceOf(Table::class, $db->table("Testing"));
    }

    public function testTable()
    {
        $db = Testing::GetSchema();
        $table = $db->table("Testing");
        $this->assertInstanceOf(Table::class, $table);

        /*  $table = $db->table("Testing_NOT_EXIST");
        $this->assertNull($table);*/

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
