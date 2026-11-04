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
    /** @var \WeakMap<EntityMapping, array<string, true>>|null */
    private static ?\WeakMap $sharedJsonSnapshots = null;

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
        foreach ($columns as $column) {
            if (!preg_match('/^json(?:b)?$/i', $column->type)) { continue; }
            $converters[$column->name] = new ValueConverter(
                toProvider: fn ($v) => json_encode($v, JSON_THROW_ON_ERROR),
                fromProvider: fn ($v) => json_decode($v, true, 512, JSON_THROW_ON_ERROR),
            );
            $comparers[$column->name] = new ValueComparer(
                equals: fn ($a, $b) => json_encode($a, JSON_THROW_ON_ERROR) === json_encode($b, JSON_THROW_ON_ERROR),
                snapshot: fn ($v) => json_decode(json_encode($v, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
            );
        }
        $customConverters = $class::valueConverters();
        $customComparers = $class::valueComparers();
        $mapping = EntityMapping::fromSchema($class, $table, $columns,
            array_replace($converters, $customConverters), array_replace($comparers, $customComparers));
        // Our own decoder produces reference-free arrays/scalars. PHP copy-on-write
        // isolates their hydrated snapshot without another JSON round trip.
        $shareable = array_fill_keys(array_diff(array_keys($converters), array_keys($customConverters), array_keys($customComparers)), true);
        self::$sharedJsonSnapshots ??= new \WeakMap();
        self::$sharedJsonSnapshots[$mapping] = $shareable;
        return $this->mappings[$class] = $mapping;
    }

    /** @internal Only for unchanged values just produced by the standard dynamic Model hydrator. */
    public static function snapshotHydrated(EntityMapping $mapping, array $values, Model $model): array
    {
        $shareable = self::$sharedJsonSnapshots[$mapping] ?? [];
        if (count($shareable) !== count($mapping->converters)) {
            // Custom converters may produce mutable objects or unsupported values;
            // retain the normal mapping validation and comparer semantics.
            return $mapping->snapshotValues($mapping->values($model));
        }
        foreach ($mapping->comparers as $name => $comparer) {
            if (!isset($shareable[$name]) && array_key_exists($name, $values)) {
                $values[$name] = $comparer->snapshot($values[$name]);
            }
        }
        return $values;
    }

    public function clear(): void { $this->mappings = []; }
}
