<?php

declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING);

use Light\Db\Schema;
use PHPUnit\Framework\TestCase;


final class ColumnTest extends TestCase
{
    public function testRename()
    {
        $db = Schema::Create();
        $table = $db->table("Testing");

        $table->renameColumn("name", "name1");

        $new_name = $table->column("name1");
        $this->assertEquals($new_name->getName(), "name1");

        $table->renameColumn("name1", "name");

        $org_name = $table->column("name");

        $this->assertEquals($org_name->getName(), "name");
    }
}
