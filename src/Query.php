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
    protected $class;
    private $_custom_column = false;

    protected $adapter;

    /**
     * @param class-string<T> $class
     * @param string $table
     */
    public function __construct(string $class, string $table, AdapterInterface $adapter)
    {
        $this->class = $class;
        parent::__construct($table);
        $this->adapter = $adapter;
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
            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            $primaryKey = "";
            foreach ($meta->getConstraints($this->table) as $constraint) {
                if ($constraint->getType() == "PRIMARY KEY") {
                    $primaryKey = $constraint->getColumns()[0];
                    break;
                }
            }
            if (!$primaryKey) {
                throw new Exception("No primary key found for table $this->table");
            }
            //reflector class
            $ref_class = new ReflectionClass($this->class);
            $instance = $ref_class->newInstanceArgs([
                $primaryKey,
                $this->table,
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

    public function count(?string $column = "*"): int
    {
        $c = clone $this;
        $c->offset(0);
        $c->limit(1);
        $c->columns([
            "c" => new Expression("count($column)")
        ]);
        $table = new Table($this->table, $this->adapter);
        return $table->selectWith($c)->current()["c"];
    }

    /**
     * @return T|null
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
        $c = clone $this;
        $c->columns([
            "c" => new Expression("avg($column)")
        ]);

        $table = new Table($this->table, $this->adapter);
        return $table->selectWith($c)->current()["c"];
    }

    public function sum(string $column)
    {
        $c = clone $this;
        $c->columns([
            "c" => new Expression("sum($column)")
        ]);

        $table = new Table($this->table, $this->adapter);
        return $table->selectWith($c)->current()["c"];
    }

    public function max(string $column)
    {
        $c = clone $this;
        $c->columns([
            "c" => new Expression("max($column)")
        ]);

        $table = new Table($this->table, $this->adapter);
        return $table->selectWith($c)->current()["c"];
    }

    public function min(string $column)
    {
        $c = clone $this;
        $c->columns([
            "c" => new Expression("min($column)")
        ]);
        $table = new Table($this->table, $this->adapter);
        return $table->selectWith($c)->current()["c"];
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
     * @return T[]
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
                $s = explode(':', $sort);
                //custom order
                if (isset(self::$Order[$this->class][$s[0]])) {
                    $query->order([self::$Order[$this->class][$s[0]]($s[1])]);
                } else {
                    $query->order($s[0] . " " . $s[1]);
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
