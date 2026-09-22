<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Console\Output;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Output\Help;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_filter;
use function array_unique;
use function array_values;
use function explode;
use function ltrim;
use function str_contains;
use function strlen;
use function strpos;
use function substr_count;
use function trim;

/**
 * `--help`, laid out rather than written out.
 *
 * ⚠ The defect this exists to prevent is not ugliness. A description
 * written two spaces after its term lands at whatever column that term
 * happens to end at, so no two rows agree and the eye has to re-find
 * the text on every line. 55 rows of the real help did exactly that.
 */
#[CoversClass(Help::class)]
final class HelpTest extends TestCase
{
    /** Every description begins in the same column, whatever its term. */
    public function testDescriptionsShareOneColumn(): void
    {
        $rendered = (new Help(80, decorated: false))->render(<<<'EOT'
            Options:
              --short <n>  A short one
              --a-considerably-longer-flag <file>  A long one
              --mid  Another
            EOT);

        $columns = [];

        foreach (explode("\n", $rendered) as $line) {
            foreach (['A short one', 'A long one', 'Another'] as $text) {
                if (str_contains($line, $text)) {
                    $columns[] = strpos($line, $text);
                }
            }
        }

        self::assertCount(3, $columns, 'every description was rendered');
        self::assertCount(1, array_unique($columns), 'and all at the same column');
    }

    /**
     * A term wider than the column keeps its own line.
     *
     * The alternative is pushing one description out of line with every
     * other, which is the defect rather than a fix for it.
     */
    public function testATermTooWideForTheColumnTakesItsOwnLine(): void
    {
        $rendered = (new Help(80, decorated: false))->render(<<<'EOT'
            Options:
              --short  A short one
              --do-not-warn-when-php-is-not-configured-for-development  Never print it
            EOT);

        $lines = array_values(array_filter(
            explode("\n", $rendered),
            static fn(string $line): bool => $line !== '',
        ));

        self::assertSame('  --do-not-warn-when-php-is-not-configured-for-development', $lines[2], 'the term, alone');

        // ⚠ Compared against the column the other row uses, not against
        // a number written here: pinning the constant would make this
        // test fail every time the column is tuned, while saying
        // nothing about whether the two rows still agree.
        self::assertSame(
            strpos($lines[1], 'A short one'),
            strpos($lines[3], 'Never print it'),
            'and its description in the same column as every other',
        );
    }

    /** Descriptions wrap to the screen, continuing under themselves. */
    public function testALongDescriptionWrapsIntoTheSameColumn(): void
    {
        $rendered = (new Help(60, decorated: false))->render(<<<'EOT'
            Options:
              --view <key>  Select a registered progress view by its key, as crucible extensions lists it
            EOT);

        $lines  = explode("\n", $rendered);
        $column = strpos($lines[1], 'Select');

        self::assertIsInt($column, 'the description was rendered');

        $continuations = 0;

        foreach ($lines as $index => $line) {
            self::assertLessThanOrEqual(60, Str::width($line), 'nothing overruns the screen');

            // Each continuation begins under the description it belongs
            // to, measured from that description rather than declared.
            if ($index > 1 && $line !== '') {
                self::assertSame($column, strlen($line) - strlen(ltrim($line)), 'continued in the same column');
                $continuations++;
            }
        }

        self::assertGreaterThan(0, $continuations, 'it did wrap');
    }

    /**
     * A list nested under an option gets a column of its own.
     *
     * Its terms start where everything else's descriptions do, so the
     * shared column cannot hold them; forcing it produced three-line
     * wraps of four-word descriptions.
     */
    public function testAListNestedUnderAnOptionIsLaidOutOnItsOwn(): void
    {
        // Written with explicit indentation rather than a heredoc: the
        // nested list's real indent is past the shared column, which is
        // the whole condition under test, and a heredoc's dedent would
        // quietly pull it back inside.
        $rendered = (new Help(80, decorated: false))->render(
            "Options:\n"
            . '  --view <key>  Select a progress view' . "\n"
            . '                              map             a cell per test' . "\n"
            . '                              map-fullscreen  the same, full screen',
        );

        // Found by content, not by index: where a description lands is
        // what this asserts, so a test that assumed the line number
        // would be asserting its own arithmetic.
        $at = static function (string $text) use ($rendered): int {
            foreach (explode("\n", $rendered) as $line) {
                $column = strpos($line, $text);

                if ($column !== false) {
                    return $column;
                }
            }

            self::fail($text . ' was not rendered');
        };

        self::assertSame($at('a cell per test'), $at('the same, full screen'), 'the nested list shares a column');
        self::assertGreaterThan($at('Select a progress view'), $at('a cell per test'), 'and it is further right');
    }

    /**
     * A description with nowhere to break is left whole.
     *
     * ⚠ `Str::wrap()` splits on width, not on words, so a long option
     * name came back as `--warn-when-php-is-not-configured-for-develo`
     * and `pment`. Split across two lines a flag cannot be copied, read
     * or searched for; running past the margin costs nothing but a
     * soft wrap the terminal does anyway.
     */
    public function testAnUnbreakableDescriptionIsNotSplitMidWord(): void
    {
        $long     = '--do-not-warn-when-php-is-not-configured-for-development';
        $rendered = (new Help(80, decorated: false))->render("Options:\n  --no-php-advisory  " . $long . "\n");

        self::assertStringContainsString($long, $rendered, 'the name survives whole');
        self::assertSame(2, substr_count(trim($rendered), "\n") + 1, 'on one line, not broken across two');
    }

    /** Headings carry the emphasis; a pipe or a CI log gets none of it. */
    public function testColourIsWornOnlyWhenTheTerminalCanRenderIt(): void
    {
        $source = "Options:\n  --view <key>  Select a view\n";

        $plain = (new Help(80, decorated: false))->render($source);

        self::assertStringNotContainsString("\e[", $plain, 'not a byte of it in a pipe');

        $painted = (new Help(80, decorated: true))->render($source);

        self::assertStringContainsString("\e[", $painted);
        self::assertStringContainsString('--view <key>', Str::stripAnsi($painted), 'and the term survives it');
    }

    /** Prose is left exactly as written — it is not a table. */
    public function testProseIsUntouched(): void
    {
        $prose    = 'Crucible is an independent test framework targeting PHPUnit 13.';
        $rendered = (new Help(80, decorated: false))->render($prose);

        self::assertSame($prose, $rendered);
    }
}
