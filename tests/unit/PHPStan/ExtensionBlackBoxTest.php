<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\AssertMethodTypeSpecifyingExtension;
use LucianoPereira\Crucible\PHPStan\AssertStaticMethodTypeSpecifyingExtension;
use LucianoPereira\Crucible\PHPStan\DialectClosureThisExtension;
use LucianoPereira\Crucible\PHPStan\DialectMagicReflectionExtension;
use LucianoPereira\Crucible\PHPStan\DialectMethodClosureThisExtension;
use LucianoPereira\Crucible\PHPStan\DialectThisResolver;
use LucianoPereira\Crucible\PHPStan\MockeryMockReflectionExtension;
use LucianoPereira\Crucible\PHPStan\PropertyClosureTypeExtension;
use LucianoPereira\Crucible\PHPStan\PropertyFunctionClosureTypeExtension;
use LucianoPereira\Crucible\PHPStan\PropertyParameterRule;

use function dirname;
use function fclose;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;

use const PHP_BINARY;

/**
 * The extension proven the same way the conformance suite proves the
 * engine: black-box, through the real phpstan binary. One fixture,
 * one run, three claims — the PHPUnit-shaped parent resolves (alias
 * bootstrap), the narrowed calls analyse clean (both extensions), and
 * exactly one sentinel error survives (the analysis is not vacuous).
 */
#[CoversClass(AssertStaticMethodTypeSpecifyingExtension::class)]
#[CoversClass(AssertMethodTypeSpecifyingExtension::class)]
#[CoversClass(DialectThisResolver::class)]
#[CoversClass(DialectClosureThisExtension::class)]
#[CoversClass(DialectMethodClosureThisExtension::class)]
#[CoversClass(DialectMagicReflectionExtension::class)]
#[CoversClass(MockeryMockReflectionExtension::class)]
#[CoversClass(PropertyClosureTypeExtension::class)]
#[CoversClass(PropertyFunctionClosureTypeExtension::class)]
#[CoversClass(PropertyParameterRule::class)]
#[Group('phpstan-blackbox')]
final class ExtensionBlackBoxTest extends TestCase
{
    public function testTheFixtureAnalysesToExactlyTheSentinel(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }

        $directory = sys_get_temp_dir() . '/crucible-phpstan-' . uniqid();

        mkdir($directory, 0o777, true);

        $fixture = $root . '/tests/_fixtures/phpstan/AliasNarrowingFixture.php';

        file_put_contents($directory . '/phpstan.neon', <<<NEON
            includes:
                - {$root}/phpstan/extension.neon

            parameters:
                level: max
                paths:
                    - {$fixture}
                tmpDir: {$directory}/cache

            NEON);

        $process = proc_open(
            [
                PHP_BINARY,
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--configuration', $directory . '/phpstan.neon',
                '--autoload-file', $root . '/vendor/autoload.php',
                '--error-format', 'json',
                '--no-progress',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        self::assertTrue(is_resource($process), 'phpstan could not be started.');

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($output === false ? '' : $output, true);

        self::assertIsArray($decoded, 'phpstan produced no JSON: ' . ($errors === false ? '' : $errors));
        self::assertIsArray($decoded['files'] ?? null);

        $messages = [];

        foreach ($decoded['files'] as $reported) {
            if (!is_array($reported) || !is_array($reported['messages'] ?? null)) {
                continue;
            }

            foreach ($reported['messages'] as $message) {
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }
        }

        // Exactly the sentinel plus the two D-051 type dumps: no
        // class.notFound (aliases resolved), no failures on any
        // narrowed call — including the named-argument probe, which
        // PHPStan normalizes before the extension runs — and the
        // property closure parameters carry their exact per-position
        // types recovered from the forAll chain.
        $rendered = [];

        foreach ($messages as $message) {
            $identifier = $message['identifier'] ?? null;
            $text       = $message['message'] ?? null;

            $rendered[] = (is_string($identifier) ? $identifier : '?')
                . ': ' . (is_string($text) ? $text : '?');
        }

        self::assertSame([
            'argument.type: Parameter #1 $value of method '
                . 'LucianoPereira\Crucible\Tests\Fixtures\PHPStan\AliasNarrowingFixture::half() expects int, string given.',
            'phpstan.dumpType: Dumped type: int',
            'phpstan.dumpType: Dumped type: list<string>',
            'phpstan.dumpType: Dumped type: bool',
            'crucible.propertyParameter: The check() closure parameter $wrong declares string, but its generator produces int.',
            'crucible.propertyParameter: The property() closure parameter $alsoWrong declares int, but its generator produces string.',
        ], $rendered, 'Unexpected analysis output: ' . ($output === false ? '' : $output));
    }

    public function testScopedUsesInResolvesThroughAncestorPestConfigs(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }

        $directory = sys_get_temp_dir() . '/crucible-phpstan-scoped-' . uniqid();

        mkdir($directory, 0o777, true);

        $tree = $root . '/tests/_fixtures/phpstan/scoped';

        file_put_contents($directory . '/phpstan.neon', <<<NEON
            includes:
                - {$root}/phpstan/extension.neon

            parameters:
                level: max
                paths:
                    - {$tree}/Feature
                # A real project's uses() classes come in through its
                # autoloader; the fixture tree has none, so the class
                # loads the same way the D-050 statement describes.
                bootstrapFiles:
                    - {$tree}/ScopedCase.php
                tmpDir: {$directory}/cache

            NEON);

        $rendered = $this->analyse($root, $directory);

        // $this resolves to the class the ancestor Pest.php scoped in
        // (D-067) — the espresso() call analyses clean, and the dump
        // proves the type is the scoped class, not the default.
        self::assertSame(
            ['phpstan.dumpType: Dumped type: LucianoPereira\Crucible\Tests\Fixtures\PHPStan\Scoped\ScopedCase'],
            $rendered,
        );
    }

