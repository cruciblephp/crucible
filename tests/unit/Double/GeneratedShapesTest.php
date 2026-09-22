<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Double;

use LogicException;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Double\Generator;
use LucianoPereira\Crucible\Double\TestDoubles;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\ByRef;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\Config;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\EnumDefault;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\ObjectDefaultWithArguments;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\ScalarDefaults;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\StaticMethod;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\Suit;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\Tentative;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\Variadic;
use LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes\VoidReturn;
use ReflectionMethod;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function str_contains;
use function var_export;

use const PHP_BINARY;

/**
 * The generated-code corpus.
 *
 * Everything the double generator emits exists only as a string until
 * eval() runs, and ✓ measured, no automated instrument can read it:
 * PHPStan cannot see inside a string literal, phpcpd finds no clone
 * there (D-108), and the mutation runner skips those paths outright —
 * `src/CLI/Commands/MutationCommand.php:334` drops any covered "file"
 * that `is_file()` rejects, which is exactly what an eval()'d
 * pseudo-path is. So a behavioural test over a corpus of SHAPES is the
 * only instrument left, and the shapes come from the branch list in
 * Generator::methodCode()/parameterCode() rather than from examples.
 *
 * The generation axis runs in a SUBPROCESS. One shape — an object
 * parameter default — is not a catchable failure: ✓ measured,
 * `try { eval(...) } catch (Throwable)` does not catch "Constant
 * expression contains invalid operations"; the process dies. A test
 * that observed it in-process would take the suite with it.
 */
#[CoversClass(Generator::class)]
final class GeneratedShapesTest extends TestCase
{
    private const string FIXTURES = '/tests/_fixtures/double-shapes/shapes.php';

    protected function setUp(): void
    {
        // The corpus is not autoloaded: `tests/_fixtures` is outside the
        // map on purpose, and one shape in it cannot be loaded casually
        // — generating a double for it kills the process.
        require_once __DIR__ . '/../../_fixtures/double-shapes/shapes.php';
    }

    /**
     * Every shape, and whether generating a double for it survives.
     *
     * The table is the record: a shape that starts or stops surviving
     * moves a row here, which is the change worth noticing.
     */
    public function testEveryShapeGeneratesOrIsRecordedAsNotGenerating(): void
    {
        $survives = [];

        foreach ([
            'ByRef', 'EnumDefault', 'NeverReturn', 'ObjectDefault', 'ObjectDefaultWithArguments',
            'ScalarDefaults', 'SelfReturn', 'StaticMethod', 'Tentative', 'UnionType', 'Variadic', 'VoidReturn',
        ] as $shape) {
            $survives[$shape] = $this->generatesInASubprocess($shape);
        }

        // Every shape generates. ObjectDefault is the one that did not:
        // parameterCode() inlined the default with var_export(), which
        // renders an object as `\Config::__set_state(...)` — not a
        // constant expression — and the result was an UNCATCHABLE fatal
        // naming neither the doubled type nor the reason. The
        // initializer PHP itself renders is emitted now.
        self::assertSame([
            'ByRef'                      => true,
            'EnumDefault'                => true,
            'NeverReturn'                => true,
            'ObjectDefault'              => true,
            'ObjectDefaultWithArguments' => true,
            'ScalarDefaults'             => true,
            'SelfReturn'                 => true,
            'StaticMethod'               => true,
            'Tentative'                  => true,
            'UnionType'                  => true,
            'Variadic'                   => true,
            'VoidReturn'                 => true,
        ], $survives);
    }

    public function testAByRefParameterKeepsItsSignatureAndIsForwardedByValue(): void
    {
        $double = $this->createStub(ByRef::class);

        // The signature is reproduced faithfully — the generated method
        // really does declare `&$out`.
        self::assertTrue((new ReflectionMethod($double, 'fill'))->getParameters()[0]->isPassedByReference());

        // But the dispatcher receives \func_get_args(), which hands
        // over VALUES, so nothing can be written back through the
        // reference. DESIGN.md:560 records that as the decision ("by-ref
        // parameters forwarded by value"); until now nothing pinned it,
        // and a recorded decision with no test is a claim.
        $out = ['before'];
        $double->fill($out);

        self::assertSame(['before'], $out);
    }

