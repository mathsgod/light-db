<?php

namespace Light\Db\Paginator;

use ArrayObject;
use Laminas\Paginator\Adapter\AdapterInterface;
use Light\Db\Query;

class Adapter implements AdapterInterface
{
    private $query;

    public function __construct(Query $query)
    {
        $this->query = $query;
    }

    function getItems($offset, $itemCountPerPage)
    {
        $query = clone $this->query;
        $query->offset($offset);
        $query->limit($itemCountPerPage);
        return new ArrayObject($query->toArray());
    }

    function count(): int
    {
        return $this->query->count();
    }
}
