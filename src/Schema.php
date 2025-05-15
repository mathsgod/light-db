<?php

namespace Light\Db;

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Ddl\CreateTable;
use Laminas\Db\Sql\Ddl\DropTable;
use Laminas\Db\Sql\Sql;
use Laminas\Db\TableGateway\TableGateway;
use PDO;

class Schema
{
    private $adapter;
    public $_tables;
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;

        $this->_tables = new LazyCollection(function () {
            $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this->adapter);
            foreach ($meta->getTableNames() as $name) {
                yield new Table($name, $this->adapter);
            }
        });


    }

    function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    static function Create(array $options = []): Schema
    {
        //load from .env
        if (!isset($_ENV["DATABASE_HOSTNAME"])) {
            $dotenv = \Dotenv\Dotenv::createImmutable(getcwd());
            $dotenv->load();
        }

        $host = $_ENV["DATABASE_HOSTNAME"];
        $name = $_ENV["DATABASE_DATABASE"];
        $port = $_ENV["DATABASE_PORT"] ?? 3306;
        $username = $_ENV["DATABASE_USERNAME"];
        $password = $_ENV["DATABASE_PASSWORD"];
        $charset = $_ENV["DATABASE_CHARSET"] ?? "utf8mb4";

        if (!$host) throw new \Exception("DATABASE_HOSTNAME not found in .env");
        if (!$name) throw new \Exception("DATABASE_DATABASE not found in .env");
        if (!$username) throw new \Exception("DATABASE_USERNAME not found in .env");


        $driver_options = [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];

        if ($options !== null) {
            foreach ($options as $key => $opt) {
                $driver_options[$key] = $opt;
            }
        }


        return new static(new Adapter(
            [
                "database" => $name,
                "hostname" => $host,
                "username" => $username,
                "password" => $password,
                "port" => $port,
                "charset" => $charset,
                "driver" => "Pdo_Mysql",
                "driver_options" => $driver_options
            ]
        ));
    }


    public function getTableGateway(string $name, $features = null)
    {
        return new TableGateway($name, $this->adapter, $features);
    }

    public function table(string $name)
    {
        return $this->_tables->first(fn($table) => $table->getTable() === $name);
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

    public function createTable(string $name, callable $call)
    {
        $create = new CreateTable($name);
        $call($create);
        $sql = new Sql($this->adapter);
        return $this->adapter->query($sql->buildSqlString($create), Adapter::QUERY_MODE_EXECUTE);
    }

    public function hasTable(string $name): bool
    {
        $has = false;
        $this->_tables->each(function ($table) use ($name, &$has) {
            if ($table->getTable() === $name) {
                $has = true;
            }
        });
        return $has;
    }

    public function dropTable(string $name)
    {
        $drop = new DropTable($name);
        $sql = new Sql($this->adapter);
        return $this->adapter->query($sql->buildSqlString($drop), Adapter::QUERY_MODE_EXECUTE);
    }
}
