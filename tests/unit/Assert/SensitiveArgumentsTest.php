<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Assert\SensitiveArguments;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Property\Gen;
use LucianoPereira\Crucible\Property\Property;
use SensitiveParameter;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function sprintf;

use const PHP_BINARY;

/**
 * What Crucible alone prints keeps a #[\SensitiveParameter] value out, as
 * PHP keeps it out of a trace; the phpunit and pest dialects keep their
 * incumbents' answers, which print it (D-167).
 */
#[CoversClass(SensitiveArguments::class)]
#[CoversClass(Property::class)]
final class SensitiveArgumentsTest extends TestCase
{
    public function testASensitiveArgumentIsWrittenAsPhpWritesItInATrace(): void
    {
        $login = static fn(string $user, #[SensitiveParameter] string $password, #[SensitiveParameter] string ...$codes): bool => true;

        self::assertSame(
            ["'ann'", SensitiveArguments::REDACTED, SensitiveArguments::REDACTED, SensitiveArguments::REDACTED],
            SensitiveArguments::render($login, ['ann', 'hunter2', '1', '2'], Exporter::describe(...)),
        );
    }

    public function testAPropertysCounterexampleLeavesTheSensitiveValueOut(): void
    {
        try {
            Property::forAll(Gen::int(10, 10), Gen::int(42, 42))->seed(1)->check(static fn(int $attempts, #[SensitiveParameter] int $pin): bool => false);
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('Counterexample: 10, ' . SensitiveArguments::REDACTED . "\n", $failure->getMessage());
            self::assertStringNotContainsString('42', $failure->getMessage());

            return;
        }

        self::fail('the property held');
    }

    public function testATableCaseIsNamedWithoutTheSensitiveValue(): void
    {
        exec(sprintf(
            'cd %s && %s %s --list-tests 2>&1',
            escapeshellarg(dirname(__DIR__, 2) . '/_fixtures/sensitive'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 3) . '/crucible'),
        ), $output);

        $listing = implode("\n", $output);

        self::assertStringContainsString("sensitiveLogin('ann', " . SensitiveArguments::REDACTED . ') = false', $listing);
        self::assertStringNotContainsString('hunter2', $listing);
    }
}
