<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use Closure;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\IssueCollector;

use function dirname;
use function fclose;
use function file_put_contents;
use function is_resource;
use function proc_close;
use function proc_open;
use function restore_error_handler;
use function set_error_handler;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trigger_error;
use function uniqid;
use function unlink;

use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const PHP_BINARY;

#[CoversClass(IssueCollector::class)]
final class IssueCollectorTest extends TestCase
{
    /**
     * @param list<non-empty-string> $baseline
     */
    private function collector(array $baseline = []): IssueCollector
    {
        // This test file's directory counts as project code.
        return new IssueCollector([__DIR__ . '/'], new WorkingDirectory(dirname(__DIR__, 3)), $baseline);
    }

    public function testCapturesAllThreeKindsWithoutDisturbingTheTest(): void
    {
        $collector = $this->collector();
        $collector->install();

        trigger_error('going away', E_USER_DEPRECATED);
        trigger_error('heads up', E_USER_NOTICE);
        trigger_error('watch out', E_USER_WARNING);

        $issues = $collector->drain();

        $this->assertCount(3, $issues);
        $this->assertSame(IssueKind::Deprecation, $issues[0]->kind);
        $this->assertSame(IssueKind::Notice, $issues[1]->kind);
        $this->assertSame(IssueKind::Warning, $issues[2]->kind);
        $this->assertSame('going away', $issues[0]->message);
        $this->assertSame(
            ['deprecations' => 1, 'notices' => 1, 'warnings' => 1],
            $collector->tallies(),
        );
    }

    public function testSilencedErrorsAreNotCounted(): void
    {
        $collector = $this->collector();
        $collector->install();

        @trigger_error('nobody should see this', E_USER_DEPRECATED);

        $this->assertCount(0, $collector->drain());
    }

    public function testDeprecationsTriggeredInProjectCodeAreSelf(): void
    {
        $collector = $this->collector();
        $collector->install();

        trigger_error('own code deprecation', E_USER_DEPRECATED);

        $issues = $collector->drain();

        $this->assertSame(DeprecationScope::Self_, $issues[0]->scope);
    }

    public function testBaselinedDeprecationsAreSuppressedAtCapture(): void
    {
        $file = 'tests/unit/Runner/IssueCollectorTest.php';

        $collector = $this->collector([$file . '|acknowledged legacy']);
        $collector->install();

        trigger_error('acknowledged legacy', E_USER_DEPRECATED);
        trigger_error('brand new problem', E_USER_DEPRECATED);

        $issues = $collector->drain();

        $this->assertCount(1, $issues);
        $this->assertSame('brand new problem', $issues[0]->message);
    }

    public function testDrainResetsPerTestButTalliesAccumulate(): void
    {
        $collector = $this->collector();

        $collector->install();
        trigger_error('first test', E_USER_NOTICE);
        $collector->drain();

        $collector->install();
        trigger_error('second test', E_USER_NOTICE);

        $this->assertCount(1, $collector->drain());
        $this->assertSame(2, $collector->tallies()['notices']);

        $collector->resetTallies();

        $this->assertSame(0, $collector->tallies()['notices']);
    }

    /**
     * @return callable|null the active handler, read with no net effect
     */
    private function top()
    {
        $probe = static fn(): bool => true;

        $saw = set_error_handler($probe);
        restore_error_handler();

        return $saw;
    }

