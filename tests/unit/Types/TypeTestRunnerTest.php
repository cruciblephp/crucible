<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Types;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\OutcomeLog;
use LucianoPereira\Crucible\Types\TypeTestRunner;
use LucianoPereira\Crucible\Types\TypeTestSuite;

use function array_column;
use function dirname;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Type tests (D-130) through the real phpstan: every call to one of
 * PHPStan's assertion functions and every crucible-type-error marker is
 * a test with its own verdict; errors outside any assertion, and a file
 * that asserts nothing, are the file's, never lost.
 */
#[CoversClass(TypeTestRunner::class)]
#[CoversClass(TypeTestSuite::class)]
#[Group('phpstan-blackbox')]
final class TypeTestRunnerTest extends TestCase
{
    public function testEveryAssertionIsATestWithItsOwnVerdict(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-09-26T12:00:00+00:00')));
        $emitter->subscribe($log = new OutcomeLog());
        $emitter->subscribe($failures = new class implements Listener {
            /** @var array<string, string> test id => failure message */
            public array $messages = [];

            public function handle(Envelope $envelope): void
            {
                if ($envelope->event instanceof TestFinished && $envelope->event->failure instanceof Failure) {
                    $this->messages[$envelope->event->test->toString()] = $envelope->event->failure->message;
                }
            }
        });

        $summary = (new TypeTestRunner($emitter, sys_get_temp_dir() . '/crucible-types-test-' . uniqid()))->run(
            [new TypeTestSuite('tests/_fixtures/types', phpstan: $root . '/vendor/bin/phpstan')],
            new WorkingDirectory($root),
        );

        self::assertSame([
            'tests/_fixtures/types/family.types.php::line 14: subtype of array',
            'tests/_fixtures/types/family.types.php::line 16: native array',
            'tests/_fixtures/types/ids.types.php::line 13: list<int>',
            'tests/_fixtures/types/ids.types.php::line 15: type error binaryOp.invalid',
        ], array_column($log->of(Outcome::Passed), 'id'));

        self::assertSame([
            'tests/_fixtures/types/family.types.php::line 15: subtype of int',
            'tests/_fixtures/types/ids.types.php::line 14: list<string>',
            'tests/_fixtures/types/ids.types.php::line 16: type error binaryOp.invalid',
        ], array_column($log->of(Outcome::Failed), 'id'));

        // ids.types.php calls an undefined function outside any
        // assertion; nothing.types.php asserts nothing, so tests nothing.
        self::assertSame([
            'tests/_fixtures/types/ids.types.php::the file analyses',
            'tests/_fixtures/types/nothing.types.php::the file analyses',
        ], array_column($log->of(Outcome::Errored), 'id'));
        self::assertSame(9, $summary->total());

        // Each failure says which way it failed.
        self::assertSame(
            'Expected a binaryOp.invalid error on this line; it analyses clean.',
            $failures->messages['tests/_fixtures/types/ids.types.php::line 16: type error binaryOp.invalid'] ?? null,
        );
        self::assertStringStartsWith(
            'Expected type list<string>, actual: list<int>',
            $failures->messages['tests/_fixtures/types/ids.types.php::line 14: list<string>'] ?? '',
        );
        self::assertStringStartsWith(
            'No type assertion in this file',
            $failures->messages['tests/_fixtures/types/nothing.types.php::the file analyses'] ?? '',
        );
    }

    public function testANameFilterKeepsOnlyTheAssertionsItNames(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-09-26T12:00:00+00:00')));
        $emitter->subscribe($log = new OutcomeLog());

        $suite = (new TypeTestSuite('tests/_fixtures/types', phpstan: $root . '/vendor/bin/phpstan'))->filtered('string');

        (new TypeTestRunner($emitter, sys_get_temp_dir() . '/crucible-types-test-' . uniqid()))->run([$suite], new WorkingDirectory($root));

        self::assertSame(['tests/_fixtures/types/ids.types.php::line 14: list<string>'], array_column($log->of(Outcome::Failed), 'id'));
        self::assertSame([], $log->of(Outcome::Passed));
        self::assertSame([], $log->of(Outcome::Errored));
    }
}
