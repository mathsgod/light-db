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
}
