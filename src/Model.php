<?php

declare(strict_types=1);

namespace Light\Db;

use Light\Entity\{Entity, EntityMapping, EntityState};

/** Convention-based Active Record; hydration and persistence are provided by light-entity. */
abstract class Model extends Entity
{
    private ?Database $owner = null;
    private ?EntityMapping $mapping = null;
    private array $original = [];
    private bool $persisted = false;
    private bool $deleted = false;
    private bool $readOnly = false;
    private static array $filters = [];
    private static array $orders = [];
    private static array $booted = [];

    final public function __construct(array $attributes = [])
    {
        if ($attributes !== []) { $this->bind($attributes); }
    }

    public function toArray(): array { return $this->jsonSerialize(); }

    /** @return Query<static> */
    public static function Query(\Light\Linq\Expression\ValueExpression|array $where = []): Query
    {
        $query = new Query(Database::getDefault(), static::class);
        return $where === [] ? $query : $query->where($where);
    }

    public static function Get(int|string|array|null $key): ?static
    {
        if ($key === null) { return null; }
        $query = static::Query();
        if (!is_array($key)) {
            $keys = Database::getDefault()->metadata(static::class)->keys;
            if (count($keys) !== 1) { throw new \InvalidArgumentException('Composite keys require a named condition map.'); }
            $key = [$keys[0] => $key];
        }
        if ($key === []) { throw new \InvalidArgumentException('Get() requires a key or non-empty condition map.'); }
        return $query->where($key)->first();
    }

    public static function Create(array $attributes = []): static
    {
        $model = new static();
        $database = Database::getDefault();
        $model->attachRecord($database, $database->metadata(static::class), false);
        $model->bind($attributes);
        return $model;
    }

    public function bind(array $attributes): static
    {
        $this->getMapping()->applyValues($this, $attributes);
        return $this;
    }

    /** @internal Query attaches metadata after light-entity directly hydrates this Model class. */
    public function attachRecord(Database $database, EntityMapping $mapping, bool $persisted = true, bool $readOnly = false): void
    {
        $this->owner = $database; $this->mapping = $mapping; $this->persisted = $persisted;
        $this->readOnly = $readOnly;
        $this->original = $persisted && !$readOnly ? $mapping->snapshotEntity($this, fromProvider: true) : [];
    }

    private function getMapping(): EntityMapping
    {
        $this->owner ??= Database::getDefault();
        return $this->mapping ??= $this->owner->metadata(static::class);
    }

    public function save(): int
    {
        $this->assertWritable();
        if ($this->deleted) { throw new \LogicException('A deleted Model cannot be saved again.'); }
        $mapping = $this->getMapping();
        $context = $this->owner->writeContext($mapping);
        try {
            if ($this->persisted) { $context->attach($this, $this->original); }
            else { $context->add(static::class, $this); }
            $count = $context->saveChanges();
        } finally {
            $context->entry($this)->state = EntityState::Detached;
            $this->attachRelations($context, $mapping, \Light\Entity\QueryTrackingBehavior::NoTracking);
        }
        $this->persisted = true;
        $this->original = $mapping->snapshotEntity($this);
        return $count;
    }

    public function delete(): int
    {
        $this->assertWritable();
        if (!$this->persisted || $this->deleted) { return 0; }
        $mapping = $this->getMapping();
        $context = $this->owner->writeContext($mapping);
        try {
            $context->attach($this, $this->original);
            $context->remove(static::class, $this);
            $count = $context->saveChanges();
        } finally {
            $context->entry($this)->state = EntityState::Detached;
            $this->attachRelations($context, $mapping, \Light\Entity\QueryTrackingBehavior::NoTracking);
        }
        $this->persisted = false; $this->deleted = true;
        return $count;
    }

    private function assertWritable(): void
    {
        if ($this->readOnly) { throw new \LogicException('A read-only Model cannot be saved or deleted.'); }
    }
    public function isReadOnly(): bool { return $this->readOnly; }
    public function exists(): bool { return $this->persisted && !$this->deleted; }
    public function isDirty(?string $property = null): bool
    {
        if ($this->readOnly) { throw new \LogicException('Read-only Models have no original snapshot for change detection.'); }
        $mapping = $this->getMapping(); $current = $mapping->values($this);
        if ($property !== null) {
            if (!array_key_exists($property, $current)) { throw new \InvalidArgumentException('Unknown property: '.$property); }
            return !$mapping->propertyEquals($property, $current[$property], $this->original[$property] ?? null);
        }
        if ($this->persisted) { return !$mapping->valuesEqual($current, $this->original); }
        if ($this->storedValues() !== []) { return true; }
        foreach ($current as $value) { if ($value !== null) { return true; } }
        return false;
    }

    public static function _key(): string|array
    {
        $keys = Database::getDefault()->metadata(static::class)->keys;
        return count($keys) === 1 ? $keys[0] : $keys;
    }
    public function _id(): int|string|array|null
    {
        $mapping = $this->getMapping();
        if (count($mapping->keys) === 1) { return $mapping->values($this)[$mapping->keys[0]]; }
        return array_intersect_key($mapping->values($this), array_flip($mapping->keys));
    }

    public static function valueConverters(): array { return []; }
    public static function valueComparers(): array { return []; }
    protected static function filterDefinitions(): array { return []; }
    protected static function orderDefinitions(): array { return []; }
    public static function RegisterFilter(string $name, callable $callback): void { self::$filters[static::class][$name] = $callback; }
    public static function RegisterOrder(string $name, callable $callback): void { self::$orders[static::class][$name] = $callback; }

    /** @internal */
    public static function registered(string $kind, string $name): ?callable
    {
        if (!isset(self::$booted[static::class])) {
            foreach (static::filterDefinitions() as $key => $callback) { static::RegisterFilter($key, $callback); }
            foreach (static::orderDefinitions() as $key => $callback) { static::RegisterOrder($key, $callback); }
            self::$booted[static::class] = true;
        }
        $registry = $kind === 'filter' ? self::$filters : self::$orders;
        for ($class = static::class; $class; $class = get_parent_class($class)) {
            if (isset($registry[$class][$name])) { return $registry[$class][$name]; }
        }
        return null;
    }
}
