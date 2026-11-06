<?php

declare(strict_types=1);

namespace Light\Db;

use Light\Entity\{DbSet, EntityMapping};
use Light\Entity\Sql\{LikeExpression, NotExpression};
use Light\Linq\{Enumerable, Expr, IEnumerable};
use Light\Linq\Expressions\ValueExpression;

/** @template T of Model @implements \IteratorAggregate<int,T> */
final class Query implements \IteratorAggregate, \Countable
{
    private DbSet $set;
    private EntityMapping $mapping;
    private ?int $limit = null;
    private int $offset = 0;
    private bool $ordered = false;
    private bool $readOnly = false;
    private ?array $ignoredFilters = [];

    /** @param class-string<T> $class */
    public function __construct(private readonly Database $database, private readonly string $class)
    {
        $this->mapping = $database->metadata($class);
        $this->set = $database->set($class);
    }

    /** Skip Model snapshots and prohibit persistence through this query and its results. @return self<T> */
    public function asReadOnly(): self
    {
        $copy = clone $this; $copy->readOnly = true; return $copy;
    }

    /** Disable all global query filters, or only the supplied names. @return self<T> */
    public function ignoreQueryFilters(?array $names = null): self
    {
        $copy = clone $this;
        $copy->set = $this->set->ignoreQueryFilters($names);
        $copy->ignoredFilters = $names === null || $this->ignoredFilters === null ? null : array_values(array_unique([...$this->ignoredFilters, ...$names]));
        return $copy;
    }

    public function getClassName(): string { return $this->class; }
    /** @return self<T> */
    public function where(ValueExpression|array|\Closure $condition): self
    {
        $copy = clone $this;
        $copy->set = $this->set->where(is_array($condition) ? $this->expression($condition) : $condition);
        return $copy;
    }
    /** @return self<T> */
    public function whereRaw(string $sql, array $parameters = []): self
    {
        $copy = clone $this; $copy->set = $this->set->whereRaw($sql, $parameters); return $copy;
    }

    /** @return self<T> */
    public function whereIn(string $field, array|\Light\Linq\IQueryable $values): self
    {
        if (is_array($values)) { return $this->where([$field => ['in' => $values]]); }
        $copy = clone $this;
        $copy->set = $this->set->whereIn($this->field($field), $values);
        return $copy;
    }

    /** @return self<T> */
    public function whereNotIn(string $field, array|\Light\Linq\IQueryable $values): self
    {
        if (is_array($values)) { return $this->where([$field => ['notIn' => $values]]); }
        $copy = clone $this;
        $copy->set = $this->set->whereNotIn($this->field($field), $values);
        return $copy;
    }

    /** @return self<T> */
    public function filters(?array $filters): self
    {
        $query = $this;
        if ($filters !== null && array_is_list($filters)) {
            foreach ($filters as $group) {
                if (!is_array($group)) { throw new \InvalidArgumentException('Filter lists require condition maps.'); }
                $query = $query->filters($group);
            }
            return $query;
        }
        foreach ($filters ?? [] as $name => $value) {
            $callback = $this->class::registered('filter', (string)$name);
            if ($callback === null) { $query = $query->where([$name=>$value]); continue; }
            $result = $callback($value, $query);
            $query = match (true) {
                $result instanceof self => $query->checkCustomQuery($result),
                $result instanceof ValueExpression || is_array($result) => $query->where($result),
                default => throw new \LogicException('Registered filters must return a Query, expression or condition map.'),
            };
        }
        return $query;
    }

    private function checkCustomQuery(self $query): self
    {
        if ($query->database !== $this->database || $query->class !== $this->class) {
            throw new \LogicException('A registered callback must return a query for the same Model and Database.');
        }
        if ($this->ignoredFilters === null || $this->ignoredFilters !== []) { $query = $query->ignoreQueryFilters($this->ignoredFilters); }
        return $this->readOnly ? $query->asReadOnly() : $query;
    }

    /** @return self<T> */
    public function orderBy(string|ValueExpression $field, bool $descending = false): self
    {
        $value = is_string($field) ? $this->field($field) : $field;
        $copy = clone $this;
        $copy->set = $this->ordered
            ? ($descending ? $this->set->thenByDescending($value) : $this->set->thenBy($value))
            : ($descending ? $this->set->orderByDescending($value) : $this->set->orderBy($value));
        $copy->ordered = true;
        return $copy;
    }

