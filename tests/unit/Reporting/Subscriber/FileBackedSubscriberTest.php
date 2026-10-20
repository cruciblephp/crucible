<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Subscriber;

use DateTimeImmutable;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Clock\FrozenClock;
use LucianoPereira\Crucible\Event\{Emitter, Envelope, RunFinished, RunStarted, RunSummary};
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Subscriber\FileBackedSubscriber;

use function file_put_contents;
use function is_dir;
use function is_file;
use function is_resource;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

/**
 * Exercised through a small recording subclass rather than
 * {@see \LucianoPereira\Crucible\Reporting\Subscriber\Subscribers\JUnitSubscriber} —
 * this is the lifecycle {@see FileBackedSubscriber} itself owns
 * (open/fail/close), independent of any one subscriber's own
 * formatting logic.
 */
#[CoversClass(FileBackedSubscriber::class)]
final class FileBackedSubscriberTest extends TestCase
{
    public function testOpenFailureThrowsNamingTheGivenLabel(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Cannot open "' . sys_get_temp_dir() . '/crucible-missing-' . 'dir/out.txt" for the test fixture.');

        // Told not to create the directory, as the OTR subscriber is (D-148).
        @new RecordingFileBackedSubscriber(sys_get_temp_dir() . '/crucible-missing-dir/out.txt', 'the test fixture', createDirectory: false);
    }

    public function testAMissingDirectoryIsCreatedAsTheIncumbentCreatesIt(): void
    {
        $root = sys_get_temp_dir() . '/' . uniqid('crucible-filebacked-', true);
        $path = $root . '/logs/junit/out.txt';

        new RecordingFileBackedSubscriber($path, 'the test fixture');

        $this->assertTrue(is_file($path));

        unlink($path);
        rmdir($root . '/logs/junit');
        rmdir($root . '/logs');
        rmdir($root);
    }

    public function testADirectoryThatCannotBeCreatedIsAConfigurationError(): void
    {
        // A regular file where a directory is needed: no user can create it.
        $blocker = tempnam(sys_get_temp_dir(), 'crucible-blocker-');
        self::assertNotFalse($blocker);
        file_put_contents($blocker, '');

        try {
            new RecordingFileBackedSubscriber($blocker . '/out.txt', 'the test fixture');
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            $this->assertSame('Cannot create the directory of "' . $blocker . '/out.txt" for the test fixture.', $e->getMessage());
        } finally {
            unlink($blocker);
        }

        $this->assertFalse(is_dir($blocker));
    }

    public function testWriteReceivesEveryEnvelopeAndTheStreamClosesOnRunFinished(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-filebacked-');
        self::assertNotFalse($path);

        $subscriber = new RecordingFileBackedSubscriber($path, 'the test fixture');

        $emitter = new Emitter(new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $emitter->subscribe($subscriber);

        $emitter->emit(new RunStarted());
        $emitter->emit(new RunFinished(new RunSummary(), 0.0));

        $this->assertCount(2, $subscriber->received);
        $this->assertFalse(is_resource($subscriber->stream()));

        @unlink($path);
    }
}

final class RecordingFileBackedSubscriber extends FileBackedSubscriber
{
    /** @var list<Envelope> */
    public array $received = [];

    protected function write(Envelope $envelope): void
    {
        $this->received[] = $envelope;
    }

    public function stream(): mixed
    {
        return $this->stream;
    }
}
