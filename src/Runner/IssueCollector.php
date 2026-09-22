<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_any;
use function debug_backtrace;
use function error_reporting;
use function in_array;
use function max;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;
use function strlen;
use function substr;

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const E_DEPRECATED;
use const E_NOTICE;
use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;

/**
 * Captures the PHP-level issues a test triggers — deprecations,
 * notices, warnings — without changing its outcome. Installed around
 * each test, drained after it; the drained issues ride the
 * test:finish event.
 *
 * Deprecations are attributed on capture (DeprecationScope): a
 * trigger inside the project's own directories is `self`; a trigger
 * in a dependency is `direct` when the nearest calling frame is
 * project code and `indirect` otherwise. Baselined deprecations
 * (acknowledged legacy, keyed file|message) are suppressed here, at
 * the source — they appear nowhere downstream, which keeps every
 * consumer of the stream consistent.
 */
final class IssueCollector
{
    private const int CAPTURED = E_DEPRECATED | E_USER_DEPRECATED
        | E_NOTICE | E_USER_NOTICE
        | E_WARNING | E_USER_WARNING;

    /** @var list<Issue> */
    private array $issues = [];

    private bool $installed = false;

    private int $deprecations = 0;

    private int $notices = 0;

    private int $warnings = 0;

    /**
     * @param list<non-empty-string> $projectDirectories absolute prefixes counting as "own code"
     * @param list<non-empty-string> $baseline           suppressed deprecation keys, "file|message"
     */
    public function __construct(
        private readonly array $projectDirectories,
        private readonly WorkingDirectory $workingDirectory,
        private readonly array $baseline = [],
    ) {}

    public function install(): void
    {
        set_error_handler($this->capture(...), self::CAPTURED);
        $this->installed = true;
    }

    /**
     * Uninstalls the handler and returns what the test triggered.
     *
     * @return list<Issue>
     */
    public function drain(): array
    {
        if ($this->installed) {
            restore_error_handler();
            $this->installed = false;
        }

        $issues       = $this->issues;
        $this->issues = [];

        return $issues;
    }

    /**
     * Run-level tallies, accumulated across drains.
     *
     * @return array{deprecations: int, notices: int, warnings: int}
     */
    public function tallies(): array
    {
        return [
            'deprecations' => $this->deprecations,
            'notices'      => $this->notices,
            'warnings'     => $this->warnings,
        ];
    }

    public function resetTallies(): void
    {
        $this->deprecations = 0;
        $this->notices      = 0;
        $this->warnings     = 0;
    }

    private function capture(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // Respect @-suppression and the configured reporting level:
        // what PHP would not report, Crucible does not count.
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        $kind = IssueKind::fromErrorLevel($level);

        if (!$kind instanceof \LucianoPereira\Crucible\Event\IssueKind || $message === '' || $file === '') {
            return false;
        }

        $relative = $this->relative($file);

        $scope = $kind === IssueKind::Deprecation ? $this->scopeOf($file) : null;

        if ($kind === IssueKind::Deprecation && in_array($relative . '|' . $message, $this->baseline, true)) {
            return true; // acknowledged legacy: suppressed at the source
        }

        $this->issues[] = new Issue($kind, $message, $relative, max(1, $line), $scope);

        match ($kind) {
            IssueKind::Deprecation => $this->deprecations++,
            IssueKind::Notice      => $this->notices++,
            IssueKind::Warning     => $this->warnings++,
        };

        return true;
    }

    private function scopeOf(string $file): DeprecationScope
    {
        if ($this->isProjectFile($file)) {
            return DeprecationScope::Self_;
        }

        // Triggered in a dependency: the nearest calling frame that
        // is not the trigger file (and not this collector) decides
        // direct vs indirect.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $frame) {
            $frameFile = $frame['file'] ?? '';

            if (in_array($frameFile, ['', $file, __FILE__], true)) {
                continue;
            }

            return $this->isProjectFile($frameFile) ? DeprecationScope::Direct : DeprecationScope::Indirect;
        }

        return DeprecationScope::Indirect;
    }

    private function isProjectFile(string $file): bool
    {
        return array_any($this->projectDirectories, static fn(string $directory): bool => str_starts_with($file, $directory));
    }

    /**
     * @param non-empty-string $file
     *
     * @return non-empty-string
     */
    private function relative(string $file): string
    {
        $prefix = $this->workingDirectory->path . '/';

        if (str_starts_with($file, $prefix) && strlen($file) > strlen($prefix)) {
            /** @var non-empty-string */
            return substr($file, strlen($prefix));
        }

        return $file;
    }
}
