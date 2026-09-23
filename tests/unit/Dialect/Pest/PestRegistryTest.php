<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Architecture\ArchRule;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\DescribeCall;
use LucianoPereira\Crucible\Dialect\Pest\PestRegistry;
use LucianoPereira\Crucible\Dialect\Pest\TestCall;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Property\Gen;
use RuntimeException;

use function dirname;
use function mb_strlen;
use function str_repeat;
use function strtoupper;

/**
 * Where the pest globals deposit what a *.pest.php file declares.
 *
 * Static state with a begin()/drain() bracket, which is why every test
 * here closes its own bracket: a leaked `collecting` flag would let a
 * later test call a global that should have refused, and a leaked call
 * list would put a test into somebody else's file.
 *
 * The auto-naming helpers are the dense part and the reason this file
 * exists — a check() with no description takes its name from its own
 * source line, and a name is the TestId, so getting it wrong renames a
 * test or collides two.
 */
#[CoversClass(PestRegistry::class)]
final class PestRegistryTest extends TestCase
{
    private const string FILE = '/project/tests/Unit/Example.pest.php';

    protected function tearDown(): void
    {
        // Unconditional: a test that threw mid-bracket still has to
        // hand the registry back in a state the next one can use.
        PestRegistry::drain();
    }

    public function testEveryGlobalRefusesOutsideAFileBeingLoaded(): void
    {
        PestRegistry::drain();

        $refusals = [
            'test()'       => static fn(): mixed => PestRegistry::test('a', null, 'test'),
            'it()'         => static fn(): mixed => PestRegistry::test('a', null, 'it'),
            'arch()'       => PestRegistry::arch(...),
            'check()'      => static fn(): mixed => PestRegistry::check(static fn(): int => 1),
            'property()'   => static fn(): mixed => PestRegistry::property('a', Gen::int(), static fn(): bool => true),
            'table()'      => static fn(): mixed => PestRegistry::table('strtoupper', [['a', 'A']]),
            'describe()'   => static fn(): mixed => PestRegistry::describe('a', static function (): void {}),
            'beforeEach()' => static function (): void {
                PestRegistry::beforeEach(static function (): void {});
            },
            'afterEach()' => static function (): void {
                PestRegistry::afterEach(static function (): void {});
            },
            'beforeAll()' => static function (): void {
                PestRegistry::beforeAll(static function (): void {});
            },
            'afterAll()' => static function (): void {
                PestRegistry::afterAll(static function (): void {});
            },
        ];

        foreach ($refusals as $named => $call) {
            try {
                $call();
                self::fail($named . ' must refuse outside a file being loaded');
            } catch (ConfigurationException $refusal) {
                self::assertStringContainsString($named, $refusal->getMessage());
                self::assertStringContainsString('*.pest.php', $refusal->getMessage());
            }
        }
    }

    public function testDrainFoldsTheFilesRegistrationsLastClassWinning(): void
    {
        PestRegistry::begin(self::FILE);

        $first  = static function (): void {};
        $second = static function (): void {};

        PestRegistry::beforeEach($first);
        PestRegistry::uses(FirstScopeClass::class)->group('alpha');
        PestRegistry::uses(SecondScopeClass::class)->group('beta');
        PestRegistry::afterAll($second);
        PestRegistry::covers('App\\One', 'App\\Two');
        PestRegistry::mutates('src/One.php');

        $state = PestRegistry::drain();

        // Last class wins; groups accumulate across both uses() calls.
        self::assertSame(SecondScopeClass::class, $state->uses);
        self::assertSame(['alpha', 'beta'], $state->groups);
        self::assertSame([$first], $state->beforeEach);
        self::assertSame([$second], $state->afterAll);
        self::assertSame(['App\\One', 'App\\Two'], $state->covers);
        self::assertSame(['src/One.php'], $state->mutates);
    }

    public function testDrainClosesTheBracketSoTheNextCallRefuses(): void
    {
        PestRegistry::begin(self::FILE);
        self::assertSame(dirname(self::FILE), PestRegistry::currentFileDirectory());

        PestRegistry::drain();

        self::assertNull(PestRegistry::currentFileDirectory());

        try {
            PestRegistry::test('a', null, 'test');
            self::fail('drain() ends collection');
        } catch (ConfigurationException) {
            self::assertTrue(true);
        }
    }

    public function testBeginDiscardsWhateverThePreviousFileLeft(): void
    {
        PestRegistry::begin(self::FILE);
        PestRegistry::test('from the first file', null, 'test');
        PestRegistry::covers('App\\Stale');

        // One file at a time: beginning the next must not inherit the
        // previous one's calls, or a test lands in the wrong file.
        PestRegistry::begin('/project/tests/Unit/Other.pest.php');
        $state = PestRegistry::drain();

        self::assertSame([], $state->calls);
        self::assertSame([], $state->covers);
    }

