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
    public static function Query(\Light\Linq\Expressions\ValueExpression|array $where = []): Query
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

    public function bind(array|object $attributes): static
    {
        if (is_object($attributes)) { $attributes = (array) $attributes; }
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

    /** Database bound to this instance, including after changing the default. */
    protected function database(): Database
    {
        $this->getMapping();
        return $this->owner ?? throw new \LogicException('Model database was not initialized.');
    }

    public function save(): int
    {
        $this->assertWritable();
        if ($this->deleted) { throw new \LogicException('A deleted Model cannot be saved again.'); }
        $mapping = $this->getMapping();
        $context = $this->owner->writeContext($mapping);
        try {
            $this->stageWrite($context, false);
            $count = $context->saveChanges();
        } finally {
            $this->owner->releaseWriteContext($context, $this, $mapping);
        }
        $this->persisted = true;
        $this->original = $mapping->snapshotEntity($this);
        $this->owner->notifyPersistence($this, false);
        return $count;
    }

    public function delete(): int
    {
        $this->assertWritable();
        if ($this->deleted) { return 0; }
        $mapping = $this->getMapping();
        if (!$this->persisted) { return 0; }
        $context = $this->owner->writeContext($mapping);
        try {
            $this->stageWrite($context, true);
            $count = $context->saveChanges();
        } finally {
            $this->owner->releaseWriteContext($context, $this, $mapping);
        }
        $this->persisted = false; $this->deleted = true;
        $this->owner->notifyPersistence($this, true);
        return $count;
    }

    /** Register this Model using its original model-value snapshot. */
    private function stageWrite(\Light\Entity\DbContext $context, bool $delete): void
    {
        $this->assertWritable();
        // Supply the exact Active Record mapping even when a new Model class is
        // first encountered midway through a shared immediate transaction.
        $mapping = $this->getMapping();
        if ($this->persisted) {
            $context->changeTracker->attach($mapping, $this, $this->original);
            if ($delete) { $context->changeTracker->setState($mapping, $this, EntityState::Deleted); }
        } elseif (!$delete) {
            $context->changeTracker->add($mapping, $this);
        }
    }

    /** @internal Invoke hooks after persistence, or after the outer transaction commits. */
    final public function notifyPersistence(bool $deleted): void
    {
        $this->afterPersistence($deleted);
    }

    /** Extension hook after a successful persistence commit. */
    protected function afterPersistence(bool $deleted): void {}

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

    /** Named predicates applied automatically to every query for this Model. */
    protected static function queryFilterDefinitions(): array { return []; }

    /** @internal */
    final public static function configureEntity(\Light\Entity\EntityTypeBuilder $builder): void
    {
        foreach (static::queryFilterDefinitions() as $name => $filter) {
            $builder->hasQueryFilter($name, $filter);
        }
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
