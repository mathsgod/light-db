<?php

namespace Light\Db;

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\RowGateway\RowGateway;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Ddl\AlterTable;
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

    /**
     * @return \Illuminate\Support\Collection<\Laminas\Db\Metadata\Object\ConstraintObject>
     */
    public function getConstraints(): \Illuminate\Support\Collection
    {
        if ($this->_constraints) return $this->_constraints;
        $this->_constraints = collect();
        $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);
        foreach ($meta->getConstraints($this->table) as $constraint) {
            $this->_constraints->push($constraint);
        }
        return $this->_constraints;
    }


    public function removeRow(array $where)
    {
        return $this->delete($where);
    }

    public function addRow(array $data)
    {
        $this->insert($data);
        return $this->getLastInsertValue();
    }

    /**
     * @return \Illuminate\Support\Collection<Column>
     */
    public function columns(): \Illuminate\Support\Collection
    {
        if ($this->_columns) return $this->_columns;
        $this->_columns = collect();

        $p = $this->adapter->getPlatform();
        if ($p->getName() == "MySQL") {
            $schema = $this->adapter->getCurrentSchema();

            $sql = "SELECT * FROM `INFORMATION_SCHEMA`.`COLUMNS`  Where `TABLE_NAME`  = "
                . $p->quoteTrustedValue($this->table)
                . " AND `TABLE_SCHEMA` = " . $p->quoteTrustedValue($schema);

            $result = $this->adapter->query($sql)->execute();

            foreach ($result as $row) {
                $column = new \Light\Db\Column($row["COLUMN_NAME"], $this->table);
                $column->setOrdinalPosition($row["ORDINAL_POSITION"]);
                $column->setDataType($row["DATA_TYPE"]);
                $column->setIsNullable($row["IS_NULLABLE"] === "YES");
                if (
                    $row["EXTRA"] == "auto_increment"
                    || $row["EXTRA"] == "VIRTUAL GENERATED"
                    || $row["EXTRA"] == "DEFAULT_GENERATED"
                    || $row["EXTRA"] == "STORED GENERATED"
                ) {
                    $column->setColumnDefault(null);
                } else {
                    $column->setColumnDefault($row["COLUMN_DEFAULT"]);
                }


                $column->setCharacterMaximumLength($row["CHARACTER_MAXIMUM_LENGTH"]);
                $column->setCharacterOctetLength($row["CHARACTER_OCTET_LENGTH"]);
                $column->setNumericPrecision($row["NUMERIC_PRECISION"]);
                $column->setNumericScale($row["NUMERIC_SCALE"]);
                $column->setNumericUnsigned(false !== strpos($row['COLUMN_TYPE'], 'unsigned'));
                $column->setVirtualGenerated($row["EXTRA"] === "VIRTUAL GENERATED");
                $column->setAutoIncrement($row["EXTRA"] === "auto_increment");

                $this->_columns->push($column);
            }
        } else {
            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);
            foreach ($meta->getColumns($this->table) as $column) {
                $this->_columns->push($column);
            }
        }

        return $this->_columns;
    }

    public function column(string $name): ?Column
    {
        return $this->columns()->first(fn($column) => $column->getName() === $name);
    }

    public function removeColumn(string $name)
    {
        $this->_columns = null;
        $this->_constraints = null;
        $alter = new Ddl\AlterTable($this->table);
        $alter->dropColumn($name);

        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        return $this->execute($sql->buildSqlString($alter));
    }

    public function addColumn(ColumnInterface $column)
    {
        $this->_columns = null;
        $this->_constraints = null;
        $table = new Ddl\AlterTable($this->table);
        $table->addColumn($column);

        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        return $this->execute($sql->buildSqlString($table));
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
    public function min(string $column, $where = null)
    {
        $select = new Select($this->table);
        if (isset($where)) {
            $select->where($where);
        }
        $select->columns([
            "c" => new Expression("min(`$column`)")
        ]);
        $select->limit(1);

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

    public function avg(string $column, $where = null)
    {
        $select = new Select($this->table);
        if (isset($where)) {
            $select->where($where);
        }
        $select->columns([
            "c" => new Expression("avg(`$column`)")
        ]);

        return iterator_to_array($this->selectWith($select))[0]["c"] ?? null;
    }

    public function sum(string $column, $where = null)
    {
        $select = new Select($this->table);
        if (isset($where)) {
            $select->where($where);
        }
        $select->columns([
            "c" => new Expression("sum(`$column`)")
        ]);

        return iterator_to_array($this->selectWith($select))[0]["c"] ?? null;
    }

    public function top(int $top, $where = null)
    {
        $select = new Select($this->table);
        if (isset($where)) {
            $select->where($where);
        }
        $select->limit($top);
        return iterator_to_array($this->selectWith($select));
    }
    public function dropColumn(string $name)
    {
        $alter = new AlterTable($this->table);
        $alter->dropColumn($name);
        $sql = new Sql($this->adapter);
        $this->_columns = null;
        return $this->execute($sql->buildSqlString($alter));
    }


    public function truncate()
    {
        $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);
        $tableNames = $meta->getTableNames();
        if (!in_array($this->table, $tableNames)) {
            throw new \Exception("Table {$this->table} not found");
        }
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

            default:
                $newColumn = new class($newName, $nullable, $default, $type) extends \Laminas\Db\Sql\Ddl\Column\Column {
                    public function __construct($name, $nullable = null, $default = null, $type = null)
                    {
                        parent::__construct($name, $nullable, $default);
                        $this->type = $type;
                    }
                };



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
        $primaryKeys = $this->getConstraints()->first(fn($constraint) => $constraint->getType() === 'PRIMARY KEY')->getColumns();
        return array_unique($primaryKeys);
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

    public function max($column, $where = null)
    {
        $select = new Select($this->table);
        if (isset($where)) {
            $select->where($where);
        }
        $select->columns([
            "c" => new Expression("max(`$column`)")
        ]);
        $select->limit(1);

        return iterator_to_array($this->selectWith($select))[0]["c"] ?? null;
    }
}