    public function testDescribeNestsThePathAndPopsItEvenWhenTheBodyThrows(): void
    {
        PestRegistry::begin(self::FILE);

        $describe = PestRegistry::describe('Calculator', static function (): void {
            PestRegistry::describe('arithmetic', static function (): void {
                PestRegistry::test('adds', null, 'it');
            });
        });

        self::assertInstanceOf(DescribeCall::class, $describe);

        try {
            PestRegistry::describe('broken', static function (): void {
                throw new RuntimeException('body blew up');
            });
            self::fail('the body throws');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        // The finally in describe() is what makes this hold: a thrown
        // body must not leave 'broken' on the path for every later test.
        PestRegistry::test('after', null, 'test');

        $state = PestRegistry::drain();

        self::assertSame('Calculator > arithmetic > it adds', $state->calls[0]->name());
        self::assertSame('after', $state->calls[1]->name());
    }

    public function testArchRegistersAnOrdinaryTestAndHandsBackTheRule(): void
    {
        PestRegistry::begin(self::FILE);

        $named   = PestRegistry::arch('the domain stays pure');
        $default = PestRegistry::arch();

        self::assertInstanceOf(ArchRule::class, $named);
        self::assertInstanceOf(ArchRule::class, $default);

        $state = PestRegistry::drain();

        // An arch rule is a test, not a linter -- and the default name
        // is the spec's, not the rule's description.
        self::assertSame('the domain stays pure', $state->calls[0]->name());
        self::assertSame('architecture', $state->calls[1]->name());
    }

    public function testCheckTakesItsNameFromATrailingCommentWhenThereIsOne(): void
    {
        PestRegistry::begin(self::FILE);

        PestRegistry::check(static fn(): int => 1 + 1); // adds one and one
        PestRegistry::check(static fn(): int => 2 + 2); # hash comments count
        PestRegistry::check(static fn(): int => 3 + 3); /* and inline ones */

        $state = PestRegistry::drain();

        self::assertSame('adds one and one', $state->calls[0]->name());
        self::assertSame('hash comments count', $state->calls[1]->name());
        self::assertSame('and inline ones', $state->calls[2]->name());
    }

    public function testCheckFallsBackToTheSourceLineAndThenToTheLineNumber(): void
    {
        PestRegistry::begin(self::FILE);

        PestRegistry::check(static fn(): int => 40 + 2);

        $state = PestRegistry::drain();

        // No comment: the line itself, trimmed. Asserted by fragment
        // rather than in full, since the exact text is Pint's to decide.
        self::assertStringContainsString('40 + 2', $state->calls[0]->name());
    }

    public function testALongSourceLineIsTruncatedRatherThanUsedWhole(): void
    {
        PestRegistry::begin(self::FILE);

        PestRegistry::check(static fn(): string => str_repeat('a name far longer than any reasonable test description ', 4));

        $name = PestRegistry::drain()->calls[0]->name();

        // 100 characters plus the ellipsis: a TestId is read by people.
        self::assertStringEndsWith('…', $name);
        self::assertSame(101, mb_strlen($name));
    }

    public function testTwoDerivedNamesThatAgreeGetAnOccurrenceSuffix(): void
    {
        PestRegistry::begin(self::FILE);

        PestRegistry::check(static fn(): int => 1); // a shared comment
        PestRegistry::check(static fn(): int => 2); // a shared comment

        // Two rows that name themselves identically, the table route to
        // the same collision.
        PestRegistry::table('strtoupper', [['a', 'A'], ['a', 'A']]);

        $state = PestRegistry::drain();

        // The name IS the TestId, so a collision would make two tests
        // one; the first keeps the bare name.
        self::assertSame('a shared comment', $state->calls[0]->name());
        self::assertSame('a shared comment #2', $state->calls[1]->name());
        self::assertSame("strtoupper('a') = 'A'", $state->calls[2]->name());
        self::assertSame("strtoupper('a') = 'A' #2", $state->calls[3]->name());
    }

    public function testADuplicateDescriptionIsRefusedTheWayTheIncumbentRefusesIt(): void
    {
        PestRegistry::begin(self::FILE);
        PestRegistry::test('the same name', null, 'test');

        // ✓ Pest 5.1.1 throws TestAlreadyExist at collection and runs
        // nothing in the file. A name IS the TestId, so accepting the
        // second filed two tests under one id.
        $this->refuses(
            static fn(): mixed => PestRegistry::test('the same name', null, 'test'),
            'A test named [the same name] already exists in',
        );

        // The it() spelling collides with itself the same way.
        PestRegistry::test('shares', null, 'it');
        $this->refuses(
            static fn(): mixed => PestRegistry::test('shares', null, 'it'),
            'already exists in',
        );

        // A derived name never trips it, because it was made unique
        // before it got here.
        PestRegistry::check(static fn(): int => 1); // derived once
        PestRegistry::check(static fn(): int => 2); // derived once
        self::assertCount(4, PestRegistry::drain()->calls);
    }

    public function testScopingSeparatesNamesThatWouldOtherwiseCollide(): void
    {
        PestRegistry::begin(self::FILE);

        // ✓ Measured on the same oracle run: all four coexist. The
        // uniqueness is on the COMPOSED name, so a describe() scope
        // separates two leaves, and the "it " prefix is part of it.
        PestRegistry::describe('group A', static function (): void {
            PestRegistry::test('shared', null, 'test');
        });

        PestRegistry::describe('group B', static function (): void {
            PestRegistry::test('shared', null, 'test');
        });

        PestRegistry::test('shared', null, 'test');
        PestRegistry::test('shared', null, 'it');

        $names = [];

        foreach (PestRegistry::drain()->calls as $call) {
            $names[] = $call->name();
        }

        self::assertSame([
            'group A > shared',
            'group B > shared',
            'shared',
            'it shared',
        ], $names);
    }

    public function testPropertyRefusesEveryArgumentShapeItCannotRun(): void
    {
        PestRegistry::begin(self::FILE);

        // Last argument must be the property closure.
        $this->refuses(
            static fn(): mixed => PestRegistry::property('a', static fn(): bool => true, Gen::int()),
            'last argument',
        );

        // Anything between description and closure must be a generator.
        $this->refuses(
            static fn(): mixed => PestRegistry::property('a', static fn(): bool => true, static fn(): bool => true),
            'between the description and the closure',
        );

        // And there must be at least one.
        $this->refuses(
            static fn(): mixed => PestRegistry::property('a', static fn(): bool => true),
            'at least one generator',
        );
    }

    public function testPropertyRegistersOneTestWhenTheArgumentsAreRight(): void
    {
        PestRegistry::begin(self::FILE);

        $call = PestRegistry::property('addition commutes', Gen::int(), Gen::int(), static fn(): bool => true);

        self::assertInstanceOf(TestCall::class, $call);
        self::assertSame('addition commutes', PestRegistry::drain()->calls[0]->name());
    }

    public function testTableNamesEachRowFromItsArgumentsOrItsKey(): void
    {
        PestRegistry::begin(self::FILE);

        PestRegistry::table('strtoupper', [
            ['a', 'A'],
            'the empty string' => ['', ''],
        ]);

        $state = PestRegistry::drain();

        // Positional rows read as a call; a string key names the case.
        self::assertSame("strtoupper('a') = 'A'", $state->calls[0]->name());
        self::assertSame('strtoupper: the empty string', $state->calls[1]->name());
    }

    public function testTableRefusesARowThatIsNotArgumentsAndAnExpectedValue(): void
    {
        PestRegistry::begin(self::FILE);

        $this->refuses(static fn(): mixed => PestRegistry::table('strtoupper', [['a', 'A'], 'not a row']), 'got a string row');
        $this->refuses(static fn(): mixed => PestRegistry::table('strtoupper', [[]]), 'got a array row');
    }

    public function testTableNamesItsSubjectByWhateverKindOfCallableItIs(): void
    {
        PestRegistry::begin(self::FILE);

        PestRegistry::table(strtoupper(...), [['a', 'A']]);
        PestRegistry::table([new Subject(), 'twice'], [[2, 4]]);
        PestRegistry::table([Subject::class, 'thrice'], [[2, 6]]);
        PestRegistry::table(new Subject(), [[3, 3]]);
        PestRegistry::table(static fn(int $n): int => $n, [[5, 5]]);

        $names = [];

        foreach (PestRegistry::drain()->calls as $test) {
            $names[] = $test->name();
        }

        self::assertStringStartsWith('strtoupper(', $names[0]);
        self::assertStringStartsWith(Subject::class . '::twice(', $names[1]);
        self::assertStringStartsWith(Subject::class . '::thrice(', $names[2]);
        self::assertStringStartsWith(Subject::class . '::__invoke(', $names[3]);

        // An anonymous closure has no name worth printing, so it says
        // where it is instead.
        self::assertStringStartsWith('table on line ', $names[4]);
    }

    public function testFixtureResolvesUnderTheProjectRootAndRefusesWhatIsNotThere(): void
    {
        $root = dirname(__DIR__, 4);

        PestRegistry::begin(self::FILE, $root);

        // Always tests/Fixtures under the ROOT, whatever directory the
        // calling file sits in -- measured against Pest 5.1.1, which
        // has no nearest-Fixtures rule.
        self::assertStringEndsWith(WorkingDirectory::native('/tests/Fixtures/sample.txt'), PestRegistry::fixture('sample.txt'));

        $this->refuses(static fn(): mixed => PestRegistry::fixture('nothing-here.txt'), 'does not exist');
    }

    public function testFixtureRefusesOutsideAFileBeingCollected(): void
    {
        PestRegistry::drain();

        $this->refuses(
            static fn(): mixed => PestRegistry::fixture('sample.txt'),
            'only available while a test file is being collected',
        );
    }

    /**
     * @param callable(): mixed $call
     */
    private function refuses(callable $call, string $fragment): void
    {
        try {
            $call();
            self::fail('expected a refusal mentioning: ' . $fragment);
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString($fragment, $refusal->getMessage());
        }
    }
}

/** A subject with a couple of shapes table() has to name. */
final class Subject
{
    public function twice(int $n): int
    {
        return $n * 2;
    }

    public static function thrice(int $n): int
    {
        return $n * 3;
    }

    public function __invoke(int $n): int
    {
        return $n;
    }
}

final class FirstScopeClass {}

final class SecondScopeClass {}
