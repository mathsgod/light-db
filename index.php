<?php

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\Profiler\ProfilerInterface;
use Laminas\Db\RowGateway\Feature\FeatureSet;
use Laminas\Db\Sql\Ddl\CreateTable;
use Laminas\Db\Sql\Select;
use Laminas\Db\TableGateway\Feature\RowGatewayFeature;

require_once __DIR__ . '/vendor/autoload.php';

class Profiler implements ProfilerInterface
{
    public function profilerStart($target)
    {
        if ($target instanceof \Laminas\Db\Adapter\StatementContainerInterface) {
            echo $target->getSql() . "\n";
        } else {
            echo $target . "\n";
        }
    }

    public function profilerFinish() {}
}


$adapter = new Adapter([
    "driver" => "Pdo_Mysql",
    "database" => "raymond",
    "username" => "root",
    "password" => "111111",
    "hostname" => "127.0.0.1",
    "charset" => "UTF8"
]);

$schema = new \Light\DB\Schema($adapter);

$table = $schema->getTableGateway("User", new RowGatewayFeature("user_id"));
$rowset = $table->select(function (Select $select) {
    $select->limit(3);
});

foreach ($rowset as $row) {
    print_r($row->username);
}
die();

$schema->table("User")->rows->all();


$schema->removeTable('Testing5');
$schema->addTable((new CreateTable("Testing5"))
        ->addColumn(
            (new \Laminas\Db\Sql\Ddl\Column\Integer("value1"))->setOption("AUTO_INCREMENT", true)
        )
        ->addColumn(new \Laminas\Db\Sql\Ddl\Column\Integer("value2"))
        ->addConstraint(new \Laminas\Db\Sql\Ddl\Constraint\PrimaryKey("value1"))
);

$t = $schema->table('Testing5');

$t->addRow([
    "value2" => 2,
]);
$t->addRow([
    "value2" => 3,
]);

print_r($t->rows->first()->value2);
die();
$r1 = $t->rows->first();
$r1->value2 = 3;
$r1->save();
//$r1->delete();


die();


$row = $t->row([
    "value1" => 1
]);
$row->value2 = 3;
$row->save();




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