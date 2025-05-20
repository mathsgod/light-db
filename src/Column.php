<?php


namespace Light\Db;

use Laminas\Db\Metadata\Object\ColumnObject;

class Column extends ColumnObject
{

    /** @var bool */
    protected $isVirtualGenerated = false;

    public function isVirtualGenerated(): bool
    {
        return $this->isVirtualGenerated;
    }

    public function setVirtualGenerated(bool $isVirtualGenerated): void
    {
        $this->isVirtualGenerated = $isVirtualGenerated;
    }

}
