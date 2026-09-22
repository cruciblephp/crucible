<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Subscriber;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function fclose;
use function fopen;
use function sprintf;

/**
 * The file-lifecycle half of writing a Subscriber that owns a path
 * param (open at construction, fail loudly if that fails, close on
 * the terminal `RunFinished`) — extracted so a Subscriber author only
 * ever has to write {@see write()}'s actual formatting logic, not
 * re-derive this open/fail/close dance each time. `handle()` is
 * `final` on purpose: the lifecycle it enforces is the whole point of
 * extending this class rather than implementing `SubscriberContract`
 * directly.
 */
abstract class FileBackedSubscriber implements SubscriberContract
{
    /** @var resource */
    protected $stream;

    public function __construct(string $output, string $label)
    {
        $stream = fopen($output, 'w');

        if ($stream === false) {
            throw new ConfigurationException(sprintf('Cannot open "%s" for %s.', $output, $label));
        }

        $this->stream = $stream;
    }

    final public function handle(Envelope $envelope): void
    {
        $this->write($envelope);

        if ($envelope->event instanceof RunFinished) {
            fclose($this->stream);
        }
    }

    abstract protected function write(Envelope $envelope): void;
}
