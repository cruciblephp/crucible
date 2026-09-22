<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\TraitComposer;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\AbstractBase;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\Counts;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\DemandsGreeting;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\DemandsUnanswered;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\FinalBase;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\Greets;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\GreetsDifferently;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\HoldsAnInt;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\HoldsAString;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\HoldsTheSameInt;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\OverridesSealed;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\PlainBase;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\SealedMethodBase;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\StringPropertyBase;
use ReflectionMethod;

use function array_values;
use function class_uses;
use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function str_contains;
use function var_export;

use const PHP_BINARY;

/**
 * What `uses(Trait::class)` compiles to.
 *
 * PHP has no runtime trait application, so a subclass is generated per
 * (class, traits) combination — code that exists only as a string, which
 * no gate reads: PHPStan cannot see inside a string literal, phpcpd
 * finds no clone there (D-108), and the mutation runner drops eval()'d
 * pseudo-paths (`MutationCommand.php:334`).
 *
 * Two shapes used to END the process rather than throw, which is why
 * the composition axis still runs in a SUBPROCESS: a corpus that can
 * only observe what does not kill it proves the wrong thing.
 *
 * They are PHP's limits, and the verdict — the run cannot proceed — is
 * the incumbent's too. What was NOT the incumbent's behaviour was the
 * manner: ✓ measured, pest 5.1.1 catches the same collision fatal in a
 * shutdown handler and reports it at exit 1, while Crucible died at 255
 * naming ComposedTestCase_<md5> and "eval()'d code" — its own
 * internals, for a mistake in the user's uses() call. Refusing before
 * eval() is what makes an exit code and a message about the user's file
 * possible at all; an uncatchable fatal has neither.
 */
