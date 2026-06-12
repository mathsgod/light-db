<?php

declare(strict_types=1);

use Light\Db\Model;
use Light\Db\Query;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Model::boot() lifecycle and filterDefinitions() / orderDefinitions()
 * override hooks. Verifies that subclasses can self-declare their filters
 * and sorts, that boot is idempotent, that inheritance works through
 * parent::filterDefinitions(), and that the legacy RegisterFilter /
 * RegisterOrder APIs still work (backward compat).
 *
 * These tests do not touch the database. They introspect Query's private
 * $Filters / $Order registries via reflection and Model's private $booted
 * cache. The registries are reset in setUp() so tests are order-independent.
 */
final class ModelBootTest extends TestCase
{
    /** @var array<string, class-string> */
    private static array $evalCache = [];

    protected function setUp(): void
    {
        $this->resetRegistry();
    }

    protected function tearDown(): void
    {
        $this->resetRegistry();
    }

    public function testBootIsIdempotent(): void
    {
        $closure = fn() => null;
        $cls = $this->makeFilterClass('ModelBootIdempotent', ['foo' => $closure]);

        $cls::boot();
        $cls::boot();
        $cls::boot();

        $this->assertCount(1, $this->getFilters($cls));
        $this->assertArrayHasKey('foo', $this->getFilters($cls));
    }

    public function testFilterDefinitionsAreRegisteredOnBoot(): void
    {
        $foo = fn($v) => "foo = $v";
        $bar = fn($v) => "bar = $v";
        $cls = $this->makeFilterClass('ModelBootFilterReg', ['foo' => $foo, 'bar' => $bar]);

        $this->assertSame([], $this->getFilters($cls));

        $cls::boot();

        $r = $this->getFilters($cls);
        $this->assertCount(2, $r);
        $this->assertArrayHasKey('foo', $r);
        $this->assertArrayHasKey('bar', $r);
    }

    public function testOrderDefinitionsAreRegisteredOnBoot(): void
    {
        $rank = fn($d) => "rank $d";
        $cls = $this->makeOrderClass('ModelBootOrderReg', ['rank' => $rank]);

        $cls::boot();

        $r = $this->getOrder($cls);
        $this->assertCount(1, $r);
        $this->assertArrayHasKey('rank', $r);
    }

    public function testSubclassInheritsParentFiltersViaParentCall(): void
    {
        $fromParent = fn() => 'parent';
        $fromChild = fn() => 'child';

        $parent = $this->makeFilterClass('ModelBootParent', ['from_parent' => $fromParent]);
        $child = $this->makeSubclass(
            'ModelBootChildInherit',
            $parent,
            ['from_parent' => $fromParent, 'from_child' => $fromChild]
        );

        $child::boot();

        $r = $this->getFilters($child);
        $this->assertArrayHasKey('from_parent', $r);
        $this->assertArrayHasKey('from_child', $r);
        $this->assertCount(2, $r);
    }

    public function testSubclassCanBorrowFilterFromSibling(): void
    {
        $shared = fn() => 'source';
        $other = $this->makeFilterClass('ModelBootBorrowSource', ['shared' => $shared]);
        $borrowing = $this->makeFilterClass('ModelBootBorrowTarget', ['shared' => $shared]);

        $borrowing::boot();

        $this->assertArrayHasKey('shared', $this->getFilters($borrowing));
    }

    public function testModelWithoutOverrideRegistersNothing(): void
    {
        $cls = $this->makeBareClass('ModelBootBare');

        $cls::boot();

        $this->assertSame([], $this->getFilters($cls));
        $this->assertSame([], $this->getOrder($cls));
    }

    public function testLegacyRegisterFilterStillWorks(): void
    {
        $cls = $this->makeBareClass('ModelBootLegacy');
        $legacy = fn() => 'x';

        $cls::RegisterFilter('legacy', $legacy);

        $this->assertArrayHasKey('legacy', $this->getFilters($cls));
    }

    public function testLegacyRegisterOrderStillWorks(): void
    {
        $cls = $this->makeBareClass('ModelBootLegacyOrder');
        $legacy = fn($d) => "ord $d";

        $cls::RegisterOrder('legacy', $legacy);

        $this->assertArrayHasKey('legacy', $this->getOrder($cls));
    }

    public function testFilterCallbackProducesExpectedSqlFragment(): void
    {
        $computed = fn($v) => "name = '$v'";
        $cls = $this->makeFilterClass('ModelBootSqlFragment', ['computed' => $computed]);

        $r = (new ReflectionClass($cls))->getMethod('filterDefinitions');
        $r->setAccessible(true);
        $defs = $r->invoke(null);

        $this->assertArrayHasKey('computed', $defs);
        $this->assertSame("name = 'hello'", ($defs['computed'])('hello'));
    }

    public function testOverridingParentFilterReplacesIt(): void
    {
        $parentV = fn() => 'parent_version';
        $childV = fn() => 'child_version';

        $parent = $this->makeFilterClass('ModelBootOverrideParent', ['shared' => $parentV]);
        $child = $this->makeSubclass('ModelBootOverrideChild', $parent, ['shared' => $childV]);

        $child::boot();

        $r = $this->getFilters($child);
        $this->assertCount(1, $r);
        $this->assertSame('child_version', ($r['shared'])());
    }

