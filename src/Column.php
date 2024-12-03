<?php

namespace Light\DB;

use Laminas\Db\Metadata\Object\ColumnObject;

class Column
{
    public $name;
    public $type;
    public $null;
    public $key;
    public $default;
    public $extra;

    public function __construct($adapter, ColumnObject $column)
    {


        $this->name = $column->getName();
        $this->type = $column->getDataType();
        $this->null = $column->isNullable();
        $this->default = $column->getColumnDefault();
    }
}
