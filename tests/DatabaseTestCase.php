<?php

declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/** Each test gets an isolated SQLite database or disposable MySQL schema. */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected Database $db;
    protected ?string $schema = null;

    protected function setUp(): void
    {
        $dsn = getenv('LIGHT_DB_TEST_DSN');
        if ($dsn) {
            $this->pdo = new PDO($dsn, getenv('LIGHT_DB_TEST_USER') ?: 'root', getenv('LIGHT_DB_TEST_PASSWORD') ?: '', [PDO::ATTR_EMULATE_PREPARES=>false]);
            $this->schema = 'light_db_v2_'.bin2hex(random_bytes(8));
            $this->pdo->exec('CREATE DATABASE `'.$this->schema.'` CHARACTER SET utf8mb4');
            $this->pdo->exec('USE `'.$this->schema.'`');
        } else {
            if (!in_array('sqlite', PDO::getAvailableDrivers(),true)) { self::markTestSkipped('pdo_sqlite or LIGHT_DB_TEST_DSN required.'); }
            $this->pdo = new PDO('sqlite::memory:');
        }
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE User (id '.($mysql?'INT AUTO_INCREMENT':'INTEGER').' PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, age INT DEFAULT 18, settings '.'JSON'.", label VARCHAR(110) GENERATED ALWAYS AS (".($mysql?"CONCAT(name,'!')":"name || '!'").') VIRTUAL)');
        $this->pdo->exec('CREATE TABLE memberships(a INT,b INT,name VARCHAR(100),PRIMARY KEY(a,b))');
        $this->pdo->exec('CREATE TABLE Collision(id INT PRIMARY KEY, original VARCHAR(100), attributes VARCHAR(100), owner VARCHAR(100))');
        $insert = $this->pdo->prepare('INSERT INTO User(name,age,settings) VALUES(?,?,?)');
        $insert->execute(['Ada',30,'{"theme":"light","nested":{"tags":["a"]}}']);
        $insert->execute(['Bob',20,null]);
        $insert->execute(['Cara',10,null]);
        $this->db = new Database($this->pdo);
        Database::setDefault($this->db);
    }
    protected function tearDown(): void
    {
        Database::setDefault(null);
        if ($this->schema !== null) { $this->pdo->exec('DROP DATABASE `'.$this->schema.'`'); }
        parent::tearDown();
    }

}