    public function testTheMethodLevelMagicAndMockerySurfacesResolve(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }

        $directory = sys_get_temp_dir() . '/crucible-phpstan-dialect-' . uniqid();

        mkdir($directory, 0o777, true);

        $tree = $root . '/tests/_fixtures/phpstan/dialect';

        file_put_contents($directory . '/phpstan.neon', <<<NEON
            includes:
                - {$root}/phpstan/extension.neon

            parameters:
                level: max
                paths:
                    - {$tree}/Feature
                bootstrapFiles:
                    - {$tree}/DialectCase.php
                tmpDir: {$directory}/cache

            NEON);

        $rendered = $this->analyse($root, $directory);

        $case = 'LucianoPereira\\Crucible\\Tests\\Fixtures\\PHPStan\\Dialect\\DialectCase';

        // Three surfaces, one run, and nothing but the dumps — a
        // method.notFound or an undefined-member error anywhere here
        // would mean the extension did not fire.
        self::assertSame([
            // ScopeRegistration hooks and TestCall closures bind to
            // the scoped class, the method-level half of D-050.
            'phpstan.dumpType: Dumped type: ' . $case,
            'phpstan.dumpType: Dumped type: string',
            'phpstan.dumpType: Dumped type: ' . $case,
            'phpstan.dumpType: Dumped type: ' . $case,
            // The magic grammar: an undeclared member continues the
            // chain in kind rather than being an unknown method.
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Dialect\\Pest\\Expectation',
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Dialect\\Pest\\Expectation',
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Dialect\\Pest\\TestCall',
            // D-060: the four verbs open an expectation, anything
            // else on a mock is the runtime's mixed.
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Double\\Mockery\\MockeryExpectation',
            'phpstan.dumpType: Dumped type: LucianoPereira\\Crucible\\Double\\Mockery\\MockeryExpectation',
            'phpstan.dumpType: Dumped type: mixed',
        ], $rendered);
    }

    /**
     * One phpstan run over a prepared configuration, rendered as
     * `identifier: message` lines.
     *
     * @param non-empty-string $root
     * @param non-empty-string $directory
     *
     * @return list<string>
     */
    private function analyse(string $root, string $directory): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                $root . '/vendor/bin/phpstan',
                'analyse',
                '--configuration', $directory . '/phpstan.neon',
                '--autoload-file', $root . '/vendor/autoload.php',
                '--error-format', 'json',
                '--no-progress',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        self::assertTrue(is_resource($process), 'phpstan could not be started.');

        $output = stream_get_contents($pipes[1]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($output === false ? '' : $output, true);

        self::assertIsArray($decoded, 'phpstan produced no JSON.');

        $rendered = [];

        foreach (is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $reported) {
            if (!is_array($reported) || !is_array($reported['messages'] ?? null)) {
                continue;
            }

            foreach ($reported['messages'] as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $identifier = $message['identifier'] ?? null;
                $text       = $message['message'] ?? null;

                $rendered[] = (is_string($identifier) ? $identifier : '?')
                    . ': ' . (is_string($text) ? $text : '?');
            }
        }

        return $rendered;
    }
}
