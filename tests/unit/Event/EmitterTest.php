<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Event;

use ArrayObject;
use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\SuiteStarted;
use LucianoPereira\Crucible\Framework\TestCase;

use function iterator_to_array;

#[CoversClass(Emitter::class)]
#[CoversClass(Envelope::class)]
final class EmitterTest extends TestCase
{
    public function testStampsGaplessOneBasedSequenceAndClockTime(): void
    {
        $instant = new DateTimeImmutable('2026-07-14T12:00:00.000000+00:00');
        $emitter = new Emitter(new FrozenClock($instant));

        $first  = $emitter->emit(new RunStarted('0.1.0', '8.5.7'));
        $second = $emitter->emit(new SuiteStarted('unit'));

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame($instant, $first->timestamp);
    }

    public function testDeliversToListenersInSubscriptionOrder(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();

        $listener = static fn(string $label): Listener => new readonly class ($label, $log) implements Listener {
            /**
             * @param ArrayObject<int, string> $log
             */
            public function __construct(
                private string $label,
                private ArrayObject $log,
            ) {}

            public function handle(Envelope $envelope): void
            {
                $this->log->append($this->label . ':' . $envelope->sequence);
            }
        };

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable()));
        $emitter->subscribe($listener('a'));
        $emitter->subscribe($listener('b'));

        $emitter->emit(new SuiteStarted('unit'));
        $emitter->emit(new SuiteStarted('feature'));

        $this->assertSame(['a:1', 'b:1', 'a:2', 'b:2'], iterator_to_array($log));
    }
}
