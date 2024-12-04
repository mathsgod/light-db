<?php

namespace Light\DB;

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\RowGateway\RowGateway;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Ddl\Column\ColumnInterface;
use Laminas\Db\TableGateway\Feature\MetadataFeature;
use Laminas\Db\TableGateway\Feature\RowGatewayFeature;

class Table
{

    public $name;
    public $adapter;
    public $columns;
    public $rows;
    public $constraints;

    public function __construct(Adapter $adapter,  string $name)
    {

        $this->name = $name;
        $this->adapter = $adapter;

        $this->columns = new LazyCollection(function () {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            foreach ($meta->getColumns($this->name) as $column) {
                yield new Column($column);
            }
        });


        $this->rows = new LazyCollection(function () {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            //  new Table
            $table = new TableGateway($this->name, $this->adapter, [
                new MetadataFeature($meta),
                new RowGatewayFeature()
            ]);


            foreach ($table->select() as $row) {

                /**
                 * @var RowGateway $row
                 */
                yield $row;
            }
        });

        $this->constraints = new LazyCollection(function () {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            foreach ($meta->getConstraints($this->name) as $constraint) {
                yield $constraint;
            }
        });
    }

    public function removeRow(array $where)
    {
        $table = new TableGateway($this->name, $this->adapter);
        return $table->delete($where);
    }

    public function addRow(array $data)
    {
        $table = new TableGateway($this->name, $this->adapter);
        $table->insert($data);

        return $table->getLastInsertValue();
    }

    public function column(string $name)
    {
        return $this->columns->first(fn($column) => $column->name === $name);
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

    public function getPrimaryKey()
    {
        return $this->constraints->first(fn($constraint) => $constraint->getType() === 'PRIMARY KEY')->getColumns();
    }

    public function rows($where)
    {
        return new LazyCollection(function () use ($where) {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            //  new Table
            $table = new TableGateway($this->name, $this->adapter, [
                new MetadataFeature($meta),
                new RowGatewayFeature()
            ]);


            foreach ($table->select($where) as $row) {

                /**
                 * @var RowGateway $row
                 */
                yield $row;
            }
        });
    }

    /**
     * @return ?RowGateway
     */
    public function row($where)
    {
        $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);


        //  new Table
        $table = new TableGateway($this->name, $this->adapter, [
            new MetadataFeature($meta),
            new RowGatewayFeature()
        ]);

        /**
         * @var \Laminas\Db\ResultSet\ResultSet $results
         */
        $results = $table->select($where);

        /**
         * @var RowGateway
         */
        return $results->current();
    }
}