    /** @return self<T> */
    public function orderByRaw(string $sql, array $parameters = [], bool $descending = false): self
    {
        $copy = clone $this;
        $copy->set = $this->ordered
            ? $this->set->thenByRaw($sql, $parameters, $descending)
            : $this->set->orderByRaw($sql, $parameters, $descending);
        $copy->ordered = true;
        return $copy;
    }
    /** @return self<T> */
    public function order(string|array $order): self
    {
        $query = $this;
        foreach (is_array($order) ? $order : explode(',', $order) as $name => $direction) {
            $spec = is_string($name) ? $name.' '.$direction : str_replace(':', ' ', trim($direction));
            if ($spec === '') { continue; }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(asc|desc))?$/iD', $spec, $match)) {
                throw new \InvalidArgumentException('Use field:asc/desc or field ASC/DESC ordering.');
            }
            $descending = strtolower($match[2] ?? 'asc') === 'desc';
            $callback = $this->class::registered('order', $match[1]);
            if ($callback === null) { $query = $query->orderBy($match[1], $descending); continue; }
            $result = $callback($descending ? 'desc' : 'asc', $query);
            $query = $result instanceof self ? $query->checkCustomQuery($result)
                : ($result instanceof ValueExpression ? $query->orderBy($result, $descending)
                    : throw new \LogicException('Registered orders must return a Query or expression.'));
        }
        return $query;
    }
    /** @return self<T> */
    public function sort(?string $order): self { return $order === null ? $this : $this->order($order); }
    /** @return self<T> */
    public function limit(int $count): self
    {
        if ($count < 0) { throw new \InvalidArgumentException('Limit cannot be negative.'); }
        $copy = clone $this; $copy->limit = $count; return $copy;
    }
    /** @return self<T> */
    public function offset(int $count): self
    {
        if ($count < 0) { throw new \InvalidArgumentException('Offset cannot be negative.'); }
        $copy = clone $this; $copy->offset = $count; return $copy;
    }
    /** @return self<T> */
    public function take(int $count): self { return $this->limit($count); }
    /** @return self<T> */
    public function skip(int $count): self { return $this->offset($count); }
    /**
     * Project named columns, a single value expression, or a translated closure.
     * Returns an untracked query that can also be used as an IN subquery.
     */
    public function select(string|ValueExpression|\Closure ...$columns): \Light\Linq\Queryable
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('select() requires at least one column or expression.');
        }
        if (count($columns) === 1 && ($columns[0] instanceof ValueExpression || $columns[0] instanceof \Closure)) {
            return $this->page()->select($columns[0]);
        }
        $names = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException('Pass column names or a single projection expression or closure to select().');
            }
            $this->field($column);
            $names[] = $column;
        }
        $selector = count($names) === 1 ? $this->field($names[0]) : Expr::fields(...$names);
        return $this->page()->select($selector);
    }

    private function page(): DbSet
    {
        $set = $this->offset > 0 ? $this->set->skip($this->offset) : $this->set;
        return $this->limit === null ? $set : $set->take($this->limit);
    }
    /** @param T $model @return T */
    private function attach(Model $model): Model
    {
        $model->attachRecord($this->database, $this->mapping, readOnly: $this->readOnly);
        return $model;
    }
    /** @return T|null */
    public function first(): ?Model
    {
        $model = $this->page()->firstOrDefault();
        return $model === null ? null : $this->attach($model);
    }
    /** Counts matching records before pagination, suitable for GraphQL meta.total. */
    public function count(): int { return $this->set->count(); }
    public function any(): bool { return $this->page()->any(); }
    public function getIterator(): \Traversable
    {
        // Finish the database result before application code can issue nested queries/writes.
        foreach ($this->page()->toArray() as $model) { yield $this->attach($model); }
    }
    public function cursor(): \Generator
    {
        foreach ($this->page() as $model) { yield $this->attach($model); }
    }
    public function toArray(): array
    {
        $models = $this->page()->toArray();
        foreach ($models as $model) { $this->attach($model); }
        return $models;
    }
    public function asEnumerable(): IEnumerable { return Enumerable::from($this); }
    public function sum(string $field): int|float|string { return $this->set->asQueryable()->sum($this->field($field)); }
    public function avg(string $field): int|float|string|null { return $this->set->asQueryable()->average($this->field($field)); }
    public function min(string $field): mixed { return $this->set->asQueryable()->min($this->field($field)); }
    public function max(string $field): mixed { return $this->set->asQueryable()->max($this->field($field)); }
    private function validateWrite(): void
    {
        if ($this->readOnly) { throw new \LogicException('A read-only Query cannot update or delete records.'); }
        if ($this->limit !== null || $this->offset !== 0) { throw new \LogicException('Bulk writes do not support pagination.'); }
    }
    public function update(array $values): int { $this->validateWrite(); return $this->set->executeUpdate($values); }
    public function delete(): int { $this->validateWrite(); return $this->set->executeDelete(); }

    private function field(string $name): ValueExpression
    {
        if (!in_array($name, $this->mapping->properties, true)) { throw new \InvalidArgumentException('Unknown column: '.$name); }
        return Expr::field($name);
    }
    private function combine(array $parts, bool $and): ValueExpression
    {
        $result = array_shift($parts) ?? Expr::eq(Expr::value(1), $and ? 1 : 0);
        foreach ($parts as $part) { $result = $and ? Expr::both($result,$part) : Expr::either($result,$part); }
        return $result;
    }
    private function expression(array $filter): ValueExpression
    {
        $parts = [];
        foreach ($filter as $name => $condition) {
            if (in_array($name, ['_and','_or'], true)) {
                if (!is_array($condition) || !array_is_list($condition)) { throw new \InvalidArgumentException('Logical groups require a list of condition maps.'); }
                $parts[] = $this->combine(array_map(fn (array $f) => $this->expression($f), $condition), $name === '_and');
                continue;
            }
            if ($name === '_not') {
                if (!is_array($condition)) { throw new \InvalidArgumentException('_not requires a condition map.'); }
                $parts[] = new NotExpression($this->expression($condition)); continue;
            }
            $field = $this->field((string)$name);
            $conditions = is_array($condition) ? (array_is_list($condition) ? ['in'=>$condition] : $condition) : ['eq'=>$condition];
            foreach ($conditions as $operator => $value) {
                $operator = ltrim((string)$operator, '_');
                $operator = $operator === 'ne' ? 'neq' : $operator;
                if (in_array($operator,['eq','neq','gt','gte','lt','lte'], true)) {
                    if ($value === null && !in_array($operator,['eq','neq'],true)) { throw new \InvalidArgumentException('Only equality accepts null.'); }
                    $parts[] = Expr::$operator($field,$value);
                } elseif (in_array($operator,['in','notIn'],true)) {
                    if (!is_array($value) || !array_is_list($value)) { throw new \InvalidArgumentException('IN requires a value list.'); }
                    $part = $this->combine(array_map(fn ($v) => Expr::eq($field,$v),$value), false);
                    $parts[] = $operator === 'in' ? $part : new NotExpression($part);
                } elseif (in_array($operator,['between','notBetween'],true)) {
                    if (!is_array($value) || !array_is_list($value) || count($value)!==2) { throw new \InvalidArgumentException('Between requires two values.'); }
                    $part = Expr::both(Expr::gte($field,$value[0]),Expr::lte($field,$value[1]));
                    $parts[] = $operator === 'between' ? $part : new NotExpression($part);
                } elseif (in_array($operator,['null','notNull'],true)) {
                    if (!is_bool($value)) { throw new \InvalidArgumentException('Null tests require a boolean.'); }
                    $parts[] = ($operator === 'null') === $value ? Expr::eq($field,null) : Expr::neq($field,null);
                } elseif (in_array($operator,['like','contains','startsWith','endsWith'],true)) {
                    if (!is_string($value)) { throw new \InvalidArgumentException('String filters require a string.'); }
                    $pattern = $operator === 'like' ? $value : str_replace(['!','%','_'],['!!','!%','!_'],$value);
                    if (in_array($operator,['contains','endsWith'],true)) { $pattern = '%'.$pattern; }
                    if (in_array($operator,['contains','startsWith'],true)) { $pattern .= '%'; }
                    $parts[] = new LikeExpression(
                        $field,
                        Expr::value($this->mapping->toProviderValue($name, $pattern)),
                        '!',
                    );
                } else { throw new \InvalidArgumentException('Unknown filter operator: '.$operator); }
            }
        }
        return $this->combine($parts,true);
    }
}
