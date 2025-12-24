<?php

namespace Light\Db;

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Profiler\ProfilerInterface;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Ddl\CreateTable;
use Laminas\Db\Sql\Ddl\DropTable;
use Laminas\Db\Sql\Sql;

use PDO;

class Adapter extends \Laminas\Db\Adapter\Adapter
{
    protected $tables = null;
    static $instance = null;
    public $isMariaDB = false;

    static function Create(array $options = [],  ?ProfilerInterface $profiler = null): static
    {
        if (self::$instance) return self::$instance;


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
        $driver = $_ENV["DATABASE_DRIVER"] ?? "pdo_mysql";

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
            $driver_options = array_merge($driver_options, $options);
        }


        self::$instance = new static(
            [
                "database" => $name,
                "hostname" => $host,
                "username" => $username,
                "password" => $password,
                "port" => $port,
                "charset" => $charset,
                "driver" => $driver,
                "driver_options" => $driver_options
            ],
            null,
            null,
            $profiler
        );

        if ($charset == "utf8mb4") {
            self::$instance->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_0900_ai_ci'")->execute();
        }

        //get table 
        $version = self::$instance->query("SELECT VERSION() as version")->execute()->current()["version"];
        self::$instance->isMariaDB = (stripos($version, 'MariaDB') !== false);


        return self::$instance;
    }

    public function disconnect()
    {
        $this->getDriver()->getConnection()->disconnect();
        self::$instance = null;
    }

    public function getTable(string $name): ?Table
    {
        return $this->getTables()->first(fn($table) => $table->getTable() === $name);
    }

    public function getTables()
    {
        if ($this->tables) return $this->tables;
        $meta = \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this);

        $collect = collect();

        foreach ($meta->getTableNames() as $tableName) {
            $collect->push(new Table($tableName, $this));
        }
        return $this->tables = $collect;
    }


    public function addTable(Ddl\CreateTable $table)
    {
        $this->tables = null;

        $sql = new \Laminas\Db\Sql\Sql($this);
        return $this->query(
            $sql->buildSqlString($table),
            Adapter::QUERY_MODE_EXECUTE
        );
    }

    public function getTriggers()
    {
        return $this->getMetadata()->getTriggers();
    }

    public function getMetadata()
    {
        return \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($this);
    }

    public function removeTable(string $name)
    {
        $this->tables = null;
        $sql = new \Laminas\Db\Sql\Sql($this);
        return $this->query(
            $sql->buildSqlString(new Ddl\DropTable($name)),
            Adapter::QUERY_MODE_EXECUTE
        );
    }

    public function createTable(string $name, callable $call)
    {
        $this->tables = null;
        $create = new CreateTable($name);
        $call($create);
        $sql = new Sql($this);
        return $this->query($sql->buildSqlString($create), Adapter::QUERY_MODE_EXECUTE);
    }

    public function hasTable(string $name): bool
    {
        $has = false;
        $this->getTables()->each(function ($table) use ($name, &$has) {
            if ($table->getTable() === $name) {
                $has = true;
            }
        });
        return $has;
    }

    public function dropTable(string $name)
    {
        $drop = new DropTable($name);
        $sql = new Sql($this);
        $this->tables = null;
        return $this->query($sql->buildSqlString($drop), Adapter::QUERY_MODE_EXECUTE);
    }

    public function beginTransaction()
    {
        return $this->getDriver()->getConnection()->beginTransaction();
    }

    public function commit()
    {
        return $this->getDriver()->getConnection()->commit();
    }

    public function rollback()
    {
        return $this->getDriver()->getConnection()->rollback();
    }
}