    public function testBootOnModelClassMarksItBooted(): void
    {
        Model::boot();

        $prop = (new ReflectionClass(Model::class))->getProperty('booted');
        $prop->setAccessible(true);
        $this->assertArrayHasKey(Model::class, $prop->getValue());
    }

    public function testQueryConstructionTriggersBoot(): void
    {
        $cls = $this->makeFilterClass('ModelBootQueryTrigger', ['lazy' => fn() => 'lazy']);

        $this->assertSame([], $this->getFilters($cls));
        $cls::boot();
        $this->assertArrayHasKey('lazy', $this->getFilters($cls));
    }

    private function resetRegistry(): void
    {
        $modelRef = new ReflectionClass(Model::class);
        if ($modelRef->hasProperty('booted')) {
            $prop = $modelRef->getProperty('booted');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }

        $queryRef = new ReflectionClass(Query::class);
        foreach (['Filters', 'Order'] as $name) {
            $p = $queryRef->getProperty($name);
            $p->setAccessible(true);
            $p->setValue(null, []);
        }
    }

    /**
     * @return array<string, callable>
     */
    private function getFilters(string $class): array
    {
        $prop = (new ReflectionClass(Query::class))->getProperty('Filters');
        $prop->setAccessible(true);
        $all = $prop->getValue();
        return $all[$class] ?? [];
    }

    /**
     * @return array<string, callable>
     */
    private function getOrder(string $class): array
    {
        $prop = (new ReflectionClass(Query::class))->getProperty('Order');
        $prop->setAccessible(true);
        $all = $prop->getValue();
        return $all[$class] ?? [];
    }

    /**
     * Render a closure as a PHP expression usable inside an evaluated
     * class body. We extract the source code lines of the closure via
     * ReflectionFunction and re-emit them. Closures must be static (no
     * $this binding) and arrow-style or named-function-style; this is a
     * test-only constraint, not a Model::boot() constraint.
     */
    private static function exportCallable(callable $cb): string
    {
        if (!($cb instanceof Closure)) {
            return var_export($cb, true);
        }
        $r = new ReflectionFunction($cb);
        $start = $r->getStartLine();
        $end = $r->getEndLine();
        $file = $r->getFileName();
        $src = file($file);
        $body = '';
        for ($i = $start - 1; $i < $end; $i++) {
            $body .= $src[$i];
        }
        if (preg_match('/^(.*?)(\bfn\s*\([^)]*\)\s*=>\s*[^\n;,})\]]+)/s', $body, $m)) {
            return $m[2];
        }
        if (preg_match('/^(.*?)((?:static\s+)?\bfunction\s*\([^)]*\)\s*(?::\s*\S+\s*)?\{)/s', $body, $m)) {
            $out = '';
            for ($i = $start - 1; $i < $end; $i++) {
                $out .= $src[$i];
            }
            if (preg_match('/((?:static\s+)?\bfunction\s*\([^)]*\)\s*(?::\s*\S+\s*)?\{.*\})\s*$/s', $out, $m2)) {
                return $m2[1];
            }
        }
        throw new LogicException('Failed to extract closure source from: ' . $body);
    }

    /**
     * @param array<string, callable> $arr
     */
    private static function exportArray(array $arr): string
    {
        $parts = [];
        foreach ($arr as $k => $v) {
            $parts[] = var_export((string) $k, true) . ' => ' . self::exportCallable($v);
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * @param array<string, callable> $filters
     */
    private function makeFilterClass(string $name, array $filters): string
    {
        return $this->evalClass($name, sprintf(
            'abstract class %s extends \\Light\\Db\\Model { protected static function filterDefinitions(): array { return %s; } }',
            $name,
            self::exportArray($filters)
        ));
    }

    /**
     * @param array<string, callable> $orders
     */
    private function makeOrderClass(string $name, array $orders): string
    {
        return $this->evalClass($name, sprintf(
            'abstract class %s extends \\Light\\Db\\Model { protected static function orderDefinitions(): array { return %s; } }',
            $name,
            self::exportArray($orders)
        ));
    }

    private function makeBareClass(string $name): string
    {
        return $this->evalClass($name, sprintf(
            'abstract class %s extends \\Light\\Db\\Model {}',
            $name
        ));
    }

    /**
     * @param array<string, callable> $filters
     */
    private function makeSubclass(string $name, string $parentClass, array $filters): string
    {
        return $this->evalClass($name, sprintf(
            'abstract class %s extends %s { protected static function filterDefinitions(): array { return %s; } }',
            $name,
            $parentClass,
            self::exportArray($filters)
        ));
    }

    private function evalClass(string $name, string $code): string
    {
        if (isset(self::$evalCache[$name])) {
            return self::$evalCache[$name];
        }
        eval($code);
        if (!class_exists($name, false)) {
            throw new RuntimeException("Class $name not declared by eval");
        }
        self::$evalCache[$name] = $name;
        return $name;
    }
}
