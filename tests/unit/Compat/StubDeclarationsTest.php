<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Compat;

use Attribute;
use BackedEnum;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use LucianoPereira\Crucible\Compat\Stubs\PhpUnitDeclarations;
use LucianoPereira\Crucible\Framework\TestCase;
use ReflectionClass;
use UnitEnum;

use function array_keys;
use function constant;
use function explode;
use function fclose;
use function file_get_contents;
use function preg_match;
use function proc_close;
use function proc_open;
use function sort;
use function stream_get_contents;
use function trim;
use function var_export;

use const PHP_BINARY;

/**
 * The analysis-only declarations for the aliased PHPUnit names (D-174):
 * the drift gate. The stubs file is a generated artifact — its claim
 * is that every name the run-time compatibility layer answers is
 * declared in a shape an analyser can parse, and that the shape says
 * what reflection says. This gate re-derives everything from the alias
 * map: names both ways, each declaration against its target's own
 * reflection, and the shipped file against a fresh generation, so the
 * artifact can never quietly rot behind a map or a class that moved.
 */
#[CoversClass(PhpUnitDeclarations::class)]
final class StubDeclarationsTest extends TestCase
{
    public function testTheShippedFileIsWhatTheGeneratorProduces(): void
    {
        $shipped = file_get_contents(PhpUnitDeclarations::file());

        self::assertIsString($shipped, 'stubs/phpunit.php is missing - generate it.');

        self::assertSame(
            PhpUnitDeclarations::generate(),
            $shipped,
            'stubs/phpunit.php drifted from the alias map - regenerate it.',
        );
    }

    public function testGenerationIsDeterministic(): void
    {
        self::assertSame(
            PhpUnitDeclarations::generate(),
            PhpUnitDeclarations::generate(),
            'Two generations of the same checkout differ.',
        );
    }

