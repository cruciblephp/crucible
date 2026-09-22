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
use LucianoPereira\Crucible\PHPStan\ApprovedEvalRule;

use function dirname;
use function fclose;
use function file_put_contents;
use function implode;
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
 * The eval allowlist, proven through the real phpstan binary.
 *
 * Two claims in one run, because one without the other proves nothing:
 * a file that generates code from an unapproved path is reported, and
 * an approved one is not. A rule that reported everything would pass
 * the first assertion alone.
 */
#[CoversClass(ApprovedEvalRule::class)]
#[Group('phpstan-blackbox')]
final class ApprovedEvalRuleTest extends TestCase
{
    public function testAnUnapprovedEvalIsReportedAndAnApprovedOneIsNot(): void
    {
        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }

        $messages = $this->analyse($root, [
            $root . '/tests/_fixtures/phpstan/UnapprovedEvalFixture.php',
            // The one approved site, analysed in the same run: the
            // rule must stay silent about the place that carries a
            // reason, or a rule reporting everything would pass.
            $root . '/src/Generated/GeneratedCode.php',
        ]);

        self::assertCount(1, $messages, 'Unexpected analysis output: ' . implode(' | ', $messages));
        self::assertStringStartsWith('crucible.evalNotApproved: ', $messages[0]);
        self::assertStringContainsString('invisible to every gate', $messages[0]);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function analyse(string $root, array $paths): array
    {
        $directory = sys_get_temp_dir() . '/crucible-phpstan-eval-' . uniqid();

        mkdir($directory, 0o777, true);

        $analysed = '';

        foreach ($paths as $path) {
            $analysed .= "            - {$path}\n";
        }

        // Only this rule, at the level the repository runs: an error
        // from anything else would make the count meaningless.
        file_put_contents($directory . '/phpstan.neon', <<<NEON
            parameters:
                level: max
                paths:
            {$analysed}    tmpDir: {$directory}/cache

            rules:
                - LucianoPereira\\Crucible\\PHPStan\\ApprovedEvalRule

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

        $rendered = [];

        foreach ($decoded['files'] as $reported) {
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
