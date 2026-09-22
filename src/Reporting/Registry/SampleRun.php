<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Registry;

use DateTimeImmutable;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Test\TestId;

/**
 * A small, real run through the real event machinery — for
 * `crucible extensions --preview` on any Listener-based plugin kind
 * (subscriber, progress view), which has no static Document to render
 * (unlike a report format's {@see \LucianoPereira\Crucible\Reporting\Document\SampleDocument}):
 * such a plugin only shows what it does by actually receiving events.
 * Built from a real `Emitter` and real event classes, the same fixture
 * pattern already used throughout `tests/unit/Reporting/ReportersTest.php`,
 * not a hand-built `Envelope` shortcut.
 */
final class SampleRun
{
    public static function through(Listener $listener): void
    {
        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $emitter->subscribe($listener);

        $passing = new TestId('tests/unit/ExampleTest.php', 'testAdds');
        $failing = new TestId('tests/unit/ExampleTest.php', 'testSubtracts');

        $emitter->emit(new RunStarted());

        $emitter->emit(new TestStarted($passing));
        $emitter->emit(new TestFinished($passing, Outcome::Passed, 0.010));

        $emitter->emit(new TestStarted($failing));
        $emitter->emit(new TestFinished($failing, Outcome::Failed, 0.020, new Failure('Expected 4, got 5.')));

        $emitter->emit(new RunFinished(new RunSummary(passed: 1, failed: 1), 0.030));
    }
}
