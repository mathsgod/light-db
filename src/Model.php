<?php

declare(strict_types=1);

namespace Light\Db;

use Light\Entity\{Entity, EntityMapping, EntityState};

/** Convention-based Active Record; hydration and persistence are provided by light-entity. */
abstract class Model extends Entity
{
    private array $attributes = [];
    private ?Database $owner = null;
    private ?EntityMapping $mapping = null;
    private array $original = [];
    private bool $persisted = false;
    private bool $deleted = false;
    private static array $filters = [];
    private static array $orders = [];
    private static array $booted = [];

    final public function __construct(array $attributes = [])
    {
        foreach ($attributes as $name => $value) { $this->assignAttribute($name, $value); }
    }

    private function assignAttribute(string $name, mixed $value): void
    {
        $reflection = new \ReflectionObject($this);
        if ($reflection->hasProperty($name) && $reflection->getProperty($name)->isPublic()) {
            $reflection->getProperty($name)->setValue($this, $value);
        } else {
            $this->__set($name, $value);
        }
    }

    public function &__get(string $name): mixed
    {
        if (!array_key_exists($name, $this->attributes)) { $this->attributes[$name] = null; }
        return $this->attributes[$name];
    }
    public function __set(string $name, mixed $value): void { $this->attributes[$name] = $value; }
    public function __isset(string $name): bool { return isset($this->attributes[$name]); }
    public function hasValue(string $name): bool { return array_key_exists($name, $this->attributes); }
    public function jsonSerialize(): array { return array_replace($this->attributes, parent::jsonSerialize()); }
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
        $mapping = $this->getMapping();
        foreach ($attributes as $name => $value) {
            if (!in_array($name, $mapping->properties, true)) { throw new \InvalidArgumentException('Unknown property: '.$name); }
        }
        foreach ($attributes as $name => $value) { $this->assignAttribute($name, $value); }
        return $this;
    }

    /** @internal Query attaches metadata after light-entity directly hydrates this Model class. */
    public function attachRecord(Database $database, EntityMapping $mapping, bool $persisted = true): void
    {
        $this->owner = $database; $this->mapping = $mapping; $this->persisted = $persisted;
        $this->original = $persisted ? $mapping->snapshotValues($mapping->values($this)) : [];
    }

    private function getMapping(): EntityMapping
    {
        $this->owner ??= Database::getDefault();
        return $this->mapping ??= $this->owner->metadata(static::class);
    }

    /** A private working copy gives the native tracker an original baseline without mutating this Model. */
    private function prepareWrite(): array
    {
        $mapping = $this->getMapping();
        $context = $this->owner->writeContext($mapping);
        $copy = clone $this;
        if ($this->persisted) {
            $current = $mapping->snapshotValues($mapping->values($this));
            foreach ($mapping->snapshotValues($this->original) as $name => $value) { $copy->assignAttribute($name, $value); }
            $context->entry($copy)->state = EntityState::Unchanged;
            foreach ($current as $name => $value) { $copy->assignAttribute($name, $value); }
        }
        return [$context, $copy, $mapping];
    }

    public function save(): int
    {
        if ($this->deleted) { throw new \LogicException('A deleted Model cannot be saved again.'); }
        [$context, $copy, $mapping] = $this->prepareWrite();
        try {
            if (!$this->persisted) { $context->add(static::class, $copy); }
            $count = $context->saveChanges();
        } finally {
            $context->entry($copy)->state = EntityState::Detached;
        }
        foreach ($mapping->values($copy) as $name => $value) { $this->assignAttribute($name, $value); }
        $this->persisted = true;
        $this->original = $mapping->snapshotValues($mapping->values($this));
        return $count;
    }

    public function delete(): int
    {
        if (!$this->persisted || $this->deleted) { return 0; }
        [$context, $copy] = $this->prepareWrite();
        try {
            $context->remove(static::class, $copy);
            $count = $context->saveChanges();
        } finally {
            $context->entry($copy)->state = EntityState::Detached;
        }
        $this->persisted = false; $this->deleted = true;
        return $count;
    }

    public function exists(): bool { return $this->persisted && !$this->deleted; }
    public function isDirty(?string $property = null): bool
    {
        $mapping = $this->getMapping(); $current = $mapping->values($this);
        if ($property !== null) {
            if (!array_key_exists($property, $current)) { throw new \InvalidArgumentException('Unknown property: '.$property); }
            return !$mapping->propertyEquals($property, $current[$property], $this->original[$property] ?? null);
        }
        if ($this->persisted) { return !$mapping->valuesEqual($current, $this->original); }
        if ($this->attributes !== []) { return true; }
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
