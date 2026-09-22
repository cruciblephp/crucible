<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Subscriber\Subscribers;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Reporting\JUnitXmlWriter;
use LucianoPereira\Crucible\Reporting\Subscriber\FileBackedSubscriber;
use LucianoPereira\Crucible\Reporting\Subscriber\Subscriber;

/**
 * Adapts the untouched {@see JUnitXmlWriter} (which takes an
 * already-open `resource $stream`) onto the Subscriber role, whose
 * params reach the constructor rather than `handle()`: this class
 * opens its own stream from a path param, exactly like
 * `GenericReportWriter` opens its own stream from a report format's
 * "output" param. The open/fail/close lifecycle itself lives on
 * {@see FileBackedSubscriber} — this class only adapts
 * {@see JUnitXmlWriter}'s own `handle()` into {@see write()}.
 */
#[Subscriber(
    key: 'junit',
    description: 'Write a JUnit XML report, for CI.',
    comment: 'Plain string building, no DOM dependency. Granularity matches PHPUnit\'s own JUnit report (D-018): risky reports as a plain pass, incomplete as skipped.',
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class JUnitSubscriber extends FileBackedSubscriber
{
    private readonly JUnitXmlWriter $inner;

    public function __construct(string $output)
    {
        parent::__construct($output, 'the JUnit subscriber');

        $this->inner = new JUnitXmlWriter($this->stream);
    }

    protected function write(Envelope $envelope): void
    {
        $this->inner->handle($envelope);
    }
}
