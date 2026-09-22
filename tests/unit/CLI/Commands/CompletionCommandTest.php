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
use LucianoPereira\Crucible\CLI\Commands\CompletionCommand;
use LucianoPereira\Crucible\Framework\TestCase;

use function implode;
use function ob_get_clean;
use function ob_start;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * Tab completion, generated from the parser rather than kept beside it.
 *
 * ⚠ Stale completion is worse than none: it offers names the binary
 * rejects and hides the ones it takes, and nobody notices until they
 * are typing at a prompt. Nothing here hard-codes an option name.
 */
#[CoversClass(CompletionCommand::class)]
final class CompletionCommandTest extends TestCase
{
    /** Every option the parser accepts reaches the script, for every shell. */
    public function testEachShellOffersEveryOptionTheParserAccepts(): void
    {
        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $script  = $this->render($shell);
            $missing = [];

            foreach (CliOptions::accepted() as $name) {
                // fish declares a long option by its bare name.
                $needle = $shell === 'fish' && str_starts_with($name, '--')
                    ? ' -l ' . substr($name, 2)
                    : $name;

                if (!str_contains($script, $needle)) {
                    $missing[] = $name;
                }
            }

            self::assertSame([], $missing, $shell . ' is missing: ' . implode(', ', $missing));
        }
    }

    /**
     * ⚠ The script is evaluated, not read. A banner, a prompt or any
     * stray line would be run as shell by `eval "$(...)"`, and the
     * failure lands in someone's shell startup rather than here.
     */
    public function testTheScriptIsOnlyScript(): void
    {
        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $script = $this->render($shell);

            // zsh leads with #compdef so the same script also works saved
            // to a file on $fpath; every shell's first line is a comment.
            self::assertStringStartsWith('#', $script);
            self::assertStringContainsString('# crucible completion for ' . $shell, $script);

            if ($shell === 'zsh') {
                self::assertStringStartsWith('#compdef crucible', $script, 'autoloadable as a file too');
            }
            self::assertStringNotContainsString('crucible 1.0.0 by', $script, 'no banner');
        }
    }

    /** An unknown shell is named rather than guessed at. */
    public function testAnUnknownShellIsRefusedWithTheList(): void
    {
        ob_start();
        $exit   = (new CompletionCommand())->execute('tcsh');
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('bash|zsh|fish', $output);

        ob_start();
        $bare = (new CompletionCommand())->execute(null);
        ob_get_clean();

        self::assertSame(1, $bare, 'and so is no shell at all');
    }

    private function render(string $shell): string
    {
        ob_start();
        $exit   = (new CompletionCommand())->execute($shell);
        $script = (string) ob_get_clean();

        self::assertSame(0, $exit, $shell . ' generated');

        return $script;
    }
}