#[CoversClass(TraitComposer::class)]
final class TraitComposerTest extends TestCase
{
    private const string FIXTURES = '/tests/_fixtures/trait-shapes/shapes.php';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../_fixtures/trait-shapes/shapes.php';
    }

    public function testNoShapeEndsTheProcess(): void
    {
        $ends = [];

        foreach ([
            'one'     => [PlainBase::class, [Greets::class]],
            'several' => [PlainBase::class, [Greets::class, Counts::class]],
            // An abstract trait method is a REQUIREMENT, not a rival
            // declaration: PHP applies the concrete one and is happy.
            'abstract-method-in-trait' => [PlainBase::class, [Greets::class, DemandsGreeting::class]],
            // PHP has no runtime insteadof, so two traits declaring
            // greeting() cannot both be applied.
            'collision' => [PlainBase::class, [Greets::class, GreetsDifferently::class]],
            // The composed class is concrete, and the base's abstract
            // method is implemented by nobody.
            'abstract-base-unimplemented' => [AbstractBase::class, [Greets::class]],
            // A trait's own abstract method is a requirement too, and
            // nothing here answers it.
            'trait-abstract-unanswered' => [PlainBase::class, [DemandsUnanswered::class]],
            // A trait cannot bring a method the base sealed.
            'final-method' => [SealedMethodBase::class, [OverridesSealed::class]],
            // ✓ Measured: a property may be declared twice only when
            // the declarations are IDENTICAL — same visibility, type
            // and default. These three cover both sides of that line.
            'property-pair-differs'   => [PlainBase::class, [HoldsAnInt::class, HoldsAString::class]],
            'property-base-differs'   => [StringPropertyBase::class, [HoldsAnInt::class]],
            'property-pair-identical' => [PlainBase::class, [HoldsAnInt::class, HoldsTheSameInt::class]],
        ] as $shape => [$class, $traits]) {
            $ends[$shape] = $this->endsInASubprocess($class, $traits);
        }

        // Nothing dies. The two PHP refuses are refused HERE, with a
        // message naming the traits and the method rather than a
        // generated class name and "eval()'d code".
        self::assertSame([
            'one'                         => 'composed',
            'several'                     => 'composed',
            'abstract-method-in-trait'    => 'composed',
            'collision'                   => 'refused',
            'abstract-base-unimplemented' => 'refused',
            'trait-abstract-unanswered'   => 'refused',
            'final-method'                => 'refused',
            'property-pair-differs'       => 'refused',
            'property-base-differs'       => 'refused',
            // The other side of the line: identical declarations are
            // legal, so a guard that keyed on the NAME would refuse
            // something PHP accepts.
            'property-pair-identical' => 'composed',
        ], $ends);
    }

    public function testACollisionNamesBothTraitsAndTheMethod(): void
    {
        try {
            TraitComposer::compose(PlainBase::class, [Greets::class, GreetsDifferently::class]);
            self::fail('two traits declaring greeting() cannot both be applied');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString(Greets::class, $refusal->getMessage());
            self::assertStringContainsString(GreetsDifferently::class, $refusal->getMessage());
            self::assertStringContainsString('greeting()', $refusal->getMessage());
            self::assertStringContainsString('insteadof', $refusal->getMessage());
        }
    }

    public function testAnAbstractBaseNamesWhatNobodyImplements(): void
    {
        try {
            TraitComposer::compose(AbstractBase::class, [Greets::class]);
            self::fail('a concrete subclass cannot leave an abstract method unimplemented');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString(AbstractBase::class, $refusal->getMessage());
            self::assertStringContainsString('unimplemented()', $refusal->getMessage());
        }
    }

    public function testTheComposedClassCarriesEveryTraitAndTheBase(): void
    {
        $composed = TraitComposer::compose(PlainBase::class, [Greets::class, Counts::class]);
        $instance = new $composed('name');

        self::assertInstanceOf(PlainBase::class, $instance);

        // Through reflection, because the members under test are the
        // ones the ANALYSER cannot see: the class is generated, so its
        // trait methods exist only after eval(). Calling them directly
        // would read as method.notFound — which is the blind spot this
        // corpus exists for, not a reason to weaken the assertion.
        self::assertSame('hello', (new ReflectionMethod($composed, 'greeting'))->invoke($instance));
        self::assertSame(7, (new ReflectionMethod($composed, 'count'))->invoke($instance));
        // Sorted, because the cache key sorts: the generated class is
        // one per COMBINATION, not per spelling, and the next test
        // pins why that matters.
        self::assertSame([Counts::class, Greets::class], array_values(class_uses($composed)));
    }

    public function testTheSameCombinationIsComposedOnceWhateverTheOrder(): void
    {
        // The cache key sorts and de-duplicates, so the three spellings
        // below are one generated class rather than three. That is not
        // an optimisation: eval()ing a second class with the same
        // members for the same combination would make `instanceof`
        // answers depend on which spelling ran first.
        $first  = TraitComposer::compose(PlainBase::class, [Greets::class, Counts::class]);
        $second = TraitComposer::compose(PlainBase::class, [Counts::class, Greets::class]);
        $third  = TraitComposer::compose(PlainBase::class, [Greets::class, Counts::class, Greets::class]);

        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    public function testNoTraitsMeansNoGeneratedClassAtAll(): void
    {
        // The binding class itself, not an empty subclass of it: a
        // pest file that uses() only a base class must land on that
        // base, or its name shows up in every failure message.
        self::assertSame(PlainBase::class, TraitComposer::compose(PlainBase::class, []));
    }

    public function testAFinalBaseIsRefusedWithItsName(): void
    {
        try {
            TraitComposer::compose(FinalBase::class, [Greets::class]);
            self::fail('a final class cannot be extended, so composition must be refused');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString(FinalBase::class, $refusal->getMessage());
            self::assertStringContainsString('final', $refusal->getMessage());
        }
    }

    /**
     * How composing this shape ends, from outside the process.
     *
     * @param class-string       $class
     * @param list<class-string> $traits
     *
     * @return 'composed'|'refused'|'died'
     */
    private function endsInASubprocess(string $class, array $traits): string
    {
        $root = dirname(__DIR__, 4);

        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . 'require ' . var_export($root . self::FIXTURES, true) . ';'
            . 'try {'
            . '\\' . TraitComposer::class . '::compose('
            . var_export($class, true) . ', ' . var_export($traits, true) . ');'
            . 'echo "COMPOSED";'
            . '} catch (\\' . ConfigurationException::class . ' $refusal) { echo "REFUSED"; }';

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output);

        $said = implode("\n", $output);

        return match (true) {
            str_contains($said, 'COMPOSED') => 'composed',
            str_contains($said, 'REFUSED')  => 'refused',
            default                         => 'died',
        };
    }
}
