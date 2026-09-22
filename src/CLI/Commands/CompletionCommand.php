<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\CLI\CliOptions;

use function implode;
use function in_array;
use function printf;
use function sort;
use function sprintf;
use function str_starts_with;
use function substr;

use const PHP_EOL;

/**
 * `crucible completion <shell>`: the tab-completion script for a shell.
 *
 * ⚠ Generated from the parser rather than written beside it. A list of
 * option names kept by hand is a list that goes stale the first time an
 * option is added, and stale completion is worse than none: it offers
 * names the binary rejects and hides the ones it takes.
 */
final class CompletionCommand
{
    private const array SHELLS = ['bash', 'zsh', 'fish'];

    public function execute(?string $shell): int
    {
        if ($shell === null || !in_array($shell, self::SHELLS, true)) {
            printf(
                'Usage: crucible completion <%s>' . PHP_EOL,
                implode('|', self::SHELLS),
            );

            return 1;
        }

        print match ($shell) {
            'bash'  => $this->bash(),
            'zsh'   => $this->zsh(),
            default => $this->fish(),
        };

        return 0;
    }

    private function bash(): string
    {
        return sprintf(
            <<<'SH'
                # crucible completion for bash — eval "$(crucible completion bash)"
                _crucible() {
                    local cur="${COMP_WORDS[COMP_CWORD]}"

                    if [[ "$cur" == -* ]]; then
                        COMPREPLY=( $(compgen -W "%s" -- "$cur") )
                    else
                        COMPREPLY=( $(compgen -W "%s" -- "$cur") $(compgen -f -- "$cur") )
                    fi
                }
                complete -F _crucible crucible

                SH,
            implode(' ', $this->options()),
            implode(' ', $this->commands()),
        );
    }

    private function zsh(): string
    {
        return sprintf(
            <<<'SH'
                #compdef crucible
                # crucible completion for zsh — eval "$(crucible completion zsh)",
                # or save as _crucible on $fpath. The #compdef line above makes
                # the file form work; the compdef call below makes eval work.
                _crucible() {
                    local -a options commands
                    options=(%s)
                    commands=(%s)

                    if [[ "$words[$CURRENT]" == -* ]]; then
                        compadd -- $options
                    else
                        compadd -- $commands
                        _files
                    fi
                }
                compdef _crucible crucible

                SH,
            implode(' ', $this->options()),
            implode(' ', $this->commands()),
        );
    }

    private function fish(): string
    {
        $lines = ['# crucible completion for fish — crucible completion fish > ~/.config/fish/completions/crucible.fish'];

        foreach ($this->commands() as $command) {
            $lines[] = sprintf(
                "complete -c crucible -n '__fish_use_subcommand' -a %s",
                $command,
            );
        }

        foreach ($this->options() as $option) {
            $lines[] = sprintf('complete -c crucible -l %s', substr($option, 2));
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Every `--option` the parser accepts, aliases included.
     *
     * @return list<string>
     */
    private function options(): array
    {
        $names = [];

        foreach (CliOptions::accepted() as $name) {
            if (str_starts_with($name, '--')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function commands(): array
    {
        $names = [];

        foreach (CliOptions::accepted() as $name) {
            if (!str_starts_with($name, '-')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }
}
