<?php

namespace Light\DB;

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Ddl;

class Schema
{
    private $adapter;
    public $tables;
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->tables = new LazyCollection(function () {

            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);

            foreach ($meta->getTableNames() as $name) {
                yield new Table($this->adapter, $name);
            }
        });
    }

    public function table(string $name)
    {
        return $this->tables->first(fn($table) => $table->name === $name);
    }


    public function addTable(Ddl\CreateTable $table)
    {
        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        return $this->adapter->query(
            $sql->buildSqlString($table),
            Adapter::QUERY_MODE_EXECUTE
        );
    }

    public function removeTable(string $name)
    {
        $sql = new \Laminas\Db\Sql\Sql($this->adapter);
        return $this->adapter->query(
            $sql->buildSqlString(new Ddl\DropTable($name)),
            Adapter::QUERY_MODE_EXECUTE
        );
    }
}
