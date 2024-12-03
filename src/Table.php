<?php

namespace Light\DB;

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Ddl\Column\ColumnInterface;

class Table
{

    public $name;
    private $adapter;
    public $columns;
    public $rows;

    public function __construct(Adapter $adapter, string $name)
    {
        $this->name = $name;
        $this->adapter = $adapter;

        $this->columns = new LazyCollection(function () {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            foreach ($meta->getColumns($this->name) as $column) {
                yield new Column($this->adapter, $column);
            }
        });


        $this->rows = new LazyCollection(function () {

            //  new Table
            $table = new TableGateway($this->name, $this->adapter);
            $result = $table->select();

            foreach ($result as $row) {
                yield new Row($row, $this, $this->columns);
            }
        });
    }

    public function addRow(array $data)
    {
        $table = new TableGateway($this->name, $this->adapter);
        $table->insert($data);

        return $table->getLastInsertValue();
    }

    public function removeColumn(string $name)
    {
        $table = new Ddl\AlterTable();
        $table->dropColumn($name);

        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        return $this->adapter->query(
            $sql->buildSqlString($table),
            Adapter::QUERY_MODE_EXECUTE
        );
    }

    public function addColumn(ColumnInterface $column)
    {
        $table = new Ddl\AlterTable($this->name);
        $table->addColumn($column);

        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        return $this->adapter->query(
            $sql->buildSqlString($table),
            Adapter::QUERY_MODE_EXECUTE
        );
    }
}
