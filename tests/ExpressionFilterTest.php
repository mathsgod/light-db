<?php

declare(strict_types=1);

namespace Light\Db\Tests;

use Light\Db\{Filter, Model};
use Light\Entity\Sql\{LikeExpression, RawSqlValueExpression};
use Light\Linq\Expr;
use PHPUnit\Framework\Attributes\DataProvider;

class ExpressionFilterUser extends Model
{
    protected static $_table = 'User';

    protected static function filterDefinitions(): array
    {
        return ['computedAge' => static fn ($value) => Filter::expression(
            new RawSqlValueExpression('ABS(age) + ?', [5]),
            $value,
        )];
    }
}

final class ExpressionFilterTest extends DatabaseTestCase
{
    public static function numericConditions(): iterable
    {
        yield 'scalar equality' => [35, [1]];
        yield 'eq' => [['eq' => 25], [2]];
        yield 'underscore operator' => [['_eq' => 15], [3]];
        yield 'neq' => [['neq' => 25], [1, 3]];
        yield 'ne alias' => [['ne' => 25], [1, 3]];
        yield 'gt' => [['gt' => 25], [1]];
        yield 'gte' => [['gte' => 25], [1, 2]];
        yield 'lt' => [['lt' => 25], [3]];
        yield 'lte' => [['lte' => 25], [2, 3]];
        yield 'implicit IN' => [[35, 15], [1, 3]];
        yield 'explicit IN' => [['in' => [35, 15]], [1, 3]];
        yield 'not IN' => [['notIn' => [35, 15]], [2]];
        yield 'empty array' => [[], []];
        yield 'empty IN' => [['in' => []], []];
        yield 'empty not IN' => [['notIn' => []], [1, 2, 3]];
        yield 'between' => [['between' => [25, 35]], [1, 2]];
        yield 'not between' => [['notBetween' => [25, 35]], [3]];
        yield 'combined operators' => [['gte' => 15, 'lt' => 35], [2, 3]];
    }

    #[DataProvider('numericConditions')]
    public function testRawFunctionOperatorsMatchExpectedRows(mixed $condition, array $expected): void
    {
        $query = ExpressionFilterUser::Query()->where(Filter::expression(
            new RawSqlValueExpression('ABS(age) + :offset', ['offset' => 5]),
            $condition,
        ))->orderBy('id');
        self::assertSame($expected, $query->select('id')->toArray());
        self::assertSame(count($expected), $query->count());
    }

    public static function nullableConditions(): iterable
    {
        yield 'scalar null' => [null, [2]];
        yield 'eq null' => [['eq' => null], [2]];
        yield 'neq null' => [['neq' => null], [1, 3]];
        yield 'null' => [['null' => true], [2]];
        yield 'null false' => [['null' => false], [1, 3]];
        yield 'not null' => [['notNull' => true], [1, 3]];
        yield 'not null false' => [['notNull' => false], [2]];
    }

    #[DataProvider('nullableConditions')]
    public function testNullTestsApplyToExpressionResults(mixed $condition, array $expected): void
    {
        $predicate = Filter::expression(new RawSqlValueExpression('NULLIF(age, ?)', [20]), $condition);
        self::assertSame($expected, ExpressionFilterUser::Query()->where($predicate)->orderBy('id')->select('id')->toArray());
    }

    public function testStringOperatorsEscapeWildcardsAndBindSearchValues(): void
    {
        $expression = new RawSqlValueExpression('LOWER(name)');
        foreach (['contains' => 'd', 'startsWith' => 'ad', 'endsWith' => 'da', 'like' => 'a%'] as $operator => $value) {
            self::assertSame([1], ExpressionFilterUser::Query()->where(Filter::expression($expression, [$operator => $value]))->select('id')->toArray());
        }
        $this->pdo->prepare('INSERT INTO User(name, age) VALUES (?, ?)')->execute(['%_!suffix', 40]);
        $this->pdo->prepare('INSERT INTO User(name, age) VALUES (?, ?)')->execute(['prefix%_!', 40]);
        foreach (['contains' => [4, 5], 'startsWith' => [4], 'endsWith' => [5]] as $operator => $expected) {
            self::assertSame($expected, ExpressionFilterUser::Query()->where(Filter::expression($expression, [$operator => '%_!']))->orderBy('id')->select('id')->toArray());
        }
        self::assertSame(0, ExpressionFilterUser::Query()->where(Filter::expression($expression, ['contains' => "' OR 1=1 --"]))->count());
        self::assertSame(0, ExpressionFilterUser::Query()->where(Filter::expression($expression, "' OR 1=1 --"))->count());
    }

    public function testExpressionAndOperatorParametersAreBoundInSqlOrder(): void
    {
        $predicate = Filter::expression(new RawSqlValueExpression('age + :offset', ['offset' => 5]), ['gte' => 25, 'lt' => 35]);
        $query = ExpressionFilterUser::Query()->where($predicate)->select('id');
        $compiled = $query->provider()->compile($query->expression());
        self::assertSame([5, 25, 5, 35], array_values($compiled->parameters));
        self::assertSame([2], $query->toArray());

        $predicate = Filter::expression(new RawSqlValueExpression('COALESCE(name, ?)', ['fallback']), ['contains' => 'd']);
        self::assertInstanceOf(LikeExpression::class, $predicate);
        $query = ExpressionFilterUser::Query()->where($predicate)->select('id');
        $compiled = $query->provider()->compile($query->expression());
        self::assertSame(['fallback', '%d%', '!'], array_values($compiled->parameters));
        self::assertSame([1], $query->toArray());
    }

    public function testRegisteredFiltersComposeWithFieldsPaginationAndBulkWrites(): void
    {
        $root = ExpressionFilterUser::Query();
        $filtered = $root->filters(['computedAge' => ['gte' => 25]])->where(['name' => ['neq' => 'Ada']]);
        self::assertSame([2], $filtered->limit(1)->select('id')->toArray());
        self::assertSame(3, $root->count());
        self::assertSame(1, $filtered->update(['age' => 21]));
        self::assertSame(21, ExpressionFilterUser::Get(2)->age);
    }

    public function testNativeExpressionsCanBeComposedWithoutRawSql(): void
    {
        $predicate = Expr::both(
            Filter::expression(Expr::field('age'), ['gte' => 20]),
            Expr::not(Filter::expression(Expr::field('name'), ['contains' => 'd'])),
        );
        self::assertSame([2], ExpressionFilterUser::Query()->where($predicate)->select('id')->toArray());
    }

    public static function invalidConditions(): iterable
    {
        yield 'unknown operator' => [['unsupported' => 1]];
        yield 'scalar IN' => [['in' => 1]];
        yield 'associative IN' => [['in' => ['id' => 1]]];
        yield 'scalar not IN' => [['notIn' => '1']];
        yield 'scalar between' => [['between' => 1]];
        yield 'short between' => [['between' => [1]]];
        yield 'long between' => [['between' => [1, 2, 3]]];
        yield 'associative between' => [['between' => ['first' => 1, 'last' => 2]]];
        yield 'short not between' => [['notBetween' => [1]]];
        yield 'invalid null' => [['null' => 1]];
        yield 'invalid not null' => [['notNull' => null]];
        yield 'ordered null' => [['gt' => null]];
        yield 'non-string contains' => [['contains' => 123]];
        yield 'non-string like' => [['like' => false]];
    }

    #[DataProvider('invalidConditions')]
    public function testMalformedConditionsAreRejected(mixed $condition): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Filter::expression(new RawSqlValueExpression('ABS(age)'), $condition);
    }
}
