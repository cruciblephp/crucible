<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Extension;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Configuration\Crucible;
use LucianoPereira\Crucible\Event\CheckFinished;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Extension\CheckRunner;
use LucianoPereira\Crucible\Extension\CommandGate;
use LucianoPereira\Crucible\Extension\CommandGateRunner;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\CheckLog;

use const PHP_BINARY;

/**
 * Slice 1 of the extension surface: a config-only command gate is a
 * run-scoped check. A gate presents its exit status (the leanest
 * artifact); Crucible tests `code === 0` and records a CheckFinished with
 * a named reason — never a bare non-zero. Proven black-box, through
 * real PHP subprocesses, the way the PHPStan extension is proven.
 */
#[CoversClass(CommandGate::class)]
#[CoversClass(CommandGateRunner::class)]
#[CoversClass(CheckRunner::class)]
#[CoversClass(CheckLog::class)]
#[CoversClass(CheckFinished::class)]
final class CommandGateTest extends TestCase
{
    private function runGate(CommandGate $gate): CheckLog
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-20T12:00:00+00:00')));
        $emitter->subscribe($log = new CheckLog());

        (new CheckRunner($emitter))->run([$gate], [], new WorkingDirectory(__DIR__));

        return $log;
    }

    public function testAZeroExitIsAPassedCheckWithNoReason(): void
    {
        $log = $this->runGate(new CommandGate('ok', [PHP_BINARY, '-r', 'exit(0);']));

        self::assertCount(1, $log->checks());
        self::assertSame(Outcome::Passed, $log->checks()[0]->outcome);
        self::assertNull($log->checks()[0]->reason);
        self::assertSame([], $log->failing());
    }

    public function testANonZeroExitIsAFailedCheckNamingTheExitCode(): void
    {
        $log   = $this->runGate(new CommandGate('lint', [PHP_BINARY, '-r', 'exit(3);']));
        $check = $log->checks()[0];

        self::assertSame(Outcome::Failed, $check->outcome);
        self::assertCount(1, $log->failing());

        $reason = $check->reason;
        self::assertNotNull($reason);
        self::assertStringContainsString('exit 3', $reason);
    }

    public function testACommandPastItsDeadlineIsKilledAndErrors(): void
    {
        $log   = $this->runGate(new CommandGate('slow', [PHP_BINARY, '-r', 'usleep(3000000);'], timeout: 1));
        $check = $log->checks()[0];

        self::assertSame(Outcome::Errored, $check->outcome);
        self::assertCount(1, $log->failing());

        $reason = $check->reason;
        self::assertNotNull($reason);
        self::assertStringContainsString('timed out', $reason);
    }

    public function testTheEventCarriesNameAndDuration(): void
    {
        $check = $this->runGate(new CommandGate('ok', [PHP_BINARY, '-r', 'exit(0);']))->checks()[0];

        self::assertSame('ok', $check->name);
        self::assertGreaterThanOrEqual(0.0, $check->duration);
        self::assertSame('check:finish', $check->name()->value);
        self::assertArrayHasKey('name', $check->payload());
    }

    public function testTheBuilderRecordsCommandGates(): void
    {
        $configuration = Crucible::configure()
            ->testSuite('unit', 'tests/unit')
            ->command('phpstan', [PHP_BINARY, '-v'])
            ->build();

        self::assertCount(1, $configuration->commandGates);
        self::assertSame('phpstan', $configuration->commandGates[0]->label);
    }

    public function testADuplicateCommandLabelIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        Crucible::configure()
            ->command('x', [PHP_BINARY, '-v'])
            ->command('x', [PHP_BINARY, '-i']);
    }

    public function testAnEmptyArgvIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        Crucible::configure()->command('x', []);
    }
}
