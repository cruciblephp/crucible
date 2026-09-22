<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Version;

use function basename;
use function count;
use function date;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_dir;
use function ksort;
use function mkdir;
use function phpversion;
use function sha1;
use function sha1_file;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function token_get_all;
use function token_name;

use const ENT_QUOTES;
use const ENT_XML1;
use const PHP_VERSION;

/**
 * The spec's --coverage-xml: a directory of XML, an index naming every
 * file and one document per file carrying its classes, their methods
 * with line ranges and CRAP, and the per-line coverage with the tests
 * that reached each line.
 *
 * Output-only, like the Clover and Cobertura emitters — the no-XML rule
 * bans XML *inputs*; emitters for consumers that speak XML are the
 * sanctioned exception (D-041).
 */
final readonly class XmlReport
{
    /**
     * The single-character tokens PHP returns as plain strings, under
     * the names the incumbent's XML gives them. Observed from its own
     * output rather than read from its source: --coverage-xml has no
     * schema, so the vocabulary is only knowable by running it.
     */
    private const array PUNCTUATION = [
        '!' => 'T_EXCLAMATION_MARK',
        '"' => 'T_DOUBLE_QUOTES',
        '$' => 'T_DOLLAR',
        '%' => 'T_PERCENT',
        '&' => 'T_AMPERSAND',
        '(' => 'T_OPEN_BRACKET',
        ')' => 'T_CLOSE_BRACKET',
        '*' => 'T_MULT',
        '+' => 'T_PLUS',
        ',' => 'T_COMMA',
        '-' => 'T_MINUS',
        '.' => 'T_DOT',
        '/' => 'T_DIV',
        ':' => 'T_COLON',
        ';' => 'T_SEMICOLON',
        '<' => 'T_LT',
        '=' => 'T_EQUAL',
        '>' => 'T_GT',
        '?' => 'T_QUESTION_MARK',
        '@' => 'T_AT',
        '[' => 'T_OPEN_SQUARE',
        ']' => 'T_CLOSE_SQUARE',
        '^' => 'T_CARET',
        '`' => 'T_BACKTICK',
        '{' => 'T_OPEN_CURLY',
        '|' => 'T_PIPE',
        '}' => 'T_CLOSE_CURLY',
        '~' => 'T_TILDE',
    ];

    /**
     * @param bool $excludeSource the spec's --exclude-source-from-xml-coverage: omit the <source> element
     */
    public function __construct(private bool $excludeSource = false) {}

    /**
     * @param non-empty-string $root      absolute project root
     * @param non-empty-string $directory output directory
     * @param non-empty-string $driver
     * @param list<array{id: string, status: string, time: float, size?: string}> $tests
     *                                                                                   how each test ended, for the index's <tests>; empty when the caller has no run to describe
     */
    public function write(CoverageData $data, string $root, string $directory, string $driver, array $tests = []): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $prefix = $root . '/';
        $lines  = $data->lines;

        ksort($lines);

        // Which tests reached which line, inverted from the per-test map
        // so a line can name its own tests without re-scanning.
        $byLine = [];

        foreach ($data->tests as $testId => $files) {
            foreach ($files as $file => $executed) {
                foreach ($executed as $line) {
                    $byLine[$file][$line][] = $testId;
                }
            }
        }

        $files = '';

        foreach ($lines as $file => $values) {
            if ($file === '') {
                continue;
            }

            $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
            $name     = $this->documentName($relative);

            file_put_contents(
                $directory . '/' . $name,
                $this->fileDocument($file, $relative, $values, $byLine[$file] ?? []),
            );

            $files .= sprintf(
                "    <file name=\"%s\" href=\"%s\" hash=\"%s\"/>\n",
                $this->escape($relative),
                $this->escape($name),
                sha1_file($file) === false ? '' : sha1_file($file),
            );
        }

        file_put_contents($directory . '/index.xml', sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phpunit xmlns=\"https://schema.phpunit.de/coverage/1.0\">\n"
            . "  <build time=\"%s\" phpunit=\"%s\" coverage=\"crucible %s\">\n"
            . "    <runtime name=\"PHP\" version=\"%s\" url=\"https://www.php.net/\"/>\n"
            . "    <driver name=\"%s\" version=\"%s\"/>\n"
            . "  </build>\n"
            . "  <project source=\"%s\">\n%s    <directory name=\"/\">\n%s%s    </directory>\n  </project>\n</phpunit>\n",
            date('D M j G:i:s T Y'),
            Version::COMPATIBILITY_SERIES,
            Version::NUMBER,
            PHP_VERSION,
            $this->escape($driver),
            $this->escape(phpversion($driver) === false ? 'unknown' : phpversion($driver)),
            $this->escape($root),
            $this->tests($tests),
            $this->totals($data),
            $files,
        ));
    }

    /**
     * @param non-empty-string                $file
     * @param array<int, int>                 $values
     * @param array<int, list<string>>        $byLine
     */
    private function fileDocument(string $file, string $relative, array $values, array $byLine): string
    {
        $units = '';

        foreach (SourceAnalysis::of($file)->classes as $qualified => $class) {
            $executable = 0;
            $executed   = 0;
            $start      = 0;
            $complexity = 0;
            $methods    = '';

            foreach ($class->methods as $method) {
                $start = $start === 0 ? $method->startLine : $start;
                $own   = $this->counts($values, $method->startLine, $method->endLine);
                $executable += $own['executable'];
                $executed += $own['executed'];
                $complexity += $method->complexity;

                $methods .= sprintf(
                    "      <method name=\"%s\" signature=\"%s\" start=\"%d\" end=\"%d\" crap=\"%s\" executable=\"%d\" executed=\"%d\" coverage=\"%s\"/>\n",
                    $this->escape($method->name),
                    $this->escape($method->signature()),
                    $method->startLine,
                    $method->endLine,
                    $this->number($method->crap($values)),
                    $own['executable'],
                    $own['executed'],
                    $this->number($method->coverage($values)),
                );
            }

            $units .= sprintf(
                "    <class name=\"%s\" start=\"%d\" executable=\"%d\" executed=\"%d\" crap=\"%s\">\n      <namespace name=\"%s\"/>\n%s    </class>\n",
                $this->escape($qualified),
                $start,
                $executable,
                $executed,
                $this->number($this->crap($complexity, $executable === 0 ? 0.0 : 100 * $executed / $executable)),
                $this->escape($class->namespace),
                $methods,
            );
        }

        $coverage = '';
        $numbers  = $values;

        ksort($numbers);

        foreach ($numbers as $line => $value) {
            if ($value <= 0) {
                continue;
            }

            $covering = '';

            $reachedBy = $byLine[$line] ?? [];

            foreach ($reachedBy as $testId) {
                $covering .= sprintf(
                    "        <covered by=\"%s\" count=\"%d\"/>\n",
                    $this->escape($testId),
                    count($reachedBy),
                );
            }

            // A covered line's hit counts are on its <covered> children,
            // where the incumbent puts them, so repeating the total on
            // the parent would say nothing new. A line executed by no
            // recorded test has no children to carry it, and there the
            // count is the only hit information the document has.
            $coverage .= $covering === ''
                ? sprintf("      <line nr=\"%d\" count=\"%d\"/>\n", $line, $value)
                : sprintf("      <line nr=\"%d\">\n%s      </line>\n", $line, $covering);
        }

        $source = '';

        if (!$this->excludeSource) {
            $contents = file_get_contents($file);
            $source   = $this->source($contents === false ? '' : $contents);
        }

        return sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phpunit xmlns=\"https://schema.phpunit.de/coverage/1.0\">\n  <file name=\"%s\" path=\"%s\" hash=\"%s\">\n%s    <coverage>\n%s    </coverage>\n%s  </file>\n</phpunit>\n",
            $this->escape(basename($relative)),
            $this->escape(dirname($relative)),
            sha1_file($file) === false ? '' : sha1_file($file),
            $units,
            $coverage,
            $source,
        );
    }

    private function totals(CoverageData $data): string
    {
        $totals = $data->totals();

        // The loc and unit counts the incumbent's consumers read, from
        // the analysis the CRAP and class views already need — a report
        // that only carried executable/executed would be a narrower
        // document wearing the same schema.
        $lines   = ['total' => 0, 'comments' => 0, 'code' => 0];
        $classes = 0;
        $traits  = 0;
        $methods = 0;
        $tested  = 0;

        foreach ($data->lines as $file => $values) {
            if ($file === '') {
                continue;
            }

            $analysis = SourceAnalysis::of($file);

            foreach ($lines as $key => $count) {
                $lines[$key] = $count + $analysis->lines[$key];
            }

            foreach ($analysis->classes as $class) {
                $classes++;

                foreach ($class->methods as $method) {
                    $methods++;

                    if ($method->coverage($values) > 0.0) {
                        $tested++;
                    }
                }
            }
        }

        return sprintf(
            "      <totals>\n"
            . "        <lines total=\"%d\" comments=\"%d\" code=\"%d\" executable=\"%d\" executed=\"%d\" percent=\"%s\"/>\n"
            . "        <methods count=\"%d\" tested=\"%d\" percent=\"%s\"/>\n"
            . "        <functions count=\"0\" tested=\"0\" percent=\"0\"/>\n"
            . "        <classes count=\"%d\" tested=\"%d\" percent=\"%s\"/>\n"
            . "        <traits count=\"%d\" tested=\"0\" percent=\"0\"/>\n"
            . "      </totals>\n",
            $lines['total'],
            $lines['comments'],
            $lines['code'],
            $totals['executable'],
            $totals['covered'],
            $this->number($totals['executable'] === 0 ? 0.0 : 100 * $totals['covered'] / $totals['executable']),
            $methods,
            $tested,
            $this->number($methods === 0 ? 0.0 : 100 * $tested / $methods),
            $classes,
            0,
            $this->number(0.0),
            $traits,
        );
    }

    /**
     * @param array<int, int> $values
     *
     * @return array{executable: int, executed: int}
     */
    private function counts(array $values, int $from, int $to): array
    {
        $executable = 0;
        $executed   = 0;

        for ($line = $from; $line <= $to; $line++) {
            if (!isset($values[$line]) || $values[$line] === -2) {
                continue;
            }

            $executable++;

            if ($values[$line] > 0) {
                $executed++;
            }
        }

        return ['executable' => $executable, 'executed' => $executed];
    }

    /**
     * One flat directory, so a path separator cannot escape it and two
     * files in different directories cannot claim the same document.
     */
    private function documentName(string $relative): string
    {
        return sha1($relative) . '.xml';
    }

    /**
     * The index's <tests>: how each test ended and how long it took.
     * Omitted rather than emitted empty, because an empty element would
     * describe a run that reported nothing.
     *
     * @param list<array{id: string, status: string, time: float, size?: string}> $tests
     */
    private function tests(array $tests): string
    {
        if ($tests === []) {
            return '';
        }

        $rows = '';

        foreach ($tests as $test) {
            $rows .= sprintf(
                "      <test name=\"%s\" size=\"%s\" status=\"%s\" time=\"%F\"/>\n",
                $this->escape($test['id']),
                $this->escape($test['size'] ?? 'unknown'),
                $this->escape($test['status']),
                $test['time'],
            );
        }

        return sprintf("    <tests>\n%s    </tests>\n", $rows);
    }

    /**
     * The <source> dump: every line of the file carrying the tokens on
     * it, which is what a consumer rendering annotated source reads.
     * A token spanning lines is split at each newline, so every fragment
     * sits under the line it actually appears on and a line that holds
     * nothing is still present as an empty element.
     */
    private function source(string $contents): string
    {
        if ($contents === '') {
            return "    <source/>\n";
        }

        $tokens = [];
        $line   = 1;

        foreach (token_get_all($contents) as $token) {
            if (is_array($token)) {
                $name = $this->tokenName($token[0]);
                $text = $token[1];
                $line = $token[2];
            } else {
                $name = self::PUNCTUATION[$token] ?? 'T_UNKNOWN';
                $text = $token;
            }

            foreach (explode("\n", $text) as $offset => $fragment) {
                if ($fragment !== '') {
                    $tokens[$line + $offset][] = sprintf(
                        '<token name="%s">%s</token>',
                        $name,
                        $this->escape($fragment),
                    );
                }
            }

            $line += substr_count($text, "\n");
        }

        $body = '';

        for ($number = 1, $total = substr_count($contents, "\n") + 1; $number <= $total; ++$number) {
            $body .= isset($tokens[$number])
                ? sprintf(
                    "      <line no=\"%d\">\n        %s\n      </line>\n",
                    $number,
                    implode("\n        ", $tokens[$number]),
                )
                : sprintf("      <line no=\"%d\"/>\n", $number);
        }

        return sprintf("    <source>\n%s    </source>\n", $body);
    }

    /**
     * PHP's own name for a token, except the one nobody outside the
     * parser spells that way: `::` reaches consumers as T_DOUBLE_COLON.
     */
    private function tokenName(int $token): string
    {
        $name = token_name($token);

        return $name === 'T_PAAMAYIM_NEKUDOTAYIM' ? 'T_DOUBLE_COLON' : $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }

    /**
     * The class-level CRAP: the same curve a method's uses, over the
     * class's summed complexity and its own covered proportion.
     */
    private function crap(int $complexity, float $coverage): float
    {
        return match (true) {
            $coverage === 0.0 => $complexity ** 2 + $complexity,
            $coverage >= 95   => $complexity,
            default           => $complexity ** 2 * (1 - $coverage / 100) ** 3 + $complexity,
        };
    }

    private function number(float $value): string
    {
        return sprintf('%01.2F', $value);
    }
}
