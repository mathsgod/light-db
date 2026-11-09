<?php

declare(strict_types=1);

namespace Light\Db\Internal;

use Light\Entity\Sql\{LikeExpression, NotExpression};
use Light\Linq\Expr;
use Light\Linq\Expressions\ValueExpression;

/** Shared operator translation for mapped fields and explicit expressions. @internal */
final class FilterBuilder
{
    /**
     * Comparisons retain native mapping conversion through their field expressions.
     * LIKE patterns need explicit mapping conversion, once, after wildcard escaping.
     *
     * @param null|callable(string): mixed $convertLikePattern
     */
    public static function expression(
        ValueExpression $expression,
        mixed $condition,
        ?callable $convertLikePattern = null,
    ): ValueExpression {
        $conditions = is_array($condition)
            ? (array_is_list($condition) ? ['in' => $condition] : $condition)
            : ['eq' => $condition];
        $parts = [];
        foreach ($conditions as $operator => $value) {
            $operator = ltrim((string) $operator, '_');
            $operator = $operator === 'ne' ? 'neq' : $operator;
            if (in_array($operator, ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'], true)) {
                if ($value === null && !in_array($operator, ['eq', 'neq'], true)) {
                    throw new \InvalidArgumentException('Only equality accepts null.');
                }
                $parts[] = Expr::$operator($expression, $value);
            } elseif (in_array($operator, ['in', 'notIn'], true)) {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new \InvalidArgumentException('IN requires a value list.');
                }
                $part = self::combine(array_map(fn ($v) => Expr::eq($expression, $v), $value), false);
                $parts[] = $operator === 'in' ? $part : new NotExpression($part);
            } elseif (in_array($operator, ['between', 'notBetween'], true)) {
                if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
                    throw new \InvalidArgumentException('Between requires two values.');
                }
                $part = Expr::both(Expr::gte($expression, $value[0]), Expr::lte($expression, $value[1]));
                $parts[] = $operator === 'between' ? $part : new NotExpression($part);
            } elseif (in_array($operator, ['null', 'notNull'], true)) {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('Null tests require a boolean.');
                }
                $parts[] = ($operator === 'null') === $value ? Expr::eq($expression, null) : Expr::neq($expression, null);
            } elseif (in_array($operator, ['like', 'contains', 'startsWith', 'endsWith'], true)) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('String filters require a string.');
                }
                $pattern = $operator === 'like' ? $value : str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
                if (in_array($operator, ['contains', 'endsWith'], true)) $pattern = '%' . $pattern;
                if (in_array($operator, ['contains', 'startsWith'], true)) $pattern .= '%';
                $parts[] = new LikeExpression(
                    $expression,
                    Expr::value($convertLikePattern === null ? $pattern : $convertLikePattern($pattern)),
                    '!',
                );
            } else {
                throw new \InvalidArgumentException('Unknown filter operator: ' . $operator);
            }
        }
        return self::combine($parts, true);
    }

    /** @param list<ValueExpression> $parts */
    public static function combine(array $parts, bool $and): ValueExpression
    {
        $result = array_shift($parts) ?? Expr::eq(Expr::value(1), $and ? 1 : 0);
        foreach ($parts as $part) {
            $result = $and ? Expr::both($result, $part) : Expr::either($result, $part);
        }
        return $result;
    }
}
