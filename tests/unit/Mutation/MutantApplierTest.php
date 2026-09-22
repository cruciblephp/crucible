<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutantApplier;
use LucianoPereira\Crucible\Mutation\MutationOutcome;
use LucianoPereira\Crucible\Mutation\MutationVerdict;
use LucianoPereira\Crucible\Tests\Mutation\Fixtures\MutationTarget;

/**
 * The warm applier, exercised for real: every case forks, applies the
 * mutant in the child, and reads back a verdict. The covering "suite" is
 * one weak assertion — `answer() > 0` — so a mutant that keeps the sign
 * escapes it and one that flips the sign is killed.
 *
 * The warm path needs the fork and the signal; where they are absent the
 * whole class is required away rather than erroring — the engine cold-
 * falls-back there, which is a different test.
 */
#[CoversClass(MutantApplier::class)]
#[CoversClass(Mutant::class)]
#[CoversClass(MutationVerdict::class)]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class MutantApplierTest extends TestCase
{
    /**
     * The covering run: the weak test passes (returns no killer) while
     * `answer()` stays positive.
     *
     * @return ?non-empty-string
     */
    private function weakTest(): ?string
    {
        return MutationTarget::answer() > 0 ? null : 'MutationTarget::answer is positive';
    }

    private function mutant(string $body): Mutant
    {
        $source = "<?php\nnamespace LucianoPereira\\Crucible\\Tests\\Mutation\\Fixtures;\n"
            . "final class MutationTarget { public static function answer(): int { {$body} } }\n";

        return new Mutant(
            file: __DIR__ . '/Fixtures/MutationTarget.php',
            class: MutationTarget::class,
            line: 1,
            mutatorId: 'test',
            mutatedSource: $source,
        );
    }

    public function testAMutantTheWeakTestCannotSeeIsEscaped(): void
    {
        $applier = new MutantApplier();
        $mutant  = $this->mutant('return 5;'); // still positive → the weak test passes

        self::assertTrue($applier->canApply($mutant), 'The fixture must be unloaded for a warm apply.');

        $verdict = $applier->run($mutant, $this->weakTest(...));

        self::assertSame(MutationOutcome::Escaped, $verdict->outcome);
    }

    public function testAMutantTheCoveringTestCatchesIsKilled(): void
    {
        $verdict = (new MutantApplier())->run($this->mutant('return -5;'), $this->weakTest(...));

        self::assertSame(MutationOutcome::Killed, $verdict->outcome);
        self::assertSame('MutationTarget::answer is positive', $verdict->killedBy);
    }

    public function testAMutantThatCrashesTheRunIsErroredNotEscaped(): void
    {
        $verdict = (new MutantApplier())->run($this->mutant('throw new \\RuntimeException("boom");'), $this->weakTest(...));

        self::assertSame(MutationOutcome::Errored, $verdict->outcome);
    }

    public function testAMutantThatHangsIsTimedOut(): void
    {
        $verdict = (new MutantApplier())->run($this->mutant('usleep(3_000_000); return 2;'), $this->weakTest(...), 0.3);

        self::assertSame(MutationOutcome::TimedOut, $verdict->outcome);
    }

    public function testAnAlreadyLoadedClassIsNotWarmApplicable(): void
    {
        // This test class is loaded, so it cannot be re-mutated in a fork:
        // canApply says so, and the caller cold-falls-back.
        $loaded = new Mutant(__FILE__, self::class, 1, 'test', '<?php');

        self::assertFalse((new MutantApplier())->canApply($loaded));
    }
}
