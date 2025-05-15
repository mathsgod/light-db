<?php

namespace Light\Db;

use ArrayObject;

class Proxy extends ArrayObject
{

    public $obj;
    public $property;
    public $data = [];
    public function __construct($obj, $property, $data)
    {
        $this->obj = $obj;
        $this->property = $property;
        if ($data) {
            $this->data = json_decode($data, true);
        }

        parent::__construct($this->data, ArrayObject::ARRAY_AS_PROPS);
    }


    public function offsetSet(mixed $key, mixed $value): void
    {
        parent::offsetSet($key, $value);

        $this->data[$key] = $value;
        $this->obj->__set($this->property, $this->data);
    }

    public function offsetUnset(mixed $key): void
    {
        parent::offsetUnset($key);

        unset($this->data[$key]);
        $this->obj->__set($this->property, $this->data);
    }

    public function offsetGet(mixed $key): mixed
    {
        // 若 key 不存在，回傳 null
        return parent::offsetExists($key) ? parent::offsetGet($key) : null;
    }
}
