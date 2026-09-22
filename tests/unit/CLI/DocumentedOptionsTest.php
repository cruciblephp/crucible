<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\Application;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_diff;
use function array_keys;
use function array_values;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function ob_get_clean;
use function ob_start;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_contains;

/**
 * Documentation drift, caught by the suite rather than by a user.
 *
 * This exists because it already happened: `--profile` and `--dirty`
 * shipped complete with tests and a decision record, and were absent
 * from `--help` and from the manual, because nothing required them to
 * be there. Reviewing more carefully is not a mechanism; this is.
 *
 * The internal `--worker` is the one deliberate omission — it is the
 * supervisor's private handshake with its own child processes, not a
 * thing a user invokes.
 */
#[CoversClass(Application::class)]
#[CoversClass(CliOptions::class)]
final class DocumentedOptionsTest extends TestCase
{
    private const array INTERNAL = ['--worker'];

    /**
     * Every option the parser accepts, read from the parser itself so
     * this cannot fall behind it. ALIASES counts: an alias is rewritten
     * before the parse loop, so the command line accepts it exactly as it
     * accepts the option it points at.
     *
     * @return list<string>
     */
    private function accepted(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/CLI/CliOptions.php');
        $names  = [];

        foreach (['FLAGS', 'VALUED', 'REPEATABLE', 'ALIASES'] as $constant) {
            if (preg_match('/private const array ' . $constant . ' = \[(.*?)\];/s', $source, $block) !== 1) {
                self::fail('Could not read the ' . $constant . ' list from CliOptions.');
            }

            preg_match_all("/'(--[a-z0-9-]+)'/", $block[1], $found);

            foreach ($found[1] as $name) {
                $names[$name] = true;
            }
        }

        // Parsed by hand rather than declared, because their value is
        // optional: --changed[=ref] and --colors[=when].
        foreach (['--changed', '--colors', '--coverage-text'] as $byHand) {
            self::assertStringContainsString(
                "\$name === '" . $byHand . "'",
                $source,
                'This test assumes ' . $byHand . ' is parsed by hand; the parser changed.',
            );

            $names[$byHand] = true;
        }

        $names = array_keys($names);
        sort($names);

        return array_values(array_diff($names, self::INTERNAL));
    }

    public function testEveryOptionAppearsInTheBuiltInHelp(): void
    {
        ob_start();
        (new Application())->run(['crucible', '--help']);
        $help = (string) ob_get_clean();

        $missing = [];

        foreach ($this->accepted() as $option) {
            if (!str_contains($help, $option)) {
                $missing[] = $option;
            }
        }

        self::assertSame([], $missing, 'Undocumented in --help: ' . implode(', ', $missing));
    }

    public function testEveryOptionAppearsInTheManual(): void
    {
        $manual  = (string) file_get_contents(dirname(__DIR__, 3) . '/HELP.md');
        $missing = [];

        foreach ($this->accepted() as $option) {
            if (!str_contains($manual, $option)) {
                $missing[] = $option;
            }
        }

        self::assertSame([], $missing, 'Undocumented in HELP.md: ' . implode(', ', $missing));
    }

    public function testTheManualDocumentsNoOptionThatDoesNotExist(): void
    {
        // The other direction, which rots more quietly: a renamed or
        // removed option leaves the manual confidently wrong.
        $manual = (string) file_get_contents(dirname(__DIR__, 3) . '/HELP.md');

        // A trailing hyphen means the manual was writing a glob
        // (`--fail-on-*`), not naming an option.
        preg_match_all('/`(--[a-z0-9-]*[a-z0-9])/', $manual, $found);

        $accepted = $this->accepted();
        $unknown  = [];

        foreach ($found[1] as $option) {
            if (!in_array($option, $accepted, true) && !in_array($option, self::INTERNAL, true)) {
                $unknown[$option] = true;
            }
        }

        self::assertSame([], array_keys($unknown), 'HELP.md documents options the parser rejects: ' . implode(', ', array_keys($unknown)));
    }
}
