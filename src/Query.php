<?php

namespace Light\Db;

use Laminas\Db\Sql\Select;
use Exception;
use Illuminate\Support\LazyCollection;
use IteratorAggregate;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Platform\PlatformInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Predicate\Predicate;
use Laminas\Db\TableGateway\Feature\RowGatewayFeature;
use Laminas\Paginator\Paginator;
use Light\Db\Paginator\Adapter;
use PhpParser\Node\Expr;
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

    public function getSqlString(?PlatformInterface $adapterPlatform = null)
    {
        $adapterPlatform = $adapterPlatform ?:  $this->adapter->getPlatform();
        return $this->buildSqlString($adapterPlatform);
    }

    private static $Order = [];
    static function RegisterOrder(string $class, string $name, callable $callback)
    {
        self::$Order[$class][$name] = $callback;
    }


    private static $Filters = [];
    static function RegisterFilter(string $class, string $name, callable $callback)
    {
        self::$Filters[$class][$name] = $callback;
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
                $sort = trim($sort);

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


    private function processFilter(Predicate $where, $filter)
    {
        if ($filter === null) {
            return;
        }
        //check $filter is numberic array
        if (array_values($filter) === $filter) {
            foreach ($filter as $f) {
                $this->processFilter($where, $f);
            }
            return;
        }

        foreach ($filter as $k => $v) {
            if ($k == "_or") {
                $orPredicate = $where->nest();

                $isFirst = true;
                foreach ($v as $orCondition) {
                    if (!$isFirst) {
                        $orPredicate = $orPredicate->or;
                    }

                    // 對於 OR 條件，我們需要為每個子條件創建一個嵌套的 predicate
                    if (is_array($orCondition) && count($orCondition) > 0) {
                        $subPredicate = $orPredicate->nest();
                        $this->processFilter($subPredicate, $orCondition);
                        $subPredicate->unnest();
                    }

                    $isFirst = false;
                }
                $orPredicate->unnest();
                continue;
            }

            // 添加 _and 支援
            if ($k == "_and") {
                $andPredicate = $where->nest();

                $isFirst = true;
                foreach ($v as $andCondition) {
                    if (!$isFirst) {
                        $andPredicate = $andPredicate->and;
                    }
                    $this->processFilter($andPredicate, $andCondition);
                    $isFirst = false;
                }
                $andPredicate->unnest();
                continue;
            }

            if (isset(self::$Filters[$this->class][$k])) {
                $predicate = new Predicate;
                $exp = self::$Filters[$this->class][$k]($v, $predicate);
                if (!$exp) {
                    $exp = $predicate;
                }
            } else {
                $exp = $k;
            }

            // If the expression is a callable result (like a subquery string), use it as a literal expression
            if (isset(self::$Filters[$this->class][$k])) {

                if ($exp instanceof Predicate) {
                    $where->addPredicate($exp);
                    continue;
                }

                if ($exp) {
                    $where->literal($exp);
                }
            } elseif (is_array($v)) {
                // 使用 FilterHelper 處理複雜的過濾條件
                $filterPredicate = FilterHelper::processFilterValue($exp, $v);
                $where->addPredicate($filterPredicate);
            } else {
                $where->equalTo($exp, $v);
            }
        }
    }
    /**
     * Applies multiple filters to the query.
     *
     * Clones the current query, processes the provided filters, and returns a new query instance with the applied filters.
     *
     * @param array|null $filters An associative array of filters to apply. Each key is a field name, and each value is either a value or an array of conditions.
     *                            Special keys like `_or` and `_and` can be used for logical grouping of conditions.
     * @return static A new query instance with the applied filters.
     */
    public function filters(?array $filters)
    {
        $query = clone $this;
        $this->processFilter($query->where, $filters);
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
