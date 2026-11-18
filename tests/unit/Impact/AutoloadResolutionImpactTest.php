<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Framework\TestCase;

use function chmod;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function glob;
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
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * What a warm dependency index does to a run whose Composer resolution
 * changed outside Git.
 *
 * A classmap is generated: `vendor/composer/autoload_classmap.php` moves
 * with a file layout that `composer.json` and the lockfile do not
 * record, and `vendor/` is not in the change set Git reports. The
 * dependency index keyed its answers on a fingerprint that ignored
 * the classmap entirely, so a run that heated the index with the class
 * resolving to one file kept replaying that file after the class had
 * been remapped — `--dirty` selected nothing, printed "no tests are
 * affected", and exited 0 while the full run was red. A selection mode
 * whose green means "nothing to run" earns CI trust only if the answer
 * is the same cold and warm.
 */
final class AutoloadResolutionImpactTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-autoload-impact-' . uniqid();

        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/tests', 0o777, true);
        mkdir($root . '/vendor/composer', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        // A composer-shaped vendor: a generated classmap behind the
        // autoloader the configuration bootstraps. None of it is
        // tracked, which is the whole point - a change there never
        // reaches the Git change set. The ClassLoader class itself is
        // the one the CLI process already provides; a real project's
        // vendor/ carries the same package, and its generated
        // autoload_real would find it loaded rather than redeclare it.
        file_put_contents($this->project . '/vendor/autoload.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            $loader = new \Composer\Autoload\ClassLoader();
            $loader->addClassMap(require __DIR__ . '/composer/autoload_classmap.php');
            $loader->register();

            return $loader;
            PHP);

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->bootstrap('vendor/autoload.php')
                ->testSuite('unit', 'tests')
                ->build();
            PHP);

        file_put_contents($this->project . '/.gitignore', "/vendor/\n/.crucible.cache/\n/.alt-cache/\nevents.ndjson\n");
        $this->classmap('src/Old.php');
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testARemappedClassStillSelectsItsTestFromAWarmIndex(): void
    {
        $this->classSource('src/Old.php', 'old');
        $this->greetingTest();
        $this->commit();

        // Heat the index: a changed PHP file walks every group's
        // closure, and the test file's dependencies are recorded with
        // the class resolving through the classmap. warm.php is
        // untracked, so removing it removes the change with it.
        file_put_contents($this->project . '/warm.php', "<?php // warming the dependency index\n");
        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit, 'The warming run itself should be green and select nothing.');
        unlink($this->project . '/warm.php');

        // The attack: remap the class in the classmap - untracked,
        // invisible to Git - and make the new resolution fail the test.
        $this->classmap('src/New.php');
        $this->classSource('src/New.php', 'new');

        [$exit, $stdout, $events] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $exit, 'A remapped dependency resolution was silently dropped from the selection.');
        self::assertStringNotContainsString('No tests are affected', $stdout);
        self::assertContains('tests/GreetingTest.php::testGreeting', $this->ranIds($events));

        // Cold parity: the same answer with the index deleted.
        $this->dropIndex('.crucible.cache');

        [$coldExit, , $coldEvents] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $coldExit, 'A cold run disagrees with the warm one - the index changes the answer.');
        self::assertSame($this->ranIds($events), $this->ranIds($coldEvents));

        // Full-run parity: the selection is honest about what a whole
        // run would say, not an artifact of narrowing.
        [$fullExit, , $fullEvents] = $this->crucible([]);

        self::assertNotSame(0, $fullExit);
        self::assertContains('tests/GreetingTest.php::testGreeting', $this->ranIds($fullEvents));
    }

    public function testAPsr4ShadowFileInAPriorityDirectoryStillSelectsItsTestFromAWarmIndex(): void
    {
        // Two directories answer one PSR-4 prefix, and Composer tries
        // them in the order they were given: a file that APPEARS in
        // the first shadows the one the warm index recorded in the
        // second. No tracked byte, no manifest and no classmap moves -
        // a fingerprint over configuration alone cannot see it, and
        // the stale answer names the shadowed file, so `--dirty`
        // selects nothing while the full run is red.
        $this->psr4Vendor();
        mkdir($this->project . '/lib-new');
        mkdir($this->project . '/lib-old');
        $this->classSource('lib-old/Greeting.php', 'old');
        $this->greetingTest();
        $this->commit();

        file_put_contents($this->project . '/warm.php', "<?php // warming the dependency index\n");
        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit, 'The warming run itself should be green and select nothing.');
        unlink($this->project . '/warm.php');

        // The attack: the shadow file, added inside the priority
        // directory, wins the resolution and fails the test.
        $this->classSource('lib-new/Greeting.php', 'new');

        [$exit, $stdout, $events] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $exit, 'A shadowing file in a priority directory was silently dropped from the selection.');
        self::assertStringNotContainsString('No tests are affected', $stdout);
        self::assertContains('tests/GreetingTest.php::testGreeting', $this->ranIds($events));

        // Cold parity: the same answer with the index deleted.
        $this->dropIndex('.crucible.cache');

        [$coldExit, , $coldEvents] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $coldExit, 'A cold run disagrees with the warm one - the index changes the answer.');
        self::assertSame($this->ranIds($events), $this->ranIds($coldEvents));

        // Full-run parity.
        [$fullExit, , $fullEvents] = $this->crucible([]);

        self::assertNotSame(0, $fullExit);
        self::assertContains('tests/GreetingTest.php::testGreeting', $this->ranIds($fullEvents));
    }

    public function testTheWinningLoaderOrderStillSelectsItsTestFromAWarmIndex(): void
    {
        // Two loaders both declare the class, and the first registered
        // wins. Reversing their order - a regenerated vendor, invisible
        // to Git - moves the resolution, and a fingerprint that sorts
        // its parts flat cannot see an order at all: both loaders
        // contributed the same sorted strings either way.
        $this->twoLoaderVendor('a', 'b');
        $this->classmapFile('autoload_classmap-a.php', 'src/A.php');
        $this->classmapFile('autoload_classmap-b.php', 'src/B.php');
        $this->classSource('src/A.php', 'old');
        $this->classSource('src/B.php', 'b');
        $this->greetingTest();
        $this->commit();

        file_put_contents($this->project . '/warm.php', "<?php // warming the dependency index\n");
        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit, 'The warming run itself should be green and select nothing.');
        unlink($this->project . '/warm.php');

        // The attack: reverse the loaders inside vendor/ - untracked -
        // and make the newly winning file fail.
        $this->twoLoaderVendor('b', 'a');
        $this->classSource('src/B.php', 'broken');

        [$exit, $stdout, $events] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $exit, 'A loader-priority change was silently dropped from the selection.');
        self::assertStringNotContainsString('No tests are affected', $stdout);
        self::assertContains('tests/GreetingTest.php::testGreeting', $this->ranIds($events));

        // Cold parity: the same answer with the index deleted.
        $this->dropIndex('.crucible.cache');

        [$coldExit, , $coldEvents] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $coldExit, 'A cold run disagrees with the warm one - the index changes the answer.');
        self::assertSame($this->ranIds($events), $this->ranIds($coldEvents));

        // Full-run parity.
        [$fullExit, , $fullEvents] = $this->crucible([]);

        self::assertNotSame(0, $fullExit);
        self::assertContains('tests/GreetingTest.php::testGreeting', $this->ranIds($fullEvents));
    }

    public function testASuiteConfigurationAppearingStillSelectsItsTestFromAWarmIndex(): void
    {
        // Green with no configuration above it: the pest file defaults
        // its state. The suite becomes red the moment a `Pest.php`
        // appears, because the beforeEach the discovery loads sets it.
        file_put_contents($this->project . '/tests/Example.pest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            \test('reads the suite configuration', function (): void {
                \expect($this->fromSuiteConfig ?? 'absent')->toBe('absent');
            });
            PHP);

        $this->commit();

        file_put_contents($this->project . '/warm.php', "<?php // warming the dependency index\n");
        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        unlink($this->project . '/warm.php');

        // Added, untracked, and above an unchanged test file: the case
        // a content hash cannot see and the fingerprint used to miss
        // entirely, because it never resolved relative ids against the
        // root at all.
        file_put_contents($this->project . '/tests/Pest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            \pest()->beforeEach(function (): void {
                $this->fromSuiteConfig = 'loaded';
            });
            PHP);

        [$exit, $stdout, $events] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $exit, 'An added suite configuration was silently dropped from the selection.');
        self::assertStringNotContainsString('No tests are affected', $stdout);
        self::assertContains(
            'tests/Example.pest.php::reads the suite configuration',
            $this->ranIds($events),
        );

        // Cold parity again: identical answer without the index.
        $this->dropIndex('.crucible.cache');

        [$coldExit, , $coldEvents] = $this->crucible(['--dirty']);

        self::assertNotSame(0, $coldExit);
        self::assertSame($this->ranIds($events), $this->ranIds($coldEvents));
    }

    public function testTheIndexLivesInTheCacheDirectoryTheRunWasGiven(): void
    {
        $this->classSource('src/Old.php', 'old');
        $this->greetingTest();
        $this->commit();

        file_put_contents($this->project . '/warm.php', "<?php // warming the dependency index\n");
        $this->crucible(['--dirty', '--cache-directory=.alt-cache']);
        unlink($this->project . '/warm.php');

        self::assertNotSame([], $this->matches($this->project . '/.alt-cache/*.deps.json'), 'The dependency index must live in the --cache-directory the run was given.');

        foreach ($this->matches($this->project . '/.crucible.cache/*.deps.json') as $stale) {
            self::fail('The dependency index ignored --cache-directory and wrote the configured directory: ' . $stale);
        }
    }

    /**
     * Which file the classmap resolves `Scratch\Greeting` to - the shape
     * composer's `autoload_classmap.php` has, so the remap looks like a
     * regenerated autoloader rather than a hand edit.
     */
    private function classmap(string $source): void
    {
        $this->classmapFile('autoload_classmap.php', $source);
    }

    /**
     * A named classmap - two loaders can each carry one, mapping the
     * same class to different files, which is how registration order
     * becomes part of the resolution.
     */
    private function classmapFile(string $name, string $source): void
    {
        file_put_contents($this->project . "/vendor/composer/{$name}", <<<PHP
            <?php

            return [
                'Scratch\\\\Greeting' => dirname(__DIR__, 2) . '/{$source}',
            ];
            PHP);
    }

    /**
     * A vendor whose resolution is PSR-4 over TWO directories: Composer
     * tries them in the order given, so a file appearing in the first
     * shadows the one the second already had.
     */
    private function psr4Vendor(): void
    {
        file_put_contents($this->project . '/vendor/autoload.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            $loader = new \Composer\Autoload\ClassLoader();
            $loader->addPsr4('Scratch\\', [dirname(__DIR__) . '/lib-new', dirname(__DIR__) . '/lib-old']);
            $loader->register();

            return $loader;
            PHP);
    }

    /**
     * A vendor with two registered loaders, $first before $second: both
     * declare the class, and the first registered wins.
     */
    private function twoLoaderVendor(string $first, string $second): void
    {
        file_put_contents($this->project . '/vendor/autoload.php', <<<PHP
            <?php

            declare(strict_types=1);

            \$firstLoader = new \Composer\Autoload\ClassLoader();
            \$firstLoader->addClassMap(require __DIR__ . '/composer/autoload_classmap-{$first}.php');
            \$firstLoader->register();

            \$secondLoader = new \Composer\Autoload\ClassLoader();
            \$secondLoader->addClassMap(require __DIR__ . '/composer/autoload_classmap-{$second}.php');
            \$secondLoader->register();
            PHP);
    }

    private function classSource(string $file, string $message): void
    {
        file_put_contents($this->project . '/' . $file, <<<PHP
            <?php

            declare(strict_types=1);

            namespace Scratch;

            final class Greeting
            {
                public static function message(): string
                {
                    return '{$message}';
                }
            }
            PHP);
    }

    private function greetingTest(): void
    {
        file_put_contents($this->project . '/tests/GreetingTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class GreetingTest extends TestCase
            {
                public function testGreeting(): void
                {
                    self::assertSame('old', \Scratch\Greeting::message());
                }
            }
            PHP);
    }

    private function commit(): void
    {
        $this->git('init -q');
        $this->git('config user.email crucible@example.test');
        $this->git('config user.name Crucible');
        $this->git('add .');
        $this->git('commit -q -m base');
    }

    private function dropIndex(string $directory): void
    {
        foreach ($this->matches($this->project . '/' . $directory . '/*.deps.json') as $index) {
            @unlink($index);
        }
    }

    /**
     * @return list<string>
     */
    private function matches(string $pattern): array
    {
        $found = glob($pattern);

        return $found === false ? [] : $found;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<string>}
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
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit     = proc_close($process);
        $contents = is_file($events) ? (string) file_get_contents($events) : '';

        /** @var list<string> $lines */
        $lines = [];

        foreach (explode("\n", $contents) as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return [$exit, $stdout, $lines];
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function ranIds(array $events): array
    {
        $ids = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $ids[] = $decoded['id'];
            }
        }

        return $ids;
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
