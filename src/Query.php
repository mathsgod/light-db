<?php

namespace Light\Db;

use Laminas\Db\Sql\Select;
use Exception;
use Illuminate\Support\LazyCollection;
use IteratorAggregate;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\TableGateway\Feature\RowGatewayFeature;
use Laminas\Paginator\Paginator;
use Light\Db\Paginator\Adapter;
use Traversable;
use ReflectionClass;

/**
 * @template T
 * @method static order(string|array|Expression $order)
 * @method static limit(int $limit)
 * @method static offset(int $offset)
 * @method static where(\Laminas\Db\Sql\Where|\Closure|string|array|\Laminas\Db\Sql\Predicate\PredicateInterface $predicate,string $combination = Predicate\PredicateSet::OP_AND)
 */
class Query extends Select implements IteratorAggregate
{
    private $class;
    private $_table;
    private $adapter;
    private $_custom_column = false;

    /**
     * @param class-string<T> $class
     */
    public function __construct(string $class, Table $table)
    {
        $this->class = $class;
        parent::__construct($table->getTable());
        $this->adapter = $table->getAdapter();
        $this->_table = $table; // Initialize $_table with the provided Table instance
    }

    private static $Order = [];
    static function RegisterOrder(string $class, string $name, callable $callback)
    {
        self::$Order[$class][$name] = $callback;
    }

    public function getClassName()
    {
        return $this->class;
    }

    private function getExecuteTable()
    {
        if (count($this->columns) == 1 && $this->columns[0] == "*") {
            //get primary key
            $primaryKey = $this->_table->getPrimaryKey();
            //reflector class
            $ref_class = new ReflectionClass($this->class);
            $instance = $ref_class->newInstanceArgs([
                $primaryKey,
                $this->_table->getTable(),
                $this->adapter
            ]);
            $table = new Table($this->table, $this->adapter, new RowGatewayFeature($instance));
        } else {
            $table = new Table($this->table, $this->adapter);
        }
        return $table;
    }

    public function cursor()
    {
        return new LazyCollection(function () {
            $table = $this->getExecuteTable();
            foreach ($table->selectWith($this) as $row) {
                yield $row;
            }
        });
    }

    /**
     * @return static
     */
    public function columns(array $columns, $prefixColumnsWithTable = true)
    {
        $this->_custom_column = true;
        return parent::columns($columns, $prefixColumnsWithTable);
    }

    public function count(): int
    {
        return $this->_table->count($this->where);
    }

    /**
     * Retrieves the first result from the query, or null if no results are found.
     *
     * Clones the current query, sets the offset to 0 and the limit to 1,
     * then executes the query and returns the first result if available.
     *
     * @return T|null The first result of the query, or null if no results exist.
     */
    public function first()
    {
        $c = clone $this;
        $c->offset(0);
        $c->limit(1);
        $result = $c->toArray();
        if (count($result) > 0) {
            return $result[0];
        }
        return null;
    }

    public function collect()
    {
        return $this->execute();
    }

    public function avg(string $column)
    {
        return $this->_table->avg($column, $this->where);
    }

    public function sum(string $column)
    {
        return $this->_table->sum($column, $this->where);
    }

    public function max(string $column)
    {
        return $this->_table->max($column, $this->where);
    }

    public function min(string $column)
    {
        return $this->_table->min($column, $this->where);
    }


    public function execute(array $input_parameters = [])
    {
        $a = collect([]);
        foreach ($this->cursor($input_parameters) as $obj) {
            if ($this->_custom_column) {
                $aa = [];
                foreach ($obj as $k => $v) {
                    $aa[$k] = $v;
                }
                $a->add($aa);
            } else {
                $a->add($obj);
            }
        }
        return $a;
    }

    function getIterator(): Traversable
    {
        return $this->cursor();
    }


    /**
     * Converts the current query result set to an array.
     *
     * Iterates over the query results and returns them as an array of type T.
     *
     * @return T[] An array containing all elements from the query result.
     */
    public function toArray()
    {
        return iterator_to_array($this);
    }

    public function delete()
    {
        return (new Table($this->table, $this->adapter))->delete($this->where);
    }

    public function update(array $values)
    {
        return (new Table($this->table, $this->adapter))->update($values, $this->where);
    }

    public function sort(?string $sort)
    {
        $query = clone $this;
        if ($sort) {
            $sorts = explode(",", $sort);
            foreach ($sorts as $sort) {

                //replace ":" to " "
                $sort = str_replace(":", " ", $sort);

                $s = explode(" ", $sort);

                if (isset(self::$Order[$this->class][$s[0]])) {
                    $query->order([self::$Order[$this->class][$s[0]]($s[1])]);
                } else {
                    $query->order($sort);
                }
            }
        }
        return $query;
    }

    public function filters(?array $filters)
    {
        $query = clone $this;
        foreach ($filters ?? [] as $field => $filter) {

            if (is_array($filter)) {



                foreach ($filter as $operator => $value) {

                    if ($operator == 'eq') {
                        $query->where->equalTo($field, $value);
                    }

                    if ($operator == 'contains') {
                        $query->where->like($field, "%$value%");
                    }

                    if ($operator == 'in') {
                        $query->where->in($field, $value);
                    }

                    if ($operator == 'between') {
                        $query->where->between($field, $value[0], $value[1]);
                    }

                    if ($operator == 'gt') {
                        $query->where->greaterThan($field, $value);
                    }

                    if ($operator == 'gte') {
                        $query->where->greaterThanOrEqualTo($field, $value);
                    }

                    if ($operator == 'lt') {
                        $query->where->lessThan($field, $value);
                    }

                    if ($operator == 'lte') {
                        $query->where->lessThanOrEqualTo($field, $value);
                    }

                    if ($operator == 'ne') {
                        $query->where->notEqualTo($field, $value);
                    }

                    if ($operator == 'nin') {
                        $query->where->notIn($field, $value);
                    }
                }
            } else {
                $query->where->equalTo($field, $filter);
            }
        }
        return $query;
    }

    public function filter(callable $filter)
    {
        return collect($this)->filter($filter);
    }

    public function map(callable $map)
    {
        return collect($this)->map($map);
    }

    public function getPaginator()
    {
        return new Paginator(new Adapter($this));
    }

    public function each(callable $callback)
    {
        foreach ($this as $obj) {
            $callback($obj);
        }
    }
}
