<?php

declare(strict_types=1);

namespace Light\Db;

use Light\Db\Internal\FilterBuilder;
use Light\Linq\Expressions\ValueExpression;

/** Structured filter operators for a scalar expression, including SQL functions. */
final class Filter
{
    public static function expression(ValueExpression $expression, mixed $condition): ValueExpression
    {
        return FilterBuilder::expression($expression, $condition);
    }
}
