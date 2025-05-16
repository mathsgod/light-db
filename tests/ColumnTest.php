<?php

declare(strict_types=1);
error_reporting(E_ALL);

use Light\Db\Adapter;

use PHPUnit\Framework\TestCase;


final class ColumnTest extends TestCase
{
    public function testRename()
    {
        $db = Adapter::Create();
        $table = $db->getTable("Testing");

        $table->renameColumn("name", "name1");

        $new_name = $table->column("name1");
        $this->assertEquals($new_name->getName(), "name1");

        $table->renameColumn("name1", "name");

        $org_name = $table->column("name");

        $this->assertEquals($org_name->getName(), "name");
    }
}
