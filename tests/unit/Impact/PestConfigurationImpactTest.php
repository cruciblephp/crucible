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
use function getenv;
use function implode;
use function is_array;
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
use function str_replace;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A `*Test.php` file written in Pest loads the ancestor `Pest.php` and
 * `Datasets/*.php` files exactly like an explicit `.pest.php` one
 * (D-033). Impact selection used to add those edges only for the two
 * explicit suffixes, so editing a suite configuration selected nothing
 * from the Pest files named like PHPUnit ones — the ordinary shape of a
 * real Pest project (F-14).
 */
final class PestConfigurationImpactTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-pest-impact-' . uniqid();

        mkdir($root . '/tests/Feature', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testAPestFileNamedLikePhpUnitIsSelectedByItsConfigurationEdit(): void
    {
        $this->suite();
        $this->editConfiguration('tests/Pest.php', 'loaded', 'changed');

        [$exit, $stdout, $cold] = $this->dirty();

        self::assertNotSame(0, $exit, 'The configuration change was not selected.');
        self::assertStringNotContainsString('No tests are affected', $stdout);
        $this->assertPestFamilyRan($cold);

        // The second run answers from the dependency index; the same
        // configuration edges must survive the replay.
        [$exit, $stdout, $warm] = $this->dirty();

        self::assertNotSame(0, $exit);
        self::assertStringNotContainsString('No tests are affected', $stdout);
        $this->assertPestFamilyRan($warm);
    }

    public function testANestedConfigurationEditSelectsOnlyItsSubtree(): void
    {
        $this->suite();
        $this->editConfiguration('tests/Feature/Pest.php', 'nested', 'changed');

        [$exit, , $events] = $this->dirty();

        self::assertNotSame(0, $exit);
        self::assertSame(
            ['tests/Feature/FeaturePestTest.php::reads the nested configuration'],
            $this->ranIds($events),
            'Only the subtree under the edited configuration should run.',
        );
    }

    public function testAnAddedConfigurationAboveAnUnchangedTestSelectsIt(): void
    {
        $this->suite(withRootConfiguration: false);

        file_put_contents($this->project . '/tests/Pest.php', $this->rootConfiguration('loaded'));

        [$exit, $stdout, $events] = $this->dirty();

        self::assertSame(0, $exit, 'The added configuration should make the suite pass.');
        self::assertStringNotContainsString('No tests are affected', $stdout);
        $this->assertPestFamilyRan($events);
    }

    public function testADeletedConfigurationStillWidensTheRun(): void
    {
        $this->suite();
        unlink($this->project . '/tests/Pest.php');

        [$exit, $stdout, $events] = $this->dirty();

        self::assertNotSame(0, $exit, 'The suite now fails without its configuration.');
        self::assertStringContainsString('deleted file(s)', $stdout);
        self::assertContains('tests/PhpUnitStyleTest.php::testControl', $this->ranIds($events));
        $this->assertPestFamilyRan($events, widened: true);
    }

    /**
     * @param list<string> $events
     */
    private function assertPestFamilyRan(array $events, bool $widened = false): void
    {
        $ids = $this->ranIds($events);

        foreach ([
            'tests/PestStyleTest.php::reads the root configuration',
            'tests/Explicit.pest.php::an explicit pest file reads the root configuration',
            'tests/Native.crucible.php::\\check(fn(): mixed => $this->fromSuiteConfig)->toBe(\'loaded\');',
            'tests/Feature/FeaturePestTest.php::reads the nested configuration',
        ] as $id) {
            self::assertContains($id, $ids, 'A Pest-family file lost its configuration edge. Ran: ' . implode(', ', $ids));
        }

        if (!$widened) {
            self::assertNotContains(
                'tests/PhpUnitStyleTest.php::testControl',
                $ids,
                'A PHPUnit sibling was selected by a Pest-only change.',
            );
        }
    }

    private function suite(bool $withRootConfiguration = true): void
    {
        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()->testSuite('unit', 'tests')->build();
            PHP);

        file_put_contents($this->project . '/.gitignore', ".crucible.cache/\nevents.ndjson\n");

        if ($withRootConfiguration) {
            file_put_contents($this->project . '/tests/Pest.php', $this->rootConfiguration('loaded'));
        }

        file_put_contents($this->project . '/tests/Feature/Pest.php', $this->nestedConfiguration('nested'));

        file_put_contents($this->project . '/tests/PestStyleTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            \test('reads the root configuration', function (): void {
                \expect($this->fromSuiteConfig)->toBe('loaded');
            });
            PHP);

        file_put_contents($this->project . '/tests/Explicit.pest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            \test('an explicit pest file reads the root configuration', function (): void {
                \expect($this->fromSuiteConfig)->toBe('loaded');
            });
            PHP);

        file_put_contents($this->project . '/tests/Native.crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            \check(fn(): mixed => $this->fromSuiteConfig)->toBe('loaded');
            PHP);

        file_put_contents($this->project . '/tests/Feature/FeaturePestTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            \test('reads the nested configuration', function (): void {
                \expect($this->fromNestedConfig)->toBe('nested');
                \expect($this->fromSuiteConfig)->toBe('loaded');
            });
            PHP);

        file_put_contents($this->project . '/tests/PhpUnitStyleTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class PhpUnitStyleTest extends TestCase
            {
                public function testControl(): void
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

    private function rootConfiguration(string $value): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            \\pest()->beforeEach(function (): void {
                \$this->fromSuiteConfig = '{$value}';
            });
            PHP;
    }

    private function nestedConfiguration(string $value): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            \\pest()->beforeEach(function (): void {
                \$this->fromNestedConfig = '{$value}';
            });
            PHP;
    }

    private function editConfiguration(string $file, string $from, string $to): void
    {
        $contents = (string) file_get_contents($this->project . '/' . $file);

        file_put_contents($this->project . '/' . $file, str_replace("'{$from}'", "'{$to}'", $contents));
    }

    /**
     * @return array{int, string, list<string>}
     */
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
            self::fail('No event stream was written. stdout: ' . $stdout . ' stderr: ' . $stderr);
        }

        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn(string $line): bool => $line !== '',
        ));

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