    /**
     * A recording stand-in for the handler a bootstrap would install:
     * what sits below the window, and what must survive it.
     *
     * @param list<string> $seen
     */
    private function outer(array &$seen): Closure
    {
        return static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        };
    }

    public function testADrainRemovesAHandlerTheTestLeftAboveTheWindow(): void
    {
        $seen      = [];
        $bootstrap = $this->outer($seen);
        set_error_handler($bootstrap);

        $collector = $this->collector();
        $collector->install();

        // The test's own handler, never removed: a blind one-frame
        // restore pops THIS one and leaves the collector's live, which
        // is how errors between windows ended up in the next one's
        // drain (F-43).
        set_error_handler(static fn(): bool => true);

        $collector->drain();

        $this->assertSame($bootstrap, $this->top(), 'The stack must be back to the pre-window handler.');
        $this->assertSame(IssueCollector::HANDLERS_LEFT, $collector->hygiene());

        // The error between the windows now reaches the handler that
        // was there before the test, not the next test's window.
        trigger_error('between the windows', E_USER_NOTICE);

        $collector->install();
        $issues = $collector->drain();

        $this->assertSame(['between the windows'], $seen);
        $this->assertSame([], $issues, 'An error between windows must not be attributed to the next test.');
        $this->assertNull($collector->hygiene(), 'The clean window after a leaky one is clean.');

        restore_error_handler();
    }

    public function testADrainNeverRemovesTheHandlerItReplaced(): void
    {
        $seen      = [];
        $bootstrap = $this->outer($seen);
        set_error_handler($bootstrap);

        $collector = $this->collector();
        $collector->install();

        // The test popped the collector's handler itself: the restore
        // must stop at the bootstrap's, not remove it too.
        restore_error_handler();

        $collector->drain();

        $this->assertSame($bootstrap, $this->top());
        $this->assertSame(IssueCollector::HANDLERS_REMOVED, $collector->hygiene());

        restore_error_handler();
    }

    public function testADrainPutsBackWhatAWreckedStackStillHas(): void
    {
        // The breach-below-the-floor case cannot be asserted in this
        // process: the suite hosting this test brackets it with its own
        // collector, and deliberately popping past a window's floor
        // disturbs the host's frames too - the host would flag this
        // very test, which is the verdict working as designed. A bare
        // child process owns its whole stack and can say what the
        // drain did to what remained.
        $repoRoot = dirname(__DIR__, 3);
        $script   = sys_get_temp_dir() . '/crucible-error-handler-wreck-' . uniqid() . '.php';

        file_put_contents($script, <<<PHP
            <?php

            declare(strict_types=1);

            require '{$repoRoot}/vendor/autoload.php';

            use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
            use LucianoPereira\Crucible\Runner\IssueCollector;

            \$seen = [];
            \$deeper = static function (int \$level, string \$message) use (&\$seen): bool {
                \$seen[] = 'deeper saw ' . \$message;

                return true;
            };
            \$floor = static function (int \$level, string \$message) use (&\$seen): bool {
                \$seen[] = 'floor saw ' . \$message;

                return true;
            };

            set_error_handler(\$deeper);
            set_error_handler(\$floor);

            \$collector = new IssueCollector([], new WorkingDirectory('{$repoRoot}'));
            \$collector->install();

            // The window removes both its own handler and the floor
            // beneath it, exposing the deeper frame.
            restore_error_handler();
            restore_error_handler();

            \$collector->drain();

            \$probe = static fn (): bool => true;
            \$top   = set_error_handler(\$probe);
            restore_error_handler();

            if (\$top !== \$deeper) {
                fwrite(STDOUT, 'FAIL: the deeper frame was not kept. Saw: ' . implode(' | ', \$seen));
                exit(1);
            }

            if (\$collector->hygiene() !== IssueCollector::HANDLERS_REMOVED) {
                fwrite(STDOUT, 'FAIL: the verdict was ' . var_export(\$collector->hygiene(), true));
                exit(1);
            }

            fwrite(STDOUT, 'OK: deeper frame kept, verdict reported');
            exit(0);
            PHP);

        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('The wreck fixture could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        @unlink($script);

        self::assertSame(0, $exit, $stdout);
        self::assertStringStartsWith('OK:', $stdout);
    }

    public function testADrainIsNotFooledByAReinstalledFloorCallable(): void
    {
        // The spoofable-floor case cannot live in this process either:
        // probing the stack the drain leaves behind means popping
        // frames, and the host suite brackets this test with its own
        // collector, which would flag the probe as the verdict working.
        // A bare child process owns its whole stack.
        $repoRoot = dirname(__DIR__, 3);
        $script   = sys_get_temp_dir() . '/crucible-error-handler-spoof-' . uniqid() . '.php';

        file_put_contents($script, <<<PHP
            <?php

            declare(strict_types=1);

            require '{$repoRoot}/vendor/autoload.php';

            use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
            use LucianoPereira\Crucible\Runner\IssueCollector;

            \$seen = [];
            \$bootstrap = static function (int \$level, string \$message) use (&\$seen): bool {
                \$seen[] = 'bootstrap saw ' . \$message;

                return true;
            };

            set_error_handler(\$bootstrap);

            \$collector = new IssueCollector([], new WorkingDirectory('{$repoRoot}'));
            \$collector->install();

            // The window's code reinstalls the BOOTSTRAP'S own callable
            // above the collector: a floor matched by callable stops the
            // walk where it stands, and the collector stays installed
            // under the copy - every later error lands in a window that
            // never ran it.
            set_error_handler(\$bootstrap);

            \$collector->drain();

            // Read the whole remaining stack by popping it, and put it
            // back: what must remain is the bootstrap's frame, exactly
            // once - the copy dropped, the collector gone.
            \$remaining = [];
            \$probe = static fn (): bool => true;

            while (true) {
                \$top = set_error_handler(\$probe);
                restore_error_handler();

                if (\$top === null) {
                    break;
                }

                \$remaining[] = \$top;
                restore_error_handler();
            }

            foreach (array_reverse(\$remaining) as \$handler) {
                set_error_handler(\$handler);
            }

            if (\$remaining !== [\$bootstrap]) {
                fwrite(STDOUT, 'FAIL: the drain left ' . count(\$remaining) . ' frame(s) where the bootstrap\'s alone should remain - the collector survived under the copy');
                exit(1);
            }

            if (\$collector->hygiene() !== IssueCollector::HANDLERS_LEFT) {
                fwrite(STDOUT, 'FAIL: the verdict was ' . var_export(\$collector->hygiene(), true));
                exit(1);
            }

            fwrite(STDOUT, 'OK: the copy was dropped, the collector uninstalled, the floor intact');
            exit(0);
            PHP);

        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('The spoof fixture could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        @unlink($script);

        self::assertSame(0, $exit, $stdout);
        self::assertStringStartsWith('OK:', $stdout);
    }

    public function testARestoredFrameKeepsAnsweringButCarriesTheMaskPhpAllowsUsToGiveIt(): void
    {
        // The mask case shares the wreck's constraint: reading the stack
        // the drain leaves behind means popping frames, which the host
        // suite would flag - and the pinned behaviour is PHP's own
        // floor, so the child process says what the incumbent's
        // TestCase does: set_error_handler never reports the mask it
        // replaced, and the incumbent's own enumeration
        // (activeErrorHandlers, TestCase.php:1751) reinstalls every
        // handler on the stack with the default mask on every test, so
        // no mask-preserving bar exists to match.
        $repoRoot = dirname(__DIR__, 3);
        $script   = sys_get_temp_dir() . '/crucible-error-handler-mask-' . uniqid() . '.php';

        file_put_contents($script, <<<PHP
            <?php

            declare(strict_types=1);

            require '{$repoRoot}/vendor/autoload.php';

            use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
            use LucianoPereira\Crucible\Runner\IssueCollector;

            \$seen = [];
            \$masked = static function (int \$level, string \$message) use (&\$seen): bool {
                \$seen[] = 'masked saw ' . \$message;

                return true;
            };
            \$floor = static function (int \$level, string \$message) use (&\$seen): bool {
                \$seen[] = 'floor saw ' . \$message;

                return true;
            };

            // A handler registered for E_USER_WARNING only.
            set_error_handler(\$masked, E_USER_WARNING);
            set_error_handler(\$floor);

            \$collector = new IssueCollector([], new WorkingDirectory('{$repoRoot}'));
            \$collector->install();

            // The window removes its own handler and the floor beneath
            // it, exposing the masked frame - the walk must put back
            // what it had to lift to notice, exactly as the window
            // found it.
            restore_error_handler();
            restore_error_handler();

            \$collector->drain();

            \$probe = static fn (): bool => true;
            \$top   = set_error_handler(\$probe);
            restore_error_handler();

            if (\$top !== \$masked) {
                fwrite(STDOUT, 'FAIL: the masked frame did not survive the drain. Saw: ' . implode(' | ', \$seen));
                exit(1);
            }

            // What the drain reinstalled answers - the set is the
            // window's own - but PHP gives no way to read a registered
            // handler's mask, so the restored frame carries the
            // default one, exactly as the incumbent's snapshot restore
            // does. Pinned as the documented floor, not a silent drift.
            trigger_error('a warning it wanted', E_USER_WARNING);

            if (!in_array('masked saw a warning it wanted', \$seen, true)) {
                fwrite(STDOUT, 'FAIL: the restored frame no longer answers what its mask asked for. Saw: ' . implode(' | ', \$seen));
                exit(1);
            }

            trigger_error('a notice it never asked for', E_USER_NOTICE);

            if (!in_array('masked saw a notice it never asked for', \$seen, true)) {
                fwrite(STDOUT, 'FAIL: the restored frame no longer answers at all. Saw: ' . implode(' | ', \$seen));
                exit(1);
            }

            if (\$collector->hygiene() !== IssueCollector::HANDLERS_REMOVED) {
                fwrite(STDOUT, 'FAIL: the verdict was ' . var_export(\$collector->hygiene(), true));
                exit(1);
            }

            fwrite(STDOUT, 'OK: masked frame restored and answering, verdict reported');
            exit(0);
            PHP);

        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('The mask fixture could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        @unlink($script);

        self::assertSame(0, $exit, $stdout);
        self::assertStringStartsWith('OK:', $stdout);
    }

    public function testRepeatedWindowsWithLeaksStayBalanced(): void
    {
        $seen      = [];
        $bootstrap = $this->outer($seen);
        set_error_handler($bootstrap);

        $collector = $this->collector();

        for ($window = 0; $window < 3; $window++) {
            $collector->install();
            set_error_handler(static fn(): bool => true);
            $collector->drain();

            $this->assertSame(IssueCollector::HANDLERS_LEFT, $collector->hygiene());
        }

        $this->assertSame($bootstrap, $this->top(), 'Three leaky windows may not accumulate frames.');

        restore_error_handler();
    }

    public function testACleanWindowLeavesNoVerdict(): void
    {
        $collector = $this->collector();
        $collector->install();
        $collector->drain();

        $this->assertNull($collector->hygiene());
    }
}
