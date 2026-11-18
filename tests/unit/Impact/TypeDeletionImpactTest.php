<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_values;
use function chmod;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

use const PHP_BINARY;

/**
 * The type tier under impact selection: a deleted PHP file takes the
 * symbol its `*.types.php` assertion talks about with it, so a narrowed
 * run that keeps only the surviving paths answers green while the full
 * run is red (CB-24, D-130).
 *
 * Deletions are the one change whose role has to be read from the name
 * alone, so the filter keeps the suites for PHP source, for PHPStan's
 * configuration (including the `.neon.dist` name PHPStan accepts), and
 * for a name it cannot settle at all.
 */
#[Group('phpstan-blackbox')]
final class TypeDeletionImpactTest extends TestCase
{
    private const string TYPE_ID = 'tests/Types/Widget.types.php::line 9: list<int>';

    private const string PHP_TEST_ID = 'tests/Unit/WidgetTest.php::testControl';

    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-types-impact-' . uniqid();

        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/tests/Unit', 0o777, true);
        mkdir($root . '/tests/Types', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        $this->suite();
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testDeletingTheAnalysedFileMakesTheNarrowedRunRed(): void
    {
        $this->requirePhpstan();

        [$exit, , $before] = $this->crucible([]);
        self::assertSame(0, $exit, 'The fixture must start green.');
        self::assertSame('pass', $this->outcomeOf($before, self::TYPE_ID));

        unlink($this->project . '/src/Widget.php');

        // The control: without narrowing, the type assertion is red once
        // the definition is gone. That is what makes the narrowed answer
        // wrong, not an assumption about what PHPStan should say.
        [$exit, , $full] = $this->crucible([]);
        self::assertSame(1, $exit);
        self::assertSame('fail', $this->outcomeOf($full, self::TYPE_ID));

        [$exit, $stdout, $narrowed] = $this->crucible(['--dirty']);
        self::assertSame(1, $exit, 'A narrowed run answered green for a type assertion the full run fails.');
        self::assertStringContainsString('deleted file(s)', $stdout);
        self::assertSame('fail', $this->outcomeOf($narrowed, self::TYPE_ID), 'The type suite was dropped from the narrowed run.');
        self::assertSame('pass', $this->outcomeOf($narrowed, self::PHP_TEST_ID), 'The PHP tier widened on the deletion.');
    }

    public function testAChangedReferenceStillRunsTheTypesAfterACommittedDeletion(): void
    {
        $this->requirePhpstan();
        unlink($this->project . '/src/Widget.php');

        $this->git('add -A');
        $this->git('commit -q -m deleted');

        [$exit, , $finished] = $this->crucible(['--changed=HEAD~1']);

        self::assertSame(1, $exit);
        self::assertSame('fail', $this->outcomeOf($finished, self::TYPE_ID), 'A committed deletion is a deletion for --changed too.');
    }

    public function testRenamingTheAnalysedFileStillRunsTheTypes(): void
    {
        $this->requirePhpstan();

        // A rename is a deletion of the old name plus an addition of the
        // new one (CB-13). The addition does not look like PHP source, so
        // only the old name makes the tier run — and the symbol is gone.
        $this->git('mv src/Widget.php src/Widget.php.disabled');

        [$exit, , $finished] = $this->crucible(['--dirty']);

        self::assertSame(1, $exit);
        self::assertSame('fail', $this->outcomeOf($finished, self::TYPE_ID));
    }

    public function testDeletingTheAnalyserConfigurationKeepsTheTypeSuite(): void
    {
        $this->requirePhpstan();

        // PHPStan reads phpstan.neon.dist as its project configuration
        // (Phpstan::projectConfiguration()); the old filter only knew
        // names ending in .neon, so a deletion of this one dropped the
        // analysis. Without it the class cannot be resolved.
        unlink($this->project . '/phpstan.neon.dist');

        [$exit, , $finished] = $this->crucible(['--dirty']);

        self::assertSame(1, $exit);
        self::assertSame('fail', $this->outcomeOf($finished, self::TYPE_ID), 'The type suite was dropped with the analyser configuration.');
    }

    public function testADeletedEntryWithNoExtensionKeepsTheTypeSuite(): void
    {
        $this->requirePhpstan();
        unlink($this->project . '/Makefile');

        [$exit, , $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        self::assertSame(
            'pass',
            $this->outcomeOf($finished, self::TYPE_ID),
            'A name that cannot settle what the deleted entry was must keep the analysis.',
        );
    }

    public function testAnUnrelatedEditLeavesTheTypeSuiteOut(): void
    {
        file_put_contents($this->project . '/README.md', "edited\n");

        [$exit, $stdout, $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No tests are affected', $stdout);
        self::assertSame([], $finished, 'Nothing should run, least of all a PHPStan analysis.');
    }

    public function testAnUnrelatedDeletionLeavesTheTypeSuiteOut(): void
    {
        unlink($this->project . '/README.md');

        [$exit, , $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit, 'The PHP tier widens on the deletion and passes; the type tier is not in this change.');
        self::assertSame([self::PHP_TEST_ID], array_column($finished, 'id'));
    }

    private function requirePhpstan(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan is not installed (require-dev).');
        }
    }

    private function suite(): void
    {
        $phpstan = dirname(__DIR__, 3) . '/vendor/bin/phpstan';

        file_put_contents($this->project . '/crucible.php', sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->testSuite('unit', 'tests/Unit')
                ->typeTests('tests/Types', phpstan: %s)
                ->build();
            PHP, var_export($phpstan, true)));

        file_put_contents($this->project . '/phpstan.neon.dist', <<<'NEON'
            parameters:
                level: max
                scanDirectories:
                    - src
            NEON);

        file_put_contents($this->project . '/src/Widget.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Widget
            {
                /** @return list<int> */
                public static function ids(): array
                {
                    return [1, 2];
                }
            }
            PHP);

        file_put_contents($this->project . '/tests/Unit/WidgetTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class WidgetTest extends TestCase
            {
                public function testControl(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        file_put_contents($this->project . '/tests/Types/Widget.types.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use App\Widget;

            use function PHPStan\Testing\assertType;

            assertType('list<int>', Widget::ids());
            PHP);

        file_put_contents($this->project . '/README.md', "base\n");
        file_put_contents($this->project . '/Makefile', "all:\n");
        file_put_contents($this->project . '/.gitignore', ".crucible.cache/\nevents.ndjson\n");

        $this->git('init -q');
        $this->git('config user.email crucible@example.test');
        $this->git('config user.name Crucible');
        $this->git('add .');
        $this->git('commit -q -m base');
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<array{id: string, outcome: string}>}
     */
    private function crucible(array $arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible', ...$arguments, '--log-events-json', $events],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit     = proc_close($process);
        $contents = is_file($events) ? (string) file_get_contents($events) : '';

        // No stream at all is a legitimate answer: a run that selected
        // nothing writes no events. A caller expecting a test sees it in
        // the empty result, not here.
        /** @var list<non-empty-string> $lines */
        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn(string $line): bool => $line !== '',
        ));

        $finished = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $finished[] = [
                    'id'      => $decoded['id'],
                    'outcome' => is_string($decoded['outcome'] ?? null) ? $decoded['outcome'] : '',
                ];
            }
        }

        return [$exit, $stdout, $finished];
    }

    /**
     * @param list<array{id: string, outcome: string}> $finished
     */
    private function outcomeOf(array $finished, string $id): ?string
    {
        foreach ($finished as $entry) {
            if ($entry['id'] === $id) {
                return $entry['outcome'];
            }
        }

        return null;
    }

    private function git(string $command): void
    {
        $process = proc_open(
            ['git', '-C', $this->project, ...explode(' ', $command)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('git ' . $command . ' could not be started.');
        }

        $error = (string) stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            self::fail('git ' . $command . ' failed: ' . $error);
        }
    }

    /** Git leaves its objects read-only, which Windows will not unlink until told otherwise. */
    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                chmod($path, 0o666);
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
