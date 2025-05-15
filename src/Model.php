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
    public $original = [];
    public $changed = [];

    /**
     * @return static
     */
    static function Create(?array $data = [])
    {

        //reflector class
        $ref_class = new ReflectionClass(static::class);

        $schema = self::GetSchema();
        $adapter = $schema->getAdapter();

        $primaryKey = "";
        $meta = Factory::createSourceFromAdapter($adapter);


        foreach ($meta->getConstraints(static::class) as $constraint) {
            if ($constraint->getType() == "PRIMARY KEY") {
                $primaryKey = $constraint->getColumns()[0];
                break;
            }
        }

        if (empty($primaryKey)) {
            throw new \Exception("No primary key found for " . static::class);
        }


        $obj = $ref_class->newInstance($primaryKey, static::class, $adapter);

        //$data[$primaryKey] = null;


        $metadata = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($adapter);
        foreach ($data as $key => $value) {
            if ($metadata->getColumn($key, static::class)->getDataType() == "json") {
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


        return static::GetSchema()->table($table);
    }



    public function populate(array $rowData, $rowExistsInDatabase = false)
    {
        return parent::populate($rowData, $rowExistsInDatabase);
    }

    public function exchangeArray($array)
    {

        $this->original = $array;
        $r = parent::exchangeArray($array);
        $this->data = [];

        /* //remove data from array except for primary key
        foreach ($this->data as $key => $value) {
            if (!in_array($key, $this->primaryKeyColumn)) {
                unset($this->data[$key]);
            }
        } */
        return $r;
    }

    public function __debugInfo()
    {
        return [
            'original' => $this->original,
            'data' => $this->data,
            'changed' => $this->changed,
        ];
        return array_merge($this->original, $this->data);
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

            if (array_key_exists($name, $this->data)) {
                $v = new Proxy($this, $name, $this->data[$name]);
                return $v;
            }

            if (array_key_exists($name, $this->original)) {
                if ($this->original[$name] == null) {
                    $v = null;
                    return $v;
                }

                $v = new Proxy($this, $name, $this->original[$name]);
                return $v;
            }

            $v = new Proxy($this, $name, parent::__get($name));
            return $v;
        }

        return $data[$name] ?? null;
        $v = parent::__get($name);
        return $v;
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
        if (is_array($value)) {

            //check if the value is a json
            $adapter = $this->sql->getAdapter();
            $metadata = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($adapter);
            $column = $metadata->getColumn($name, $this->sql->getTable());
            if ($column->getDataType() == "json") {
                $value = json_encode($value, 0, JSON_UNESCAPED_UNICODE);
            } else {
                $value = implode(",", $value);
            }


            return parent::__set($name, $value);
        }

        return parent::__set($name, $value);
    }

    protected function getPrimaryKey()
    {
        $primaryKey = "";
        $meta = Factory::createSourceFromAdapter($this->sql->getAdapter());
        foreach ($meta->getConstraints(static::class) as $constraint) {
            if ($constraint->getType() == "PRIMARY KEY") {
                $primaryKey = $constraint->getColumns()[0];
                break;
            }
        }
        return $primaryKey;
    }

    public function save()
    {
        $key = $this->getPrimaryKey();

        $adapter = $this->sql->getAdapter();
        $metadata = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($adapter);

        foreach ($this->data as $name => $value) {
            $column = $metadata->getColumn($name, $this->table);


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

        if (array_key_exists($key, $this->original)) {
            $this->data[$key] = $this->original[$key];
        } else {
            $this->data[$key] = null;
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
        //get class name
        $class = get_called_class();

        $query = new Query(static::class, $class, self::GetSchema()->getAdapter());
        if ($predicate) {
            $query->where($predicate, $combination);
        }
        return $query;
    }

    static $_schema = null;

    static function SetSchema(Schema $schema)
    {
        self::$_schema = $schema;
    }

    static function GetSchema(): Schema
    {
        if (self::$_schema == null) {
            self::$_schema = Schema::Create();
        }
        return self::$_schema;
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
        $adapter = self::GetSchema()->getAdapter();
        $primaryKey = "";
        $meta = Factory::createSourceFromAdapter($adapter);
        foreach ($meta->getConstraints(static::class) as $constraint) {
            if ($constraint->getType() == "PRIMARY KEY") {
                $primaryKey = $constraint->getColumns()[0];
                break;
            }
        }
        return $primaryKey;
    }

    // get the attributes of the model
    static function __attribute(?string $name = null)
    {
        if ($name) {
            foreach (self::__attributes() as $attribute) {
                if ($attribute->getName() == $name) {
                    return $attribute;
                }
            }
            return null;
        }

        return self::__attributes();
    }

    static function __attributes()
    {
        return self::_table()->_columns->all();
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
     * @deprecated
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
