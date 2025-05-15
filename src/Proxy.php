<?php

namespace Light\Db;

use ArrayObject;

class Proxy extends ArrayObject
{

    public $obj;
    public $property;
    public $data;
    public function __construct($obj, $property, $data)
    {
        $this->obj = $obj;
        $this->property = $property;
        $this->data = json_decode($data, true);
        parent::__construct(json_decode($data, true), ArrayObject::ARRAY_AS_PROPS);
    }


    public function offsetSet(mixed $key, mixed $value): void
    {
        parent::offsetSet($key, $value);

        $this->data[$key] = $value;
        $this->obj->__set($this->property, $this->data);
    }
}
