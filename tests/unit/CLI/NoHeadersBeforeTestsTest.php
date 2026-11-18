<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Output\CliOutput;
use LucianoPereira\Crucible\Framework\TestCase;

use function chmod;
use function dirname;
use function fclose;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
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
 * A production kernel starting a native session checks headers_sent().
 * The no-colour banner used printf(), so the check failed on every run;
 * colour was safe because Splash writes through the terminal (F-01,
 * ticket #2 item 1). The fixture test asserts the flag is still false
 * when the test body runs.
 */
#[CoversClass(CliOutput::class)]
final class NoHeadersBeforeTestsTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-headers-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testTheNoColourBannerDoesNotSendHeaders(): void
    {
        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()->testSuite('unit', 'tests');
            PHP);

        file_put_contents($this->project . '/tests/HeadersProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class HeadersProbeTest extends TestCase
            {
                public function testTheCliSentNoHeaders(): void
                {
                    $sent = headers_sent($file, $line);

                    self::assertFalse(
                        $sent,
                        'Headers were sent by ' . ($file ?? '?') . ':' . ($line ?? '?') . ' before the first test.',
                    );
                }
            }
            PHP);

        $exit = $this->crucible();

        self::assertSame(0, $exit);
    }

    /**
     * @return int
     */
    private function crucible(): int
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible'],
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

        $exit = proc_close($process);

        if ($exit !== 0) {
            self::fail('crucible exited ' . $exit . '. stdout: ' . $stdout . ' stderr: ' . $stderr);
        }

        return $exit;
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
