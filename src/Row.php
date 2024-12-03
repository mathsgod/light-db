<?php

namespace Light\DB;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @property FieldCollection $fields
 */
class Row
{
    public $data;
    public $table;
    public $columns;

    public function __construct($data, Table $table, \Illuminate\Support\Enumerable $columns)
    {
        $this->data = $data;
        $this->table = $table;
        $this->columns = $columns;

        $this->fields = new LazyCollection(function () {
            foreach ($this->columns as $column) {
                yield new Field($this->data[$column->name], $column);
            }
        });
    }
}
