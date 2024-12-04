<?php

namespace Light\DB;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Metadata\Object\ColumnObject;
use Laminas\Db\Sql\Ddl;

class Column
{
    public $name;
    public $type;
    public $null;
    public $key;
    public $default;
    public $data;

    public function __construct(ColumnObject $column)
    {
        $this->data = $column;
        $this->name = $column->getName();
        $this->type = $column->getDataType();
        $this->null = $column->isNullable();
        $this->default = $column->getColumnDefault();
    }
}
