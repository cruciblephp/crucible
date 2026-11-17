<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Framework\TestCase;

use function array_filter;
use function array_values;
use function chmod;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A rename reaches `--dirty` as what it is at the filesystem level: the
 * old path deleted and the new one added (D-036). Without the deleted
 * half, a renamed dependency selects nothing and the run is green
 * without having run the test that would catch it.
 */
final class ChangedFilesDirtyTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-dirty-rename-' . uniqid();

        mkdir($root . '/tests', 0o777, true);
        mkdir($root . '/src', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()->testSuite('unit', 'tests')->build();
            PHP);

        file_put_contents($this->project . '/src/Thing.php', '<?php // the renamed dependency');
        file_put_contents($this->project . '/tests/FirstTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class FirstTest extends TestCase
            {
                public function testFirst(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);
        file_put_contents($this->project . '/tests/SecondTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class SecondTest extends TestCase
            {
                public function testSecond(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        $this->git('init -q');
        $this->git('config user.email crucible@example.test');
        $this->git('config user.name Crucible');
        $this->git('add .');
        $this->git('commit -q -m base');
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testARenamedDependencyWidensTheSelectionThroughDirty(): void
    {
        $this->git('mv src/Thing.php src/Moved.php');

        [$exit, $stdout, $events] = $this->dirty();

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('No tests are affected', $stdout);
        self::assertSame(
            ['tests/FirstTest.php::testFirst', 'tests/SecondTest.php::testSecond'],
            $this->ranIds($events),
            'A rename must keep both halves of the suite in scope.',
        );
    }

    public function testARenameIsReportedThroughChangedAsWell(): void
    {
        $this->git('mv src/Thing.php src/Moved.php');

        [$exit, $stdout, $events] = $this->crucible(['--changed=HEAD']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('deleted file(s)', $stdout);
        self::assertCount(2, $this->ranIds($events), $stdout);
    }

    private function dirty(): array
    {
        return $this->crucible(['--dirty']);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<string>}
     */
    private function crucible(array $arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible', ...$arguments, '--log-events-json', $events],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit     = proc_close($process);
        $contents = is_file($events) ? (string) file_get_contents($events) : '';

        if ($contents === '') {
            return [$exit, $stdout . $stderr, []];
        }

        /** @var list<string> $lines */
        $lines = array_values(array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== ''));

        return [$exit, $stdout, $lines];
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function ranIds(array $events): array
    {
        $ids = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $ids[] = $decoded['id'];
            }
        }

        return $ids;
    }

    private function git(string $command): void
    {
        $process = proc_open(
            ['git', '-C', $this->project, ...explode(' ', $command)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('git ' . $command . ' could not be started.');
        }

        $error = (string) stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            self::fail('git ' . $command . ' failed: ' . $error);
        }
    }

    /** Git leaves its objects read-only, which Windows will not unlink until told otherwise. */
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
