<?php

declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

use Laminas\Db\Metadata\Object\ColumnObject;
use Laminas\Db\Sql\Ddl\Column as ColumnColumn;
use Light\Db\Table;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    public function getTable()
    {
        return Testing::_table();
    }

    public function testCreate()
    {
        $db = Testing::GetAdapter();
        $table = $db->getTable("Testing");
        $this->assertInstanceOf(Table::class, $table);
    }


    public function testColumn()
    {
        $db = Testing::GetAdapter();
        $table = $db->getTable("Testing");
        $testing_id_column = $table->column("testing_id");

        $this->assertInstanceOf(ColumnObject::class, $testing_id_column);

        $col_not_exist = $table->column("testing_id_not_exist");
        $this->assertNull($col_not_exist);
    }

    public function testAddDropColumn()
    {
        $db = Testing::GetAdapter();
        $table = $db->getTable("Testing");

        if ($table->column("new_column")) {
            $table->dropColumn("new_column");
        }


        $table->addColumn(new ColumnColumn\Integer("new_column"));
        $new_column = $table->column("new_column");

        $table->dropColumn("new_column");
        $new_column = $table->column("new_column");
        $this->assertNull($new_column);
    }

    public function testInsert()
    {
        $table = $this->getTable();
        $table->truncate();
        $this->assertEquals($table->count(), 0);

        $table = $this->getTable();
        $table->insert(["name" => 'test1']);

        $table = $this->getTable();
        $this->assertEquals($table->count(), 1);

        $table = $this->getTable();
        $table->delete(["name" => "test1"]);
        $this->assertEquals($table->count(), 0);
    }


    public function testFirst()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => 'test1']);
        $result = $table->first("name='test1'");

        $this->assertArrayHasKey('name', $result);
    }

    public function testTop()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => 'test1']);
        $table->insert(["name" => 'test2']);
        $table->insert(["name" => 'test3']);

        $result = $table->top(2);

        $this->assertEquals(count($result), 2);
    }

    public function testMax()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => '1']);
        $table->insert(["name" => '2']);
        $table->insert(["name" => '3']);

        $this->assertEquals($table->max('name'), '3');
    }

    public function testMin()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => '1']);
        $table->insert(["name" => '2']);
        $table->insert(["name" => '3']);

        $this->assertEquals($table->min('name'), '1');
    }


    public function testAvg()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => '1']);
        $table->insert(["name" => '2']);
        $table->insert(["name" => '3']);

        $this->assertEquals(intval($table->avg('name')), 2);
    }


    public function testWhereCondition()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => 'a']);
        $table->insert(["name" => 'b']);
        $result = $table->select("name='b'");

        $result = iterator_to_array($result);
        $this->assertCount(1, $result);
        $this->assertEquals($result[0]['name'], 'b');
    }

    public function testCountWithCondition()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => 'x']);
        $table->insert(["name" => 'y']);
        $count = $table->count("name='x'");
        $this->assertEquals($count, 1);
    }

    public function testInsertMultipleRows()
    {
        $table = $this->getTable();
        $table->truncate();

        $table->insert(["name" => 'a']);
        $table->insert(["name" => 'b']);
        $table->insert(["name" => 'c']);
        $this->assertEquals($table->count(), 3);
    }

    public function testDeleteNonExistentRow()
    {
        $table = $this->getTable();
        $table->truncate();

        $affected = $table->delete(["name" => "not_exist"]);
        $this->assertEquals($affected, 0);
    }
}
