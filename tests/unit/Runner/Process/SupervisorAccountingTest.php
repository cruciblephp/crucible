<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use ArrayObject;
use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\RequiresOperatingSystem;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\Process\EventParser;
use LucianoPereira\Crucible\Runner\Process\Supervisor;
use LucianoPereira\Crucible\Runner\Process\WorkerProcess;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function dirname;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

/**
 * A worker that never starts is a lost unit, not a complete run (D-071).
 * The supervisor used to report its tests as errored and leave the
 * completeness flag alone, so a spawn failure could still claim the
 * run had visited everything.
 */
#[CoversClass(Supervisor::class)]
final class SupervisorAccountingTest extends TestCase
{
    public function testAForeignStartIsNotForwardedToTheOneTrueStream(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'crucible-foreign-start-');

        if ($script === false) {
            self::fail('The fake worker script could not be created.');
        }

        $frames = [
            '{"event":"test:start","id":"tests/ForeignTest.php::foreign"}',
            '{"event":"test:start","id":"tests/OrphanTest.php::testOrphan"}',
            '{"event":"test:finish","id":"tests/OrphanTest.php::testOrphan","outcome":"pass","duration":0.0}',
            '{"event":"run:finish","counts":{"pass":1}}',
        ];
        $lines = '';

        foreach ($frames as $frame) {
            $lines .= 'fwrite(STDERR, ' . var_export(WorkerProcess::EVENT_MARKER . $frame . "\n", true) . ');' . "
";
        }

        file_put_contents($script, '<?php stream_get_contents(STDIN);' . "
" . $lines . 'exit(0);');

        try {
            $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));

            /** @var ArrayObject<int, Envelope> $events */
            $events = new ArrayObject();

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

            $group = new TestGroup(
                'Case',
                [
                    new TestDefinition(
                        new TestId('tests/OrphanTest.php', 'testOrphan'),
                        static fn(): null => null,
                        MetadataCollection::from(),
                    ),
                ],
            );

            $summary = (new Supervisor(
                emitter: $emitter,
                inProcessRunner: new TestRunner($emitter),
                history: new ResultCache(),
                parser: new EventParser(),
                crucibleBinary: $script,
                configurationPath: dirname(__DIR__, 4) . '/crucible.php',
                workingDirectory: new WorkingDirectory(dirname(__DIR__, 4)),
                parallel: 2,
                fullSuite: true,
            ))->run([$group]);

            self::assertSame(1, $summary->passed);

            $starts   = [];
            $complete = null;

            foreach ($events as $envelope) {
                if ($envelope->event instanceof TestStarted) {
                    $starts[] = $envelope->event->test->toString();
                }

                if ($envelope->event instanceof RunFinished) {
                    $complete = $envelope->event->complete;
                }
            }

            self::assertSame(['tests/OrphanTest.php::testOrphan'], $starts, 'A foreign start reached the stream.');
            self::assertFalse($complete, 'A foreign start is a protocol violation, not proof the plan was visited.');
        } finally {
            @unlink($script);
        }
    }

    #[RequiresOperatingSystem('^(?!WIN)')]
    public function testAWorkerThatCannotBeSpawnedIsNotACompleteRun(): void
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-07-14T12:00:00+00:00')));

        /** @var ArrayObject<int, Envelope> $events */
        $events = new ArrayObject();

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

        $group = new TestGroup(
            'Case',
            [
                new TestDefinition(
                    new TestId('tests/OrphanTest.php', 'testOrphan'),
                    static fn(): null => null,
                    MetadataCollection::from(),
                ),
            ],
        );

        $summary = (new Supervisor(
            emitter: $emitter,
            inProcessRunner: new TestRunner($emitter),
            history: new ResultCache(),
            parser: new EventParser(),
            crucibleBinary: dirname(__DIR__, 4) . '/does-not-exist-crucible',
            configurationPath: dirname(__DIR__, 4) . '/crucible.php',
            workingDirectory: new WorkingDirectory(dirname(__DIR__, 4)),
            parallel: 2,
            fullSuite: true,
        ))->run([$group]);

        self::assertSame(1, $summary->errored, 'The unstartable unit was not reported as an error.');

        $finished = null;

        foreach ($events as $envelope) {
            if ($envelope->event instanceof RunFinished) {
                $finished = $envelope->event;
            }
        }

        self::assertInstanceOf(RunFinished::class, $finished);
        self::assertFalse($finished->complete, 'A spawn failure must not claim the plan was visited.');

        $ids = [];

        foreach ($events as $envelope) {
            if ($envelope->event instanceof TestFinished) {
                $ids[] = $envelope->event->test->toString();
            }
        }

        self::assertSame(['tests/OrphanTest.php::testOrphan'], $ids);
    }
}
