<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Types;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\PHPStan\Analysis;
use LucianoPereira\Crucible\Types\TypeExpression;
use LucianoPereira\Crucible\Types\TypeParser;

use function file_put_contents;
use function implode;
use function mkdir;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The run-time reading of a type string agrees with PHPStan's (D-131),
 * value by value. The oracle is PHPStan itself: each value is assigned,
 * narrowed to the type string, and dumped — `*NEVER*` means PHPStan's
 * reading has no room for the value. Set membership, not call-site
 * acceptance: PHPStan accepts `1` for a float parameter because PHP widens
 * it at the call, but a variable holding 1 is not a float.
 *
 * There is none; one that appears fails the test, naming the value.
 */
#[CoversClass(TypeExpression::class)]
#[CoversClass(TypeParser::class)]
#[Group('phpstan-blackbox')]
final class TypeExpressionAgreementTest extends TestCase
{
    /**
     * [type string, value as PHP source].
     *
     * @var list<array{non-empty-string, non-empty-string}>
     */
    private const array CORPUS = [
        ['int', '1'], ['int', "'1'"], ['int', '1.0'], ['float', '1.5'], ['float', '1'],
        ['string', "''"], ['string', '1'], ['bool', 'true'], ['bool', '0'], ['true', 'true'], ['true', 'false'],
        ['null', 'null'], ['?int', 'null'], ['?int', "'x'"], ['mixed', "['a']"],
        ['positive-int', '1'], ['positive-int', '0'], ['negative-int', '-1'], ['negative-int', '0'],
        ['non-negative-int', '0'], ['non-positive-int', '1'], ['non-zero-int', '0'],
        ['non-empty-string', "'a'"], ['non-empty-string', "''"], ['non-falsy-string', "'0'"], ['non-falsy-string', "'ok'"],
        ['numeric-string', "'12'"], ['numeric-string', "'1x'"], ['numeric', "'3.5'"], ['numeric', "'x'"], ['numeric', '3'],
        ['scalar', "'s'"], ['scalar', '[]'], ['array-key', "'k'"], ['array-key', '1.5'],
        ['int<1, 10>', '10'], ['int<1, 10>', '11'], ['int<min, 0>', '-5'], ['int<min, 0>', '1'],
        ["'on'|'off'", "'on'"], ["'on'|'off'", "'maybe'"], ['1|2|3', '2'], ['1|2|3', '4'],
        ['int|string', '1.5'], ['int|string', "'x'"],
        ['array', '[]'], ['array', "'x'"], ['list', '[1, 2]'], ['list', '[1 => 1]'],
        ['non-empty-array', '[]'], ['non-empty-array', '[1]'], ['non-empty-list', '[1]'], ['non-empty-list', '[]'],
        ['list<int>', '[1, 2]'], ['list<int>', "[1, 'two']"], ['array<string, int>', "['a' => 1]"], ['array<string, int>', '[1]'],
        ['array<int>', "['a' => 1]"], ['int[]', "[1, 'x']"], ['string[]', "['a', 'b']"],
        ['array{id: int}', "['id' => 1]"], ['array{id: int}', "['id' => '1']"], ['array{id: int}', '[]'],
        ['array{id: int}', "['id' => 1, 'extra' => true]"], ['array{id: int, name?: string}', "['id' => 1]"],
        ['array{id: int, name?: string}', "['id' => 1, 'name' => 2]"], ['array{int, string}', "[1, 'a']"],
        ['array{int, string}', "['a', 1]"], ['array{int, string}', "[1, 'a', true]"],
        ['array{id: positive-int, tags: list<string>}', "['id' => 1, 'tags' => ['a']]"],
        ['array{id: positive-int, tags: list<string>}', "['id' => 1, 'tags' => [1]]"],
        ['array{user: array{name: non-empty-string}}', "['user' => ['name' => 'Ada']]"],
        ['array{user: array{name: non-empty-string}}', "['user' => ['name' => '']]"],
        ['\DateTimeInterface', 'new \DateTimeImmutable()'], ['\DateTimeInterface', "'2026-01-01'"],
        ['\Countable&\ArrayAccess', 'new \ArrayObject()'], ['?\DateTimeInterface', 'null'],
        ['class-string', "'DateTimeImmutable'"], ['class-string<\Throwable>', "'RuntimeException'"],
    ];

    public function testTheRunTimeReadingAgreesWithPhpstans(): void
    {
        $directory = sys_get_temp_dir() . '/crucible-agreement-' . uniqid();
        mkdir($directory, 0o777, true);

        $lines = ['<?php', '', 'declare(strict_types=1);', ''];

        foreach (self::CORPUS as $index => [$type, $value]) {
            $lines[] = sprintf(
                'function probe%d(): void { $x = %s; \LucianoPereira\Crucible\Assert\Assert::assertMatchesShape(%s, $x); \PHPStan\dumpType($x); }',
                $index,
                $value,
                "'" . str_replace("'", "\\'", $type) . "'",
            );
        }

        file_put_contents($directory . '/corpus.php', implode("\n", $lines) . "\n");

        // The same source, as values for the run-time reading: required,
        // not eval()'d, so the values PHPStan reads and the ones checked
        // here come from one text (D-107's transport).
        $values = ['<?php', '', 'return ['];

        foreach (self::CORPUS as $index => [, $value]) {
            $values[] = sprintf('    %d => %s,', $index, $value);
        }

        file_put_contents($directory . '/values.php', implode("\n", [...$values, '];', '']));

        /** @var array<int, mixed> $corpus */
        $corpus = require $directory . '/values.php';

        /** @var array<int, string> $dumped line => dumped type */
        $dumped = [];

        foreach (Analysis::run([$directory . '/corpus.php'], [Analysis::root() . '/phpstan/extension.neon'])->messages as $message) {
            if ($message->identifier === 'phpstan.dumpType') {
                $dumped[$message->line] = $message->message;
            }
        }

        $disagreements = [];

        foreach (self::CORPUS as $index => [$type, $value]) {
            // Line of probe N: the four header lines, then one per entry.
            // No room for the value: the whole type is never, or an array
            // shape has an element of no type (`list{1, *NEVER*}` — PHPStan
            // keeps the never on the element). `ArrayObject<*NEVER*, *NEVER*>`
            // is an empty ArrayObject, which does fit.
            $dump    = substr($dumped[$index + 5] ?? 'Dumped type: *NEVER*', strlen('Dumped type: '));
            $phpstan = $dump !== '*NEVER*'
                && (!str_starts_with($dump, 'array{') && !str_starts_with($dump, 'list{') || !str_contains($dump, '*NEVER*'));
            $crucible = TypeExpression::parse($type)->mismatch($corpus[$index]) === null;

            if ($phpstan !== $crucible) {
                $disagreements[] = sprintf('#%d %s ← %s: phpstan %s, crucible %s', $index, $type, $value, $phpstan ? 'fits' : 'does not fit', $crucible ? 'fits' : 'does not fit');
            }
        }

        self::assertSame([], $disagreements);
    }
}
