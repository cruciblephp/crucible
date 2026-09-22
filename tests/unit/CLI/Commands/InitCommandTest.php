<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\CLI\Commands;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\Commands\InitCommand;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_fill;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(InitCommand::class)]
final class InitCommandTest extends TestCase
{
    /** @var non-empty-string */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crucible-init-cmd-test-' . uniqid();
        mkdir($this->dir, 0o777, true);
    }

    /**
     * A text prompt's default value pre-fills the buffer with the
     * cursor at the end, so typing appends to it rather than replacing
     * it — send one backspace per character of the default first.
     *
     * @return list<string>
     */
    private function clear(string $default): array
    {
        return array_fill(0, strlen($default), "\x7f");
    }

    protected function tearDown(): void
    {
        Runtime::reset();

        foreach ([$this->dir . '/crucible.php', $this->dir . '/phpunit.xml'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testNonInteractiveTerminalWritesThePriorStaticTemplateUnchanged(): void
    {
        Runtime::setTerminal(new FakeTerminal(interactive: false));

        ob_start();
        $exit = (new InitCommand())->execute(new WorkingDirectory($this->dir));
        ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\n\n"
                . "return Crucible::configure()\n    ->bootstrap('vendor/autoload.php')\n"
                . "    ->testSuite('unit', 'tests/Unit')\n    // Read by coverage, impact selection and architecture rules.\n    ->source(include: ['src'])\n    ->strict();\n",
            (string) file_get_contents($this->dir . '/crucible.php'),
        );
    }

    public function testInteractivePromptsCustomizeTheGeneratedConfig(): void
    {
        Runtime::setTerminal(new FakeTerminal([
            ...$this->clear('tests/Unit'), 't', 'e', 's', 't', 's', "\n", // test directory
            ...$this->clear('src'), 'a', 'p', 'p', "\n", // source directory
            'n', // strict? no
        ]));

        ob_start();
        $exit = (new InitCommand())->execute(new WorkingDirectory($this->dir));
        ob_get_clean();

        $this->assertSame(0, $exit);

        $written = (string) file_get_contents($this->dir . '/crucible.php');

        // The suite is named after the directory it holds: answering
        // "tests" gives a suite called tests, not one called unit that
        // --testsuite would then resolve to something else.
        $this->assertStringContainsString("->testSuite('tests', 'tests')", $written);
        $this->assertStringContainsString("->source(include: ['app'])", $written);
        $this->assertStringNotContainsString('->strict()', $written);
    }

    public function testDeclinedOverwriteKeepsTheExistingFile(): void
    {
        file_put_contents($this->dir . '/crucible.php', "<?php\n// hand-written\n");
        Runtime::setTerminal(new FakeTerminal(['n']));

        ob_start();
        $exit = (new InitCommand())->execute(new WorkingDirectory($this->dir));
        ob_get_clean();

        $this->assertSame(1, $exit);
        $this->assertSame("<?php\n// hand-written\n", (string) file_get_contents($this->dir . '/crucible.php'));
    }

    public function testConfirmedOverwriteReplacesTheFile(): void
    {
        file_put_contents($this->dir . '/crucible.php', "<?php\n// hand-written\n");
        Runtime::setTerminal(new FakeTerminal([
            'y', // overwrite? yes
            "\n", // test directory: accept default
            "\n", // source directory: accept default
            'y', // strict? yes
        ]));

        ob_start();
        $exit = (new InitCommand())->execute(new WorkingDirectory($this->dir));
        ob_get_clean();

        $this->assertSame(0, $exit);

        $written = (string) file_get_contents($this->dir . '/crucible.php');

        $this->assertStringContainsString("->testSuite('unit', 'tests/Unit')", $written);
        $this->assertStringContainsString("->source(include: ['src'])", $written);
        $this->assertStringContainsString('->strict()', $written);
    }

    /**
     * The typed directory is interpolated straight into generated PHP
     * source, so the prompt's validator — not just a happy-path shape —
     * is what stands between a stray quote and broken (or injected)
     * output. Reject-then-correct proves the guard actually blocks
     * submission rather than just decorating the field with an error.
     */
    public function testUnsafeCharactersAreRejectedUntilCorrected(): void
    {
        Runtime::setTerminal(new FakeTerminal([
            ...$this->clear('tests/Unit'), "'", "\n", // rejected: not a safe path character
            "\x7f", // backspace the quote
            't', 'e', 's', 't', 's', "\n", // corrected and submitted
            "\n", // source directory: accept default
            'n', // strict? no
        ]));

        ob_start();
        $exit = (new InitCommand())->execute(new WorkingDirectory($this->dir));
        ob_get_clean();

        $this->assertSame(0, $exit);

        $written = (string) file_get_contents($this->dir . '/crucible.php');

        // The rejected quote never reached the written value: the
        // corrected directory renders exactly as if it had been typed
        // that way from the start, not with a leaked/escaped quote
        // still attached.
        // The suite is named after the directory it holds: answering
        // "tests" gives a suite called tests, not one called unit that
        // --testsuite would then resolve to something else.
        $this->assertStringContainsString("->testSuite('tests', 'tests')", $written);
    }

    public function testExistingPhpunitXmlIsConvertedInsteadOfPrompting(): void
    {
        file_put_contents($this->dir . '/phpunit.xml', "<?xml version=\"1.0\"?>\n<phpunit><testsuites><testsuite name=\"unit\"><directory>tests</directory></testsuite></testsuites></phpunit>\n");
        Runtime::setTerminal(new FakeTerminal(interactive: false));

        ob_start();
        $exit   = (new InitCommand())->execute(new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Found phpunit.xml', $output);
        $this->assertTrue(is_file($this->dir . '/crucible.php'));
    }
}
