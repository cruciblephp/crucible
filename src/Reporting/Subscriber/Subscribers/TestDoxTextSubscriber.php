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
 * The spec's --testdox-text target, on the subscriber path every
 * other file-backed writer takes. The listing itself is
 * {@see TestDoxDocument}'s, shared with the other two spellings so no
 * file target can disagree with another about what ran.
 */
#[Subscriber(
    key: 'testdox-text',
    description: 'Write the documentation view as plain text.',
    comment: 'The same per-file, pretty-named listing the terminal view draws, in a file a reviewer can read without running anything.',
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class TestDoxTextSubscriber extends FileBackedSubscriber
{
    private readonly TestDoxDocument $document;

    public function __construct(string $output)
    {
        parent::__construct($output, 'the testdox text subscriber');

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
            fwrite($this->stream, $this->document->text($event));
        }
    }
}