    public function testAStaticMethodIsRefusedRatherThanDoubled(): void
    {
        $double = $this->createStub(StaticMethod::class);

        try {
            $double::make();
            self::fail('a static method must not be doubled');
        } catch (LogicException $refusal) {
            self::assertStringContainsString('Static methods are not doubled', $refusal->getMessage());
        }
    }

    public function testAVoidMethodDispatches(): void
    {
        $void = $this->createStub(VoidReturn::class);
        $void->ping();

        $this->addToAssertionCount(1);
    }

    public function testATentativeReturnTypeReachesTheSignatureAndTheReturnValue(): void
    {
        $counted = $this->createStub(Tentative::class);

        // Both halves, because they used to disagree. The signature
        // falls back to getTentativeReturnType() — without it every
        // generated override is a deprecation — and so does the
        // auto-return resolver, which did not: it read getReturnType()
        // alone, null for a tentative type, so the double answered null
        // through a signature promising int. ✓ Measured before the fix:
        // Countable::count(), ArrayAccess::offsetExists() and
        // Iterator::valid() all TypeErrored on the first call.
        self::assertSame('int', (string) (new ReflectionMethod($counted, 'count'))->getReturnType());
        self::assertSame(0, $counted->count());
    }

    public function testAnObjectDefaultKeepsWhatTheInitializerSaid(): void
    {
        // Not just "it generates": a fix emitting a bare `new Config()`
        // would pass the sweep and silently lose the arguments. The
        // incumbent keeps them — ✓ measured against phpunit 13.3.1,
        // whose double reports 99 — and so must this.
        $double = $this->createStub(ObjectDefaultWithArguments::class);

        $find = (new ReflectionMethod($double, 'find'))->getParameters()[0]->getDefaultValue();
        self::assertInstanceOf(Config::class, $find);
        self::assertSame(99, $find->limit);

        // A class constant argument has to survive fully qualified into
        // a class generated in another namespace.
        $keyed = (new ReflectionMethod($double, 'keyed'))->getParameters()[0]->getDefaultValue();
        self::assertInstanceOf(Config::class, $keyed);
        self::assertSame(Config::LIMIT, $keyed->limit);

        // And the parameter stays optional, so the arity a caller
        // relies on is unchanged.
        self::assertNull($double->find());
    }

    public function testDefaultsSurviveIntoTheSignature(): void
    {
        $double     = $this->createStub(ScalarDefaults::class);
        $parameters = (new ReflectionMethod($double, 'page'))->getParameters();

        self::assertSame([1, 'a', true, null], [
            $parameters[0]->getDefaultValue(),
            $parameters[1]->getDefaultValue(),
            $parameters[2]->getDefaultValue(),
            $parameters[3]->getDefaultValue(),
        ]);

        // An enum case is a constant expression, so it survives
        // var_export() intact — the boundary the object default crosses.
        $enum = $this->createStub(EnumDefault::class);
        self::assertSame(Suit::Hearts, (new ReflectionMethod($enum, 'of'))->getParameters()[0]->getDefaultValue());
    }

    public function testAVariadicStaysVariadic(): void
    {
        $double = $this->createStub(Variadic::class);
        $sum    = (new ReflectionMethod($double, 'sum'))->getParameters()[0];

        self::assertTrue($sum->isVariadic());
        self::assertIsInt($double->sum(1, 2, 3));
    }

    /**
     * Generate a double for one shape in a child process.
     *
     * @param non-empty-string $shape
     */
    private function generatesInASubprocess(string $shape): bool
    {
        $root  = dirname(__DIR__, 3);
        $class = 'LucianoPereira\\Crucible\\Tests\\Fixtures\\DoubleShapes\\' . $shape;

        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . 'require ' . var_export($root . self::FIXTURES, true) . ';'
            . '(new \\' . TestDoubles::class . '())->create(' . var_export($class, true) . ');'
            . 'echo "GENERATED";';

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output);

        return str_contains(implode("\n", $output), 'GENERATED');
    }
}
