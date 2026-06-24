<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for composite primary keys that contain an auto-increment column.
 */
final class CompositePrimaryKeyTest extends BaseTestCase
{
    public function testCreateWithCompositePrimaryKey(): void
    {
        $model = TestingComposite::Create([
            'tenant_id' => 42,
            'name' => 'Composite PK Test',
        ]);

        $model->save();

        $this->assertNotNull($model->id);
        $this->assertSame(42, $model->tenant_id);
        $this->assertSame('Composite PK Test', $model->name);

        $retrieved = TestingComposite::Get([
            'id' => $model->id,
            'tenant_id' => $model->tenant_id,
        ]);

        $this->assertNotNull($retrieved);
        $this->assertSame($model->id, $retrieved->id);
        $this->assertSame(42, $retrieved->tenant_id);
        $this->assertSame('Composite PK Test', $retrieved->name);
    }

    public function testUpdateWithCompositePrimaryKey(): void
    {
        $model = TestingComposite::Create([
            'tenant_id' => 7,
            'name' => 'Before Update',
        ]);
        $model->save();

        $model->name = 'After Update';
        $model->save();

        $retrieved = TestingComposite::Get([
            'id' => $model->id,
            'tenant_id' => $model->tenant_id,
        ]);

        $this->assertNotNull($retrieved);
        $this->assertSame('After Update', $retrieved->name);
    }

    public function testDeleteWithCompositePrimaryKey(): void
    {
        $model = TestingComposite::Create([
            'tenant_id' => 99,
            'name' => 'To Be Deleted',
        ]);
        $model->save();

        $id = $model->id;
        $tenantId = $model->tenant_id;

        $model->delete();

        $retrieved = TestingComposite::Get([
            'id' => $id,
            'tenant_id' => $tenantId,
        ]);

        $this->assertNull($retrieved);
    }
}
