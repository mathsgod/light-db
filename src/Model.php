<?php

namespace Light\Db;

use ArrayObject;
use Exception;
use Illuminate\Support\Arr;
use Laminas\Db\Metadata\Source\Factory;
use Laminas\Db\RowGateway\RowGateway;
use Laminas\Db\Sql\Predicate;
use ReflectionClass;
use ReflectionObject;

abstract class Model extends RowGateway
{
    protected $original = [];
    protected $changed = [];

    static function Create(?array $data = []): static
    {

        //reflector class
        $ref_class = new ReflectionClass(static::class);

        $table = self::_table();

        $primaryKeys = $table->getPrimaryKey();
        if (count($primaryKeys) == 0) {
            throw new \Exception("No primary key found for " . static::class);
        }


        $obj = $ref_class->newInstance($primaryKeys, static::class, $table->adapter);

        foreach ($table->columns() as $column) {
            $obj->original[$column->getName()] = $column->getColumnDefault();
        }

        foreach ($data as $key => $value) {
            $col = $table->column($key);
            if (!$col) continue; // 避免不存在欄位
            if ($col->getDataType() == "json") {
                if (is_array($value)) {
                    $data[$key] = json_encode($value, 0, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $obj->populate($data, false);


        return $obj;
    }

    /**
     * @param Where|string|int|array $where
     * @return ?static
     */
    static function Get($where)
    {
        if ($where === null) {
            return null;
        }

        if (is_numeric($where) || is_string($where)) {
            $key = self::_table()->getPrimaryKey()[0];
            $q = self::Query([$key => $where]);
        } else {
            $q = self::Query($where);
        }

        $obj = $q->first();
        if ($obj) {
            return $obj;
        }
        return null;
    }

    public static function _table()
    {
        $class = new \ReflectionClass(get_called_class());
        $props = $class->getStaticProperties();

        $table = $class->getShortName();
        if (isset($props["_table"]))
            $table = $props["_table"];


        return static::GetAdapter()->getTable($table);
    }

    public function exchangeArray($array)
    {
        $this->original = $array;
        $r = parent::exchangeArray($array);
        $this->data = [];
        return $r;
    }

    public function __debugInfo()
    {
        return [
            'original' => $this->original,
            'data' => $this->data,
            'changed' => $this->changed,
        ];
    }

    /**
     * __get
     *
     * @param  string $name
     * @throws Exception\InvalidArgumentException
     * @return mixed
     */
    public function __get($name)
    {

        $adapter = $this->sql->getAdapter();

        $metadata = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($adapter);
        $columns = $metadata->getColumnNames($this->sql->getTable());

        if (!in_array($name, $columns)) {
            //relation
            $ro = new ReflectionObject($this);

            $namespace = $ro->getNamespaceName();
            if ($namespace == "") {
                $class = $name;
            } else {
                $class = $namespace . "\\" . $name;
                if (!class_exists($class)) {
                    $class = $name;
                }
            }
            if (!class_exists($class)) {
                return parent::__get($name);
            }

            $key = static::_key();
            return $class::Query([$key => $this->$key]);
        }



        $column = $metadata->getColumn($name, $this->sql->getTable());



        $data = array_merge($this->original, $this->data);
        if ($column->getDataType() == "tinyint") {
            return (bool) $data[$name];
        }

        if ($column->getDataType() == "json") {

            $d = json_decode($data[$name], true);

            if (is_array($d)) {
                $v = new Proxy($this, $name, $data[$name]);
                return $v;
            }
            return $d;
        }

        return $data[$name] ?? null;
    }

    /**
     * __set
     *
     * @param  string $name
     * @param  mixed $value
     * @return void
     */
    public function __set($name, $value)
    {
        //check if the value is a json
        $column = self::_table()->column($name);
        if ($column->getDataType() == "json") {
            $value = json_encode($value, 0, JSON_UNESCAPED_UNICODE);
        } else {
            if (is_array($value)) {
                $value = implode(",", $value);
            }
        }

        return parent::__set($name, $value);
    }

    protected function getPrimaryKey()
    {
        return self::_table()->getPrimaryKey()[0];
    }

    public function save()
    {
        $key = $this->getPrimaryKey();
        $table = self::_table();

        foreach ($this->data as $name => $value) {
            $column =  $table->column($name);
            if (!$column) continue;

            if ($column->getDataType() == "int" && $value === "") {
                if ($column->isNullable()) {
                    $this->data[$name] = null;
                    continue;
                }
            }

            if ($column->getDataType() == "int" && !$column->isNullable()) {

                if ($value === "" || is_null($value)) {
                    $this->data[$name] = 0;
                    continue;
                }
            }
        }

        $this->data[$key] = $this->original[$key];

        foreach ($table->columns() as $column) {
            $colName = $column->getName();
            if ($colName == $key) continue;

            if (!$column->isNullable()) {
                if (array_key_exists($colName, $this->data)) {
                    if ($this->data[$colName] === null && !$column->getColumnDefault()) {
                        if (in_array($column->getDataType(), ["int", "tinyint", "smallint", "mediumint", "bigint"])) {
                            $this->data[$colName] = 0;
                        } else {
                            $this->data[$colName] = "";
                        }
                    }
                } else {
                    if ($column->getColumnDefault() === null) {
                        if (in_array($column->getDataType(), ["int", "tinyint", "smallint", "mediumint", "bigint"])) {
                            $this->data[$colName] = 0;
                        } else {
                            $this->data[$colName] = "";
                        }
                    }
                }
            }
        }


        $result = parent::save();
        $this->changed = $this->data;
        $this->original = array_merge($this->original, $this->data);
        $this->data = [];

        return $result;
    }

    /**
     * @return Query<static> & iterable<static>
     * @param Where|\Closure|string|array|Predicate\PredicateInterface $predicate
     */
    static function Query($predicate = null, $combination = Predicate\PredicateSet::OP_AND)
    {
        $table = self::_table();
        $query = new Query(static::class, $table->getTable(),  $table->adapter);
        if ($predicate) {
            $query->where($predicate, $combination);
        }
        return $query;
    }

    protected static $_adapter = null;

    public static function SetAdapter(Adapter $adapter)
    {
        self::$_adapter = $adapter;
    }

    public static function GetAdapter(): Adapter
    {
        if (self::$_adapter == null) {
            self::$_adapter = Adapter::Create();
        }
        return self::$_adapter;
    }

    function wasChanged(?string $name = null): bool
    {
        if (is_null($name)) {
            return count($this->changed) > 0;
        }
        return array_key_exists($name, $this->changed);
    }


    function isDirty(?string $name = null): bool
    {
        if (is_null($name)) {
            return count($this->getDirty()) > 0;
        }

        return isset($this->data[$name]);
    }

    function getDirty(): array
    {
        return $this->data;
    }

    /**
     * get the primary key of the model,
     * if the model has no primary key, return null,
     * if the model has multiple primary keys, return an array
     * @return string|array|null
     */
    static function _key()
    {
        $primaryKey = self::_table()->getPrimaryKey();
        return $primaryKey[0] ?? null;
    }

    // get the attributes of the model
    static function __attribute(?string $name = null)
    {
        if ($name) {
            return self::_table()->column($name);
        }

        return self::__attributes();
    }

    static function __attributes()
    {
        return self::_table()->columns()->all();
    }

    function __call($class_name, $args)
    {
        $ro = new ReflectionObject($this);

        $namespace = $ro->getNamespaceName();
        if ($namespace == "") {
            $class = $class_name;
        } else {
            $class = $namespace . "\\" . $class_name;
            if (!class_exists($class)) {
                $class = $class_name;
            }
        }

        if (!class_exists($class)) {
            throw new Exception($class . " class not found");
        }

        $key = forward_static_call(array($class, "_key"));
        if (self::_table()->column($key)) {
            $id = $this->$key;
            if (!$id) return null;
            return $class::Get($this->$key);
        }

        $key = static::_key();
        $q = $class::Query([$key => $this->$key]);
        $q->where($args);

        return new ArrayObject($q->toArray());
    }


    /**
     * @deprecated use populate instead
     */
    function bind($rs)
    {
        if (is_object($rs)) { // convert to array
            $rs = (array)$rs;
        }
        $this->data = array_merge($this->data, $rs);
        return $this;
    }
}
