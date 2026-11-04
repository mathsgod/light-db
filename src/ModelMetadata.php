<?php

declare(strict_types=1);

namespace Light\Db;

use Light\Entity\{EntityMapping, ValueComparer, ValueConverter};
use Light\Entity\Schema\SchemaReader;
use Light\Entity\Table;
use PDO;

/** Discovers and caches conventions and conversion rules for each concrete Model. */
final class ModelMetadata
{
    /** @var array<class-string<Model>, EntityMapping> */
    private array $mappings = [];
    private SchemaReader $schema;

    public function __construct(PDO $pdo) { $this->schema = new SchemaReader($pdo); }

    /** @param class-string<Model> $class */
    public function get(string $class): EntityMapping
    {
        if (isset($this->mappings[$class])) { return $this->mappings[$class]; }
        if (!is_subclass_of($class, Model::class)) { throw new \InvalidArgumentException('Expected a Model subclass.'); }
        $reflection = new \ReflectionClass($class);
        $properties = $reflection->getStaticProperties();
        $attribute = $reflection->getAttributes(Table::class)[0] ?? null;
        $table = $properties['_table'] ?? $attribute?->newInstance()->name ?? $reflection->getShortName();
        $columns = $this->schema->columns($table);
        $converters = []; $comparers = [];
        $customConverters = $class::valueConverters();
        $customComparers = $class::valueComparers();
        foreach ($columns as $column) {
            if (!preg_match('/^json(?:b)?$/i', $column->type)) { continue; }
            $converters[$column->name] = new ValueConverter(
                toProvider: fn ($v) => json_encode($v, JSON_THROW_ON_ERROR),
                fromProvider: fn ($v) => json_decode($v, true, 512, JSON_THROW_ON_ERROR),
            );
            $comparers[$column->name] = new ValueComparer(
                equals: fn ($a, $b) => json_encode($a, JSON_THROW_ON_ERROR) === json_encode($b, JSON_THROW_ON_ERROR),
                snapshot: fn ($v) => json_decode(json_encode($v, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                snapshotFromProvider: isset($customConverters[$column->name]) ? null : static fn ($v) => $v,
            );
        }
        return $this->mappings[$class] = EntityMapping::fromSchema($class, $table, $columns,
            array_replace($converters, $customConverters), array_replace($comparers, $customComparers));
    }

    public function clear(): void { $this->mappings = []; }
}
