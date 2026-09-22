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
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Subscriber\{FileBackedSubscriber, Subscriber};
use LucianoPereira\Crucible\Reporting\TestDoxDocument;

use function fwrite;

/**
 * The spec's --testdox-html target, on the subscriber path every
 * other file-backed writer takes. The listing itself is
 * {@see TestDoxDocument}'s, shared with the other two spellings so no
 * file target can disagree with another about what ran.
 */
#[Subscriber(
    key: 'testdox-html',
    description: 'Write the documentation view as a standalone HTML page.',
    comment: 'Self-contained: inline styles, no scripts, no requests — openable straight from a CI artifact.',
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class TestDoxHtmlSubscriber extends FileBackedSubscriber
{
    private readonly TestDoxDocument $document;

    public function __construct(string $output)
    {
        parent::__construct($output, 'the testdox html subscriber');

        $this->document = new TestDoxDocument();
    }

    protected function write(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $this->document->record($event);

            return;
        }

        if ($event instanceof RunFinished) {
            fwrite($this->stream, $this->document->html($event));
        }
    }
}
