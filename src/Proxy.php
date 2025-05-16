<?php

namespace Light\Db;

use ArrayObject;

class Proxy extends ArrayObject
{
    public $obj;
    public $property;
    public $data = [];
    public $parentProxy = null;
    public $parentKey = null;

    public function __construct($obj, $property, $data, $parentProxy = null, $parentKey = null)
    {
        $this->obj = $obj;
        $this->property = $property;
        $this->parentProxy = $parentProxy;
        $this->parentKey = $parentKey;

        if (is_string($data)) {
            $this->data = json_decode($data, true) ?? [];
        } elseif (is_array($data)) {
            $this->data = $data;
        } else {
            $this->data = [];
        }

        parent::__construct($this->data, ArrayObject::ARRAY_AS_PROPS);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        // 如果 value 係 array，自動包 Proxy
        if (is_array($value)) {
            $value = new Proxy($this->obj, $this->property, $value, $this, $key);
        }
        parent::offsetSet($key, $value);

        $this->data[$key] = $this->_extractArray($value);
        $this->_sync();
    }

    public function offsetUnset(mixed $key): void
    {
        parent::offsetUnset($key);
        unset($this->data[$key]);
        $this->_sync();
    }

    public function offsetGet(mixed $key): mixed
    {
        $value = parent::offsetExists($key) ? parent::offsetGet($key) : null;
        // 如果 value 係 array（未包 Proxy），包一層 Proxy
        if (is_array($value) && !($value instanceof Proxy)) {
            $proxy = new Proxy($this->obj, $this->property, $value, $this, $key);
            parent::offsetSet($key, $proxy);
            $this->data[$key] = $proxy->toArray();
            return $proxy;
        }
        return $value;
    }

    // 將 Proxy 轉返 array
    public function toArray()
    {
        $arr = [];
        foreach ($this as $k => $v) {
            if ($v instanceof Proxy) {
                $arr[$k] = $v->toArray();
            } else {
                $arr[$k] = $v;
            }
        }
        return $arr;
    }

    // 取出 array 給 data 用
    private function _extractArray($value)
    {
        if ($value instanceof Proxy) {
            return $value->toArray();
        }
        return $value;
    }

    // 同步到 Model
    private function _sync()
    {
        // 如果有 parent，向上同步
        if ($this->parentProxy && $this->parentKey !== null) {
            $this->parentProxy[$this->parentKey] = $this;
        } else {
            // 最頂層，寫返去 Model
            $this->obj->__set($this->property, $this->toArray());
        }
    }
}