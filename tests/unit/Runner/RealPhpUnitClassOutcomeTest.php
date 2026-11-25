<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\TestRunner;

use function array_column;
use function chmod;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A class-level skip on a *real* PHPUnit-descended class, compared
 * against the real phpunit binary — never a fixture that imitates its
 * exceptions. The oracle is executed, not read, and the test skips by
 * name on a machine without it (ORACLES.md).
 */
#[CoversClass(TestRunner::class)]
final class RealPhpUnitClassOutcomeTest extends TestCase
{
    public function testAClassLevelSkipOrIncompleteMatchesTheOracle(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/phpunit-main/vendor/autoload.php')) {
            self::markTestSkipped('The PHPUnit oracle is not installed (phpunit-main/), so real class-level outcomes cannot be compared.');
        }

        foreach ([
            ['markTestSkipped', 'whole class'],
            ['markTestIncomplete', 'half done'],
        ] as [$method, $reason]) {
            $this->compareWithTheOracle($root, $method, $reason);
        }
    }

    /**
     * @param non-empty-string $root
     * @param non-empty-string $method
     * @param non-empty-string $reason
     */
    private function compareWithTheOracle(string $root, string $method, string $reason): void
    {
        $directory = sys_get_temp_dir() . '/crucible-real-phpunit-' . uniqid();
        mkdir($directory, 0o777, true);

        $fixture = $directory . '/ClassOutcomeTest.php';
        $results = $directory . '/crucible-results.json';
        $script  = $directory . '/run-crucible.php';

        file_put_contents($fixture, <<<PHP
            <?php

            declare(strict_types=1);

            use PHPUnit\\Framework\\TestCase;

            final class ClassOutcomeTest extends TestCase
            {
                public static function setUpBeforeClass(): void
                {
                    self::{$method}('{$reason}');
                }

                public function testOne(): void
                {
                    self::assertTrue(true);
                }

                public function testTwo(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        file_put_contents($script, <<<'PHP'
            <?php

            declare(strict_types=1);

            use ArrayObject;
            use DateTimeImmutable;
            use LucianoPereira\Crucible\Clock\FrozenClock;
            use LucianoPereira\Crucible\Event\Emitter;
            use LucianoPereira\Crucible\Event\Envelope;
            use LucianoPereira\Crucible\Event\Listener;
            use LucianoPereira\Crucible\Event\TestFinished;
            use LucianoPereira\Crucible\Metadata\MetadataCollection;
            use LucianoPereira\Crucible\Runner\TestRunner;
            use LucianoPereira\Crucible\Test\TestDefinition;
            use LucianoPereira\Crucible\Test\TestGroup;
            use LucianoPereira\Crucible\Test\TestId;

            require $argv[1];
            require $argv[2];
            require $argv[3];

            /** @var ArrayObject<int, Envelope> $events */
            $events = new ArrayObject();

            $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));
            $emitter->subscribe(new readonly class ($events) implements Listener {
                /**
                 * @param ArrayObject<int, Envelope> $events
                 */
                public function __construct(private ArrayObject $events) {}

                public function handle(Envelope $envelope): void
                {
                    $this->events->append($envelope);
                }
            });

            $class = $argv[5];

            $group = new TestGroup(
                $class,
                [
                    new TestDefinition(new TestId('ClassOutcomeTest.php', 'testOne'), static fn(): null => null, MetadataCollection::from()),
                    new TestDefinition(new TestId('ClassOutcomeTest.php', 'testTwo'), static fn(): null => null, MetadataCollection::from()),
                ],
                static function () use ($class): void {
                    $class::setUpBeforeClass();
                },
            );

            $summary = (new TestRunner($emitter))->run([$group]);
            $outcomes = [];

            foreach ($events as $envelope) {
                if ($envelope->event instanceof TestFinished) {
                    $outcomes[] = [
                        'id'      => $envelope->event->test->toString(),
                        'outcome' => $envelope->event->outcome->value,
                        'reason'  => $envelope->event->reason,
                    ];
                }
            }

            file_put_contents($argv[4], json_encode([
                'outcomes' => $outcomes,
                'summary'  => ['total' => $summary->total(), 'skipped' => $summary->skipped],
            ], JSON_THROW_ON_ERROR));
            PHP);

        try {
            [$oracleExit, $oracleOut] = $this->run(
                [PHP_BINARY, $root . '/conformance/phpunit-oracle.php', $fixture],
                $directory,
            );

            self::assertSame(0, $oracleExit, 'The PHPUnit oracle itself did not run cleanly: ' . $oracleOut);
            self::assertSame(1, preg_match('/Tests: 2, Assertions: 0, Skipped: 2\./', $oracleOut), 'The oracle did not report two skipped tests: ' . $oracleOut);

            [$crucibleExit] = $this->run(
                [
                    PHP_BINARY,
                    $script,
                    $root . '/vendor/autoload.php',
                    $root . '/phpunit-main/vendor/autoload.php',
                    $fixture,
                    $results,
                    'ClassOutcomeTest',
                ],
                $directory,
            );

            self::assertSame(0, $crucibleExit);

            $decoded = json_decode((string) file_get_contents($results), true);

            self::assertIsArray($decoded);
            self::assertSame(['total' => 2, 'skipped' => 2], $decoded['summary'] ?? null, 'Crucible did not count the class as two skips.');

            $outcomes = is_array($decoded['outcomes'] ?? null) ? $decoded['outcomes'] : [];

            self::assertSame(
                ['skip', 'skip'],
                array_column($outcomes, 'outcome'),
                'A real PHPUnit class-level mark must not error every test.',
            );

            $reasons = [];

            foreach ($outcomes as $outcome) {
                if (is_array($outcome) && is_string($outcome['reason'] ?? null)) {
                    $reasons[] = $outcome['reason'];
                }
            }

            self::assertSame([$reason, $reason], $reasons, 'The class-level message was not carried as the skip reason.');
        } finally {
            $this->remove($directory);
        }
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function run(array $command, string $cwd): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** Windows and git leave read-only files behind; tear them down explicitly. */
    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                chmod($path, 0o666);
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
