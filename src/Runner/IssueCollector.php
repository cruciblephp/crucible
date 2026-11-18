<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner;

use Closure;
use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_any;
use function array_reverse;
use function debug_backtrace;
use function error_reporting;
use function in_array;
use function max;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;

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
 * The window brackets the handler STACK, not just its own entry. A
 * test that pushes a handler and fails to remove it leaves that
 * handler above ours, and a blind one-frame restore pops the test's
 * frame while ours stays live — every error between windows then
 * lands in the next window's drain, attributed to a test that never
 * ran it. The drain walks down to its own handler, removes whatever
 * the window's code left above it, and never pops past the handler
 * install() replaced: the bootstrap's handler survives even a test
 * that removed ours. What the walk finds determines the hygiene
 * verdict ({@see hygiene()}), which the runner reports the way the
 * incumbent does: a leaked handler or a removed foreign one is the
 * test's own bug, and it is a risky verdict, not a silence.
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

    /** The incumbent's exact wording for a window that left handlers behind. */
    public const string HANDLERS_LEFT = 'Test code or tested code did not remove its own error handlers';

    /** The incumbent's exact wording for a window that removed handlers other than its own. */
    public const string HANDLERS_REMOVED = 'Test code or tested code removed error handlers other than its own';

    /** @var list<Issue> */
    private array $issues = [];

    private bool $installed = false;

    /** @var ?callable the handler install() replaced, and the floor the drain never pops past */
    private $previous = null;

    /** @var ?Closure the window's own capture handler, identified by instance at drain time */
    private ?Closure $handler = null;

    /** @var ?string the hygiene verdict of the last drain, in the incumbent's words */
    private ?string $verdict = null;

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
        $this->handler   = $this->capture(...);
        $this->previous  = set_error_handler($this->handler, self::CAPTURED);
        $this->verdict   = null;
        $this->installed = true;
    }

    /**
     * Uninstalls the window and returns what the test triggered.
     *
     * The stack is walked, not guessed: every frame above our own
     * handler is removed, our handler is removed, and nothing at or
     * below the handler install() replaced is ever popped. A window
     * that removed BOTH its own handler and the one beneath it has
     * breached that floor already — the walk restores what it had to
     * lift to notice (measured against the incumbent: what remains
     * stays, its removal is the test's) and stops at the empty stack.
     *
     * @return list<Issue>
     */
    public function drain(): array
    {
        if ($this->installed) {
            $this->installed = false;
            $this->verdict   = null;

            $lifted = [];

            while (($top = $this->top()) !== null && $top !== $this->handler && $top !== $this->previous) {
                // A frame the window's code left above our handler.
                restore_error_handler();
                $lifted[] = $top;
            }

            if ($this->top() === $this->handler) {
                restore_error_handler();

                if ($lifted !== []) {
                    $this->verdict = self::HANDLERS_LEFT;
                }
            } else {
                // Our handler was removed by the window's code. The
                // floor install() recorded is where the stack stood
                // before the window opened; leave it standing, and
                // put back anything the walk had to lift only because
                // the floor itself was already gone.
                if ($this->top() === null && $this->previous !== null) {
                    foreach (array_reverse($lifted) as $liftedHandler) {
                        set_error_handler($liftedHandler);
                    }
                }

                $this->verdict = self::HANDLERS_REMOVED;
            }
        }

        $issues       = $this->issues;
        $this->issues = [];

        return $issues;
    }

    /**
     * The hygiene verdict of the last drain, in the incumbent's words:
     * handlers the window's code left installed, or handlers other
     * than its own that it removed. Null when the window bracketed
     * cleanly.
     */
    public function hygiene(): ?string
    {
        return $this->verdict;
    }

    /**
     * The active handler, read with no net effect: the probe pushes
     * and pops its own frame around the look.
     *
     * @return callable|null
     */
    private function top()
    {
        $probe = static fn(): bool => true;

        $saw = set_error_handler($probe);
        restore_error_handler();

        return $saw;
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
        // PHP reports the OS's own separator; the configured prefixes
        // may be joined with '/'.
        $file = WorkingDirectory::native($file);

        return array_any(
            $this->projectDirectories,
            static fn(string $directory): bool => str_starts_with($file, WorkingDirectory::native($directory)),
        );
    }

    /**
     * @param non-empty-string $file
     *
     * @return non-empty-string
     */
    private function relative(string $file): string
    {
        $relative = $this->workingDirectory->relative($file);

        return $relative === '' ? $file : $relative;
    }
}
