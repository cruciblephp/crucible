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
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\Commands\ExtensionsCommand;
use LucianoPereira\Crucible\Console\Input\Key;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function rmdir;
use function simplexml_load_string;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * All three registries (`ReportFormatRegistry`/`SubscriberRegistry`/
 * `ProgressViewRegistry`) are pre-seeded by `Builder::__construct()`
 * with `pdf`/`markdown`, `junit`, and `console`/`testdox`/`teamcity`
 * respectively, so a bare `Crucible::configure()->build();`
 * crucible.php already exercises list/detail/preview across all three
 * kinds — no bespoke fixture classes needed.
 */
#[CoversClass(ExtensionsCommand::class)]
final class ExtensionsCommandTest extends TestCase
{
    /** @var non-empty-string */
    private string $dir;

    protected function setUp(): void
    {
        // ⚠ The command prompts now, and a prompt without this drives the
        // REAL terminal: run at a TTY, this test drew a select list into
        // the middle of the suite's own progress output and then blocked
        // waiting for a keypress. A test may never own the screen.
        Runtime::setTerminal(new FakeTerminal(interactive: false));

        $this->dir = sys_get_temp_dir() . '/crucible-extensions-cmd-test-' . uniqid();
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir . '/crucible.php', "<?php\nreturn LucianoPereira\\Crucible\\Configuration\\Crucible::configure()->build();\n");
    }

    protected function tearDown(): void
    {
        Runtime::reset();

        foreach ([$this->dir . '/crucible.php', $this->dir . '/out.txt'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testListShowsAllThreeKindsWithAKindColumn(): void
    {
        $options = $this->parse(['extensions']);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('KIND', $output);
        $this->assertStringContainsString('report-format', $output);
        $this->assertStringContainsString('subscriber', $output);
        $this->assertStringContainsString('progress-view', $output);
        $this->assertStringContainsString('pdf', $output);
        $this->assertStringContainsString('markdown', $output);
        $this->assertStringContainsString('junit', $output);
        $this->assertStringContainsString('console', $output);
        $this->assertStringContainsString('testdox', $output);
        $this->assertStringContainsString('teamcity', $output);
    }

    public function testListJsonTagsEachEntryWithItsKind(): void
    {
        $options = $this->parse(['extensions', '--json']);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--json'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);

        /** @var list<array{kind: string, key: string}> $rows */
        $rows  = json_decode($output, true);
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row['kind'];
        }

        $this->assertSame('report-format', $byKey['pdf']);
        $this->assertSame('report-format', $byKey['markdown']);
        $this->assertSame('subscriber', $byKey['junit']);
        $this->assertSame('progress-view', $byKey['console']);
        $this->assertSame('progress-view', $byKey['testdox']);
        $this->assertSame('progress-view', $byKey['teamcity']);
    }

    public function testDetailFindsAReportFormatByKey(): void
    {
        $options = $this->parse(['extensions', '--key=pdf']);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--key=pdf'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('pdf', $output);
        $this->assertStringContainsString('Available: yes', $output);
    }

    public function testDetailFindsASubscriberByKey(): void
    {
        $options = $this->parse(['extensions', '--key=junit']);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--key=junit'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('junit', $output);
        $this->assertStringContainsString('Available: yes', $output);
        $this->assertStringContainsString('output', $output); // its required param, listed
    }

    public function testDetailFindsAProgressViewByKey(): void
    {
        $options = $this->parse(['extensions', '--key=teamcity']);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--key=teamcity'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('teamcity', $output);
        $this->assertStringContainsString('Available: yes', $output);
    }

    public function testDetailUnknownKeyErrorsAcrossAllRegistries(): void
    {
        $options = $this->parse(['extensions', '--key=nope']);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--key=nope'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not registered as a report format, a subscriber, or a progress view', $output);
    }

    public function testPreviewWithoutKeyErrors(): void
    {
        $options = $this->parse(['extensions', '--preview']);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--preview'], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--preview requires --key', $output);
        $terminal = Runtime::terminal();

        $this->assertInstanceOf(FakeTerminal::class, $terminal);
        $this->assertSame('', $terminal->output(), 'and no prompt was drawn to the terminal');
    }

    /**
     * The other half of {@see testPreviewWithoutKeyErrors}: answered.
     *
     * ⚠ That test proves the prompt REFUSES off a terminal, which is
     * what a script gets. Nothing proved the prompt works when someone
     * is there to answer it — and the command has no other way in.
     */
    public function testPreviewWithoutAKeyAsksAndPreviewsTheAnswer(): void
    {
        $path = $this->dir . '/out.txt';

        Runtime::setTerminal(new FakeTerminal([Key::ENTER]));

        $options = $this->parse(['extensions', '--preview', '--out=' . $path]);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--preview', '--out=' . $path], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $terminal = Runtime::terminal();

        $this->assertInstanceOf(FakeTerminal::class, $terminal);
        $this->assertStringContainsString('Which plugin should be previewed?', Str::stripAnsi($terminal->output()), 'it asked');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Wrote preview to ' . $path, $output);
        $this->assertTrue(is_file($path));
        $this->assertNotSame('', (string) file_get_contents($path), 'and the answer produced a real preview');

        unlink($path);
    }

    public function testPreviewReportFormatWritesTheRenderedDocument(): void
    {
        $path    = $this->dir . '/out.txt';
        $options = $this->parse(['extensions', '--key=pdf', '--preview', '--out=' . $path]);

        ob_start();
        $exit = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--key=pdf', '--preview', '--out=' . $path], new WorkingDirectory($this->dir));
        ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertTrue(is_file($path));
        $this->assertStringStartsWith("%PDF-1.4\n", (string) file_get_contents($path));

        unlink($path);
    }

    public function testPreviewSubscriberWritesARealWellFormedJUnitReport(): void
    {
        $path    = $this->dir . '/out.txt';
        $options = $this->parse(['extensions', '--key=junit', '--preview', '--out=' . $path]);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--key=junit', '--preview', '--out=' . $path], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Wrote preview to ' . $path, $output);

        $xml = simplexml_load_string((string) file_get_contents($path));

        if ($xml === false) {
            self::fail('The previewed JUnit report is not well-formed XML.');
        }

        $suites = $xml->xpath('//testsuite') ?? [];
        $this->assertNotEmpty($suites);

        unlink($path);
    }

    public function testPreviewProgressViewWritesRealTeamCityServiceMessages(): void
    {
        $path    = $this->dir . '/out.txt';
        $options = $this->parse(['extensions', '--key=teamcity', '--preview', '--out=' . $path]);

        ob_start();
        $exit   = (new ExtensionsCommand())->execute($options, ['crucible', 'extensions', '--key=teamcity', '--preview', '--out=' . $path], new WorkingDirectory($this->dir));
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Wrote preview to ' . $path, $output);

        $written = (string) file_get_contents($path);
        $this->assertStringContainsString("##teamcity[testStarted name='testAdds'", $written);
        $this->assertStringContainsString('testFailed', $written);

        unlink($path);
    }

    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): CliOptions
    {
        $options = CliOptions::fromArgv(['crucible', ...$argv]);

        if (is_string($options)) {
            self::fail('Unexpected parse error: ' . $options);
        }

        return $options;
    }
}