    public function testTheShippedFileParses(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-l', PhpUnitDeclarations::file()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process, 'php -l could not be started.');

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), 'php -l rejected the declarations file: ' . $output);
        self::assertStringContainsString('No syntax errors', $output);
    }

    public function testEveryAliasedNameIsDeclaredAndNothingElse(): void
    {
        $declared = array_keys($this->parsed());
        $aliased  = array_keys(PhpUnitCompatibility::aliases());

        sort($declared);
        sort($aliased);

        self::assertSame(
            $aliased,
            $declared,
            'The declarations and the run-time alias map disagree - regenerate the stubs or fix the map.',
        );
    }

    public function testEachDeclarationMatchesItsTarget(): void
    {
        $parsed = $this->parsed();

        foreach (PhpUnitCompatibility::aliases() as $name => $target) {
            $entry = $parsed[$name] ?? null;

            self::assertInstanceOf(ParsedDeclaration::class, $entry, $name . ' has no declaration in the shipped file.');

            $reflection = new ReflectionClass($target);
            $kind       = $reflection->isInterface() ? 'interface' : ($reflection->isEnum() ? 'enum' : 'class');

            self::assertSame($kind, $entry->kind, $name . ' is declared with the wrong keyword.');

            if ($kind === 'enum') {
                $this->assertCasesMatch($name, $entry->cases, $reflection);

                continue;
            }

            self::assertSame(
                '\\' . $target,
                $entry->extends,
                $name . ' does not extend the class the alias answers.',
            );

            if ($kind === 'class') {
                self::assertSame($reflection->isAbstract(), $entry->abstract, $name . ' misdeclares abstract.');
                self::assertSame($reflection->isReadOnly(), $entry->readonly, $name . ' misdeclares readonly.');
            }

            self::assertSame(
                $this->targetFlags($reflection),
                $entry->flags,
                $name . ' carries the wrong attribute flags.',
            );
        }
    }

    /**
     * The parsed declarations, read from the shipped file's own text -
     * not from the generator - so the gate holds the artifact, not its
     * author, to the claims.
     *
     * @return array<string, ParsedDeclaration>
     */
    private function parsed(): array
    {
        $text      = (string) file_get_contents(PhpUnitDeclarations::file());
        $entries   = [];
        $namespace = '';
        $pending   = null;
        $inEnum    = null;

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^namespace ([\w\\\\]+);$/', $line, $namespaceMatch) === 1) {
                $namespace = $namespaceMatch[1];
                $inEnum    = null;

                continue;
            }

            if ($line === '#[\Attribute]') {
                $pending = 0;

                continue;
            }

            if (preg_match('/^#\[\\\\Attribute\((.+)\)]$/', $line, $markerMatch) === 1) {
                $pending = $this->evaluatedFlags($markerMatch[1]);

                continue;
            }

            if (preg_match('/^(?:(abstract) )?(?:(readonly) )?(class|interface) (\w+) extends \\\\([\w\\\\]+) \{\}$/', $line, $declarationMatch) === 1) {
                $entries[$namespace . '\\' . $declarationMatch[4]] = new ParsedDeclaration(
                    $declarationMatch[3],
                    $declarationMatch[1] === 'abstract',
                    $declarationMatch[2] === 'readonly',
                    '\\' . $declarationMatch[5],
                    $pending,
                );

                $pending = null;

                continue;
            }

            if (preg_match('/^(enum) (\w+)(?:: (\w+))?$/', $line, $enumMatch) === 1) {
                $entries[$namespace . '\\' . $enumMatch[2]] = new ParsedDeclaration($enumMatch[1], false, false, '', null);

                $inEnum = $namespace . '\\' . $enumMatch[2];

                continue;
            }

            if ($inEnum !== null && preg_match('/^    case (\w+)(?: = (.+))?;$/', $line, $caseMatch) === 1) {
                $entries[$inEnum]->cases[$caseMatch[1]] = $caseMatch[2] ?? null;
            }
        }

        return $entries;
    }

    /**
     * @param non-empty-string $inner the flags expression, ` | `-separated constant names
     */
    private function evaluatedFlags(string $inner): int
    {
        $flags = 0;

        foreach (explode(' | ', $inner) as $token) {
            $value = constant(trim($token));

            self::assertIsInt($value, 'Not an attribute flag constant: ' . $token);

            $flags += $value;
        }

        return $flags;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function targetFlags(ReflectionClass $reflection): ?int
    {
        $markers = $reflection->getAttributes(Attribute::class);

        if ($markers === []) {
            return null;
        }

        $flags = $markers[0]->getArguments()[0] ?? 0;

        self::assertIsInt($flags, 'A non-int attribute flag set on ' . $reflection->getName() . '.');

        return $flags;
    }

    /**
     * The enum copy against the enum itself: same cases, same backing
     * values, same order - the one declaration shape that extends
     * cannot give.
     *
     * @param array<string, string|null> $cases
     * @param ReflectionClass<object>    $reflection
     */
    private function assertCasesMatch(string $name, array $cases, ReflectionClass $reflection): void
    {
        $expected = [];

        foreach ($reflection->getConstants() as $value) {
            if (!$value instanceof UnitEnum) {
                continue;
            }

            $backed = $value instanceof BackedEnum ? var_export($value->value, true) : null;

            $expected[$value->name] = $backed;
        }

        self::assertSame($expected, $cases, $name . ' is declared with the wrong cases or backing values.');
    }
}

/**
 * One declaration as the test read it from the shipped file, as an
 * object because two array shapes with literal-narrowed fields do not
 * unify the way the parse needs them to.
 */
final class ParsedDeclaration
{
    /**
     * @param array<string, string|null> $cases enum cases, name => backing value as written
     */
    public function __construct(
        public string $kind,
        public bool $abstract,
        public bool $readonly,
        public string $extends,
        public ?int $flags,
        public array $cases = [],
    ) {}
}
