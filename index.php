<?php

use Illuminate\Support\LazyCollection;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Ddl\CreateTable;

require_once __DIR__ . '/vendor/autoload.php';

$adapter = new Adapter([
    "driver" => "Pdo_Mysql",
    "database" => "raymond",
    "username" => "root",
    "password" => "111111",
    "hostname" => "127.0.0.1",
    "charset" => "UTF8"
]);

$schema = new \Light\DB\Schema($adapter);

/* $schema->addTable((new CreateTable("Testing5"))
        ->addColumn(new \Laminas\Db\Sql\Ddl\Column\Integer("value1"))
        ->addColumn(new \Laminas\Db\Sql\Ddl\Column\Integer("value2"))
);
 */
$schema->removeTable("Testing5");
//$schema->table('Testing5')->addColumn(new \Laminas\Db\Sql\Ddl\Column\Integer("value4"));




/*$t = new CreateTable("Testing5");
$t->addColumn(new \Laminas\Db\Sql\Ddl\Column\Integer("value1"));
$t->addColumn(new \Laminas\Db\Sql\Ddl\Column\Integer("value2"));
*/

//print_R($schema->addTable($t));

//$schema->removeTable("Testing5");


/* 

$t = $schema->table('Testing4');


print_R($t->addRow([
    "value1" => 1,
    "value2" => 2,
]));
 */




//$table = $schema->tables->first(fn($table) => $table->name === 'User');

//$table->columns