<?php

namespace Light\Db;

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\RowGateway\RowGateway;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Ddl\AlterTable;
use Laminas\Db\Sql\Ddl\Column\Column;
use Laminas\Db\Sql\Ddl\Column\ColumnInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\TableGateway\Feature\MetadataFeature;
use Laminas\Db\TableGateway\Feature\RowGatewayFeature;
use Laminas\Db\Sql\Predicate;

class Table extends TableGateway
{
    protected $_columns = null;
    protected $_constraints = null;

    public function getConstraints()
    {
        if ($this->_constraints) return $this->_constraints;
        $this->_constraints = new LazyCollection(function () {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            foreach ($meta->getConstraints($this->table) as $constraint) {
                yield $constraint;
            }
        });
        return $this->_constraints;
    }

    public function removeRow(array $where)
    {
        $this->delete($where);
    }

    public function addRow(array $data)
    {
        $this->insert($data);
        return $this->getLastInsertValue();
    }

    public function columns()
    {
        if ($this->_columns) return $this->_columns;
        $this->_columns = new LazyCollection(function () {
            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);
            foreach ($meta->getColumns($this->table) as $column) {
                yield $column;
            }
        });
        return $this->_columns;
    }

    public function column(string $name)
    {
        return $this->columns()->first(fn($column) => $column->getName() === $name);
    }

    public function removeColumn(string $table)
    {
        $this->_columns = null;
        $this->_constraints = null;
        $table = new Ddl\AlterTable();
        $table->dropColumn($table);

        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        return $this->execute($sql->buildSqlString($table));
    }

    public function addColumn(ColumnInterface $column)
    {
        $this->_columns = null;
        $this->_constraints = null;
        $table = new Ddl\AlterTable($this->table);
        $table->addColumn($column);

        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        $this->execute($sql->buildSqlString($table));
    }

    public function count($where = null): int
    {
        $select = new Select($this->table);
        if (isset($where)) {
            $select->where($where);
        }
        $select->columns([
            "c" => new Expression("count(*)")
        ]);
        $select->limit(1);

        return iterator_to_array($this->selectWith($select))[0]["c"] ?? 0;
    }
    public function min(string $column)
    {
        $select = new Select($this->table);
        $select->columns([
            "c" => new Expression("min(`$column`)")
        ]);

        return iterator_to_array($this->selectWith($select))[0]["c"] ?? null;
    }

    /**
     * @param Where|\Closure|string|array $where
     */
    public function first($where = null, $combination = Predicate\PredicateSet::OP_AND)
    {
        $select = new Select($this->table);
        if (isset($where)) {
            $select->where($where, $combination);
        }
        $select->limit(1);
        return iterator_to_array($this->selectWith($select))[0] ?? null;
    }

    public function avg(string $column)
    {
        $select = new Select($this->table);
        $select->columns([
            "c" => new Expression("avg(`$column`)")
        ]);

        return iterator_to_array($this->selectWith($select))[0]["c"] ?? null;
    }

    public function top(int $top)
    {
        $select = new Select($this->table);
        $select->limit($top);
        return iterator_to_array($this->selectWith($select));
    }
    public function dropColumn(string $name)
    {
        $alter = new AlterTable($this->table);
        $alter->dropColumn($name);
        $sql = new Sql($this->adapter);
        return $this->execute($sql->buildSqlString($alter));
    }


    public function truncate()
    {
        return $this->execute("TRUNCATE TABLE `{$this->table}`");
    }

    function getColumn(string $name)
    {
        return $this->columns()->first(fn($column) => $column->getName() === $name);
    }

    function renameColumn(string $oldName, string $newName)
    {
        $column = $this->getColumn($oldName);
        if (!$column) {
            throw new \Exception("Column '$oldName' not found");
        }

        // 取得原本的型別與屬性
        $type = $column->getDataType();
        $nullable = $column->isNullable();
        $default = $column->getColumnDefault();
        $length = $column->getCharacterMaximumLength();
        $precision = $column->getNumericPrecision();
        $scale = $column->getNumericScale();

        // 根據型別建立對應的 Column 物件
        switch (strtolower($type)) {
            case 'varchar':
            case 'char':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Varchar($newName, $length, $nullable, $default);
                break;
            case 'int':
            case 'integer':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Integer($newName, $nullable, $default);
                break;
            case 'text':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Text($newName, $nullable, $default);
                break;
            case 'decimal':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Decimal($newName, $precision, $scale, $nullable, $default);
                break;
            case 'datetime':
            case 'timestamp':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Datetime($newName, $nullable, $default);
                break;
            case 'date':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Date($newName, $nullable, $default);
                break;
            case 'float':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Floating($newName, $nullable, $default);
                break;
            case 'boolean':
            case 'tinyint':
                $newColumn = new \Laminas\Db\Sql\Ddl\Column\Boolean($newName, $nullable, $default);
                break;
            // 其他型別請依需求補齊
            default:
                // fallback: 用最基本的 Column
                $newColumn = new Column($newName, $nullable, $default);
                break;
        }

        $this->_columns = null;

        $alter = new AlterTable($this->table);
        $alter->changeColumn($oldName, $newColumn);

        $sql = new Sql($this->adapter);




        return $this->execute($sql->buildSqlString($alter));
    }

    private function execute(string $sql, $parametersOrQueryMode = Adapter::QUERY_MODE_EXECUTE)
    {
        /**
         * @var  \Laminas\Db\Adapter\Adapter $adapter
         */
        $adapter = $this->adapter;

        return $adapter->query($sql, $parametersOrQueryMode);
    }

    public function getPrimaryKey()
    {
        return $this->getConstraints()->first(fn($constraint) => $constraint->getType() === 'PRIMARY KEY')->getColumns();
    }

    public function rows($where)
    {
        return new LazyCollection(function () use ($where) {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            //  new Table
            $table = new TableGateway($this->table, $this->adapter, [
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

    public function max($column)
    {
        $select = new Select($this->table);
        $select->columns([
            "c" => new Expression("max(`$column`)")
        ]);
        return iterator_to_array($this->selectWith($select))[0]["c"] ?? null;
    }
}
