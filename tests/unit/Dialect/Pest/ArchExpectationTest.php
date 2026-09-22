<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use Closure;
use LucianoPereira\Crucible\Architecture\ArchitectureUniverse;
use LucianoPereira\Crucible\Architecture\ArchRule;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/**
 * What `expect()` means when its subject names a namespace (D1).
 *
 * A subject resolves the way an `arch()` target does — the declared
 * symbol it names, plus everything beneath it in the configured source
 * — so `expect(Foo::class)` still answers about Foo, and
 * `expect('App\Models')` answers about everything under it.
 *
 * A target matching nothing PASSES, because that is what the incumbent
 * does and a borrowed suite should get its own verdict. Crucible
 * disagrees out loud rather than silently: a warning names the target,
 * and `--fail-on-warning` turns it into a failure for anyone who wants
 * D-088's stricter position.
 */
#[CoversClass(Expectation::class)]
final class ArchExpectationTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];

        set_error_handler(function (int $level, string $message): bool {
            $this->warnings[] = $message;

            return true;
        }, E_USER_WARNING);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testASubjectNamingNothingPassesAndSaysSo(): void
    {
        // Passing is the incumbent's answer; the warning is Crucible's.
        (new Expectation('NoSuchNamespaceAnywhere'))->toBeClass();

        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('NoSuchNamespaceAnywhere', $this->warnings[0]);
        self::assertStringContainsString('asserts nothing', $this->warnings[0]);
    }

    /**
     * The empty case has to pass NEGATED too. Routed through LogicalNot
     * a vacuous pass would invert into a failure, so `->not` on a
     * target matching nothing would fail where the incumbent passes —
     * the false-red mirror of D-104.
     */
    public function testTheEmptyCasePassesUnderNegationAsWell(): void
    {
        (new Expectation('NoSuchNamespaceAnywhere'))->not->toBeClass();

        self::assertCount(1, $this->warnings);
    }

    public function testANamedSymbolStillAnswersAboutItself(): void
    {
        // The value reading is not a separate mode: a class-string
        // resolves to exactly itself, which is the same answer as
        // before.
        (new Expectation(Expectation::class))->toBeClass();
        (new Expectation(\LucianoPereira\Crucible\Event\Listener::class))->toBeInterface();

        self::assertSame([], $this->warnings, 'a real symbol matches itself, so nothing is vacuous');
    }

    public function testANamespaceAnswersForEverythingBeneathIt(): void
    {
        // Console\Concerns holds four files and all four are traits.
        (new Expectation('LucianoPereira\Crucible\Console\Concerns'))->toBeTraits();

        self::assertSame([], $this->warnings);
    }

    /**
     * The point of holding the predicates in one place: both spellings
     * ask the same question, so they must give the same answer. Asserted
     * rather than assumed — two implementations agreeing today is what
     * five copies of a verdict classification looked like before one of
     * them drifted (D-108).
     */
    public function testBothSpellingsAgree(): void
    {
        $rule = new ArchRule(new ArchitectureUniverse(new Source(includeDirectories: ['src']), new WorkingDirectory(dirname(__DIR__, 4))));

        // arch(): every class in Console\Concerns is a trait.
        $rule->expect('LucianoPereira\Crucible\Console\Concerns')->toBeTraits()->assert();

        // expect(): the same claim, the same words.
        (new Expectation('LucianoPereira\Crucible\Console\Concerns'))->toBeTraits();

        self::assertSame([], $this->warnings);
    }

    public function testTheDependencyMatchersReachTheSameSource(): void
    {
        // src/ declares strict types everywhere, because Pint enforces
        // it — an answer known without consulting the implementation.
        (new Expectation('LucianoPereira\Crucible\Architecture'))->toUseStrictTypes();

        self::assertSame([], $this->warnings);
    }

    public function testDocumentationAndCasingAnswerInTheExpectSpellingToo(): void
    {
        (new Expectation(\LucianoPereira\Crucible\Impact\ImpactSelection::class))->toHaveMethodsDocumented();
        (new Expectation('LucianoPereira\Crucible\Architecture'))->toBeCasedCorrectly();

        self::assertSame([], $this->warnings);
    }

    public function testAnEnumIsNotReadonlyInEitherSpelling(): void
    {
        // ✓ The incumbent excludes enums from toBeReadonly explicitly.
        // Both spellings have to agree with it, and with each other.
        $failed = false;

        try {
            (new Expectation(\LucianoPereira\Crucible\Impact\ImpactReason::class))->toBeReadonly();
        } catch (AssertionFailedError) {
            $failed = true;
        }

        self::assertTrue($failed, 'an enum is immutable by construction, but that is not what readonly claims');
    }

    /**
     * Every plural spelling is `return $this->singular();` — in the
     * incumbent too — so the only way one can be wrong is by
     * delegating to the wrong singular, which the positive form alone
     * would not catch. Both are therefore asked about targets of every
     * kind and required to answer the same, rather than asked once
     * about a target they agree on.
     *
     * They were executed by nothing before this: the parity grid
     * excludes the arch family, and `toBeInterfaces` — the one that
     * shipped unable to pass at all — had no caller anywhere.
     */
    public function testEveryPluralSpellingAgreesWithItsSingular(): void
    {
        $targets = [
            \LucianoPereira\Crucible\Clock\SystemClock::class,
            \LucianoPereira\Crucible\Event\Listener::class,
            'LucianoPereira\Crucible\Console\Concerns',
            \LucianoPereira\Crucible\Reporting\Document\Tone::class,
            \LucianoPereira\Crucible\Console\Style\Attribute::class,
            \LucianoPereira\Crucible\Assert\ValueType::class,
        ];

        $pairs = [
            ['toBeClass', 'toBeClasses'],
            ['toBeInterface', 'toBeInterfaces'],
            ['toBeTrait', 'toBeTraits'],
            ['toBeEnum', 'toBeEnums'],
            ['toBeIntBackedEnum', 'toBeIntBackedEnums'],
            ['toBeStringBackedEnum', 'toBeStringBackedEnums'],
        ];

        $agreed = 0;

        foreach ($pairs as [$singular, $plural]) {
            foreach ($targets as $target) {
                self::assertSame(
                    $this->answers($target, $singular),
                    $this->answers($target, $plural),
                    $plural . ' does not answer as ' . $singular . ' for ' . $target,
                );

                $agreed++;
            }
        }

        // The pairs are worthless if every answer is the same one.
        self::assertSame(36, $agreed);
        self::assertTrue($this->answers($targets[0], 'toBeClasses'));
        self::assertFalse($this->answers($targets[0], 'toBeEnums'));
    }

    /**
     * The four remaining wrappers that no test and no probe had ever
     * executed. Each is one line into {@see ArchPredicates}, so what is
     * checked here is the pairing — a wrapper naming the wrong
     * predicate reads perfectly and answers about something else.
     */
    public function testTheRemainingWrappersAnswerInTheExpectSpelling(): void
    {
        $predicates = \LucianoPereira\Crucible\Architecture\ArchPredicates::class;

        // ✓ Measured: final, parentless, interface-free, all properties
        // documented; the Clock namespace compares with === throughout.
        self::assertTrue($this->answers($predicates, 'toExtendNothing'));
        self::assertTrue($this->answers($predicates, 'toImplementNothing'));
        self::assertTrue($this->answers($predicates, 'toHavePropertiesDocumented'));
        self::assertTrue($this->answers('LucianoPereira\Crucible\Clock', 'toUseStrictEquality'));

        // And each can still fail, so a wrapper that asserts nothing
        // cannot pass for the wrong reason.
        self::assertFalse($this->answers(\LucianoPereira\Crucible\Clock\SystemClock::class, 'toImplementNothing'));
        self::assertFalse($this->answers($predicates, 'toUseStrictEquality'));
    }

    /** Did the matcher pass for this target? */
    private function answers(string $target, string $matcher): bool
    {
        return $this->passes(static function () use ($target, $matcher): void {
            (new Expectation($target))->{$matcher}();
        });
    }

    private function passes(Closure $work): bool
    {
        try {
            $work();
        } catch (AssertionFailedError) {
            return false;
        }

        return true;
    }
}
