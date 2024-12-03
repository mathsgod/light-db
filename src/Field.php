<?php

namespace Light\DB;

class Field
{

    public $name;
    public $value;

    public function __construct($value, Column $column)
    {
        $this->name = $column->name;
        $this->value = $value;
    }
}
