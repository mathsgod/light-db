<?php

declare(strict_types=1);

use Light\Db\Adapter;

final class ColumnTest extends BaseTestCase
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

    public function testJsonColumnDataType()
    {
        $db = Adapter::Create();
        $table = $db->getTable("Testing");
        $j = $table->column("j");

        $this->assertNotNull($j, "Column 'j' should exist");
        $this->assertEquals(
            "json",
            $j->getDataType(),
            "Column 'j' should be detected as json type (works on both MySQL 8.0 and MariaDB)"
        );
    }
}
