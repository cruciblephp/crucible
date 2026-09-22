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
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Reporting\Subscriber\{FileBackedSubscriber, Subscriber};

use function fwrite;
use function gethostname;
use function htmlspecialchars;
use function php_uname;
use function sprintf;

use const ENT_QUOTES;
use const ENT_XML1;
use const PHP_EOL;
use const PHP_VERSION;
use const ZEND_THREAD_SAFE;

/**
 * Open Test Reporting: the opentest4j event schema, which is a *stream*
 * of started/finished events with ids rather than a tree of results —
 * the one interop format shaped like Crucible's own event stream rather
 * than like a summary of it, so the mapping is nearly one to one.
 *
 * Written straight out as the events arrive, which is what the schema
 * is for; nothing is buffered and no run needs to end for the file to
 * be readable up to that point.
 */
#[Subscriber(
    key: 'otr',
    description: 'Write an Open Test Reporting (opentest4j) event stream.',
    comment: 'The one interop format shaped like an event stream rather than a summary, so it is written live rather than assembled at the end.',
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class OtrSubscriber extends FileBackedSubscriber
{
    private int $nextId = 1;

    /** @var array<string, int> test id string => the OTR id it was started under */
    private array $ids = [];

    public function __construct(string $output)
    {
        parent::__construct($output, 'the Open Test Reporting subscriber');
    }

    protected function write(Envelope $envelope): void
    {
        $event = $envelope->event;
        $time  = $envelope->timestamp->format('Y-m-d\TH:i:s.uP');

        if ($event instanceof RunStarted) {
            $this->prologue($event, $time);

            return;
        }

        if ($event instanceof TestStarted) {
            $id = $this->nextId++;

            $this->ids[$event->test->toString()] = $id;

            fwrite($this->stream, sprintf(
                '  <e:started id="%d" name="%s" time="%s"/>' . PHP_EOL,
                $id,
                $this->escape($event->test->toString()),
                $this->escape($time),
            ));

            return;
        }

        if ($event instanceof TestFinished) {
            $this->finished($event, $time);

            return;
        }

        if ($event instanceof RunFinished) {
            fwrite($this->stream, '</e:events>' . PHP_EOL);
        }
    }

    private function prologue(RunStarted $event, string $time): void
    {
        $host = gethostname();

        fwrite($this->stream, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<e:events xmlns="https://schemas.opentest4j.org/reporting/core/0.2.0"'
            . ' xmlns:e="https://schemas.opentest4j.org/reporting/events/0.2.0"'
            . ' xmlns:php="https://schema.phpunit.de/otr/php/0.1.0">' . PHP_EOL
            . '  <infrastructure>' . PHP_EOL
            . '    <hostName>%s</hostName>' . PHP_EOL
            . '    <operatingSystem>%s</operatingSystem>' . PHP_EOL
            . '    <php:phpVersion>%s</php:phpVersion>' . PHP_EOL
            . '    <php:threadModel>%s</php:threadModel>' . PHP_EOL
            . '  </infrastructure>' . PHP_EOL
            . '  <!-- crucible %s, run started %s -->' . PHP_EOL,
            $this->escape($host === false ? 'unknown' : $host),
            $this->escape(php_uname('s')),
            $this->escape(PHP_VERSION),
            ZEND_THREAD_SAFE ? 'ZTS' : 'NTS',
            $this->escape($event->crucibleVersion),
            $this->escape($time),
        ));
    }

    private function finished(TestFinished $event, string $time): void
    {
        $key = $event->test->toString();
        $id  = $this->ids[$key] ?? $this->nextId++;

        unset($this->ids[$key]);

        // The schema's five statuses. A risky test passed its
        // assertions and failed its conditions, which is what ABORTED
        // means here; a blocked skip (D-075) is still a skip.
        $status = match ($event->outcome) {
            Outcome::Passed  => 'SUCCESSFUL',
            Outcome::Failed  => 'FAILED',
            Outcome::Errored => 'ERRORED',
            Outcome::Skipped,
            Outcome::Incomplete => 'SKIPPED',
            Outcome::Risky      => 'ABORTED',
        };

        $reason = $event->failure->message ?? $event->reason;

        fwrite($this->stream, sprintf(
            '  <e:finished id="%d" time="%s">' . PHP_EOL
            . '    <result status="%s">%s</result>' . PHP_EOL
            . '  </e:finished>' . PHP_EOL,
            $id,
            $this->escape($time),
            $status,
            $reason === null ? '' : PHP_EOL . '      <reason>' . $this->escape($reason) . '</reason>' . PHP_EOL . '    ',
        ));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
