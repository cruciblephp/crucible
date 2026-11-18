<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\Process\WorkerManifest;
use LucianoPereira\Crucible\Runner\Process\WorkerProcess;
use LucianoPereira\Crucible\Runner\Process\WorkUnit;

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
use function microtime;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function sort;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;
use function usleep;
use function var_export;

use const PHP_BINARY;

/**
 * A worker's NDJSON events travel on stderr behind a per-frame marker
 * (CB-23), never on stdout: what the bootstrap, discovery or a test
 * writes to the console cannot swallow a frame, even without a trailing
 * newline. Sharing the stderr pipe keeps the D-124 attribution order,
 * and the failure the CB-20 reproduction used to lose now arrives with
 * its message.
 */
final class WorkerEventTransportTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    /** @var list<non-empty-string> */
    private array $scripts = [];

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-transport-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }

        $this->remove($this->project);
    }

    public function testBootstrapStdoutCannotCorruptTheFirstFrames(): void
    {
        file_put_contents(
            $this->project . '/bootstrap.php',
            "<?php\n\nfwrite(STDOUT, 'bootstrap noise without a newline');\n",
        );
        $this->project(bootstrap: 'bootstrap.php');
        $this->writeTest('FirstTest', 'public function testOne(): void { self::assertTrue(true); }');
        $this->writeTest('SecondTest', 'public function testTwo(): void { self::assertTrue(true); }');

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit);
        self::assertSame(
            ['tests/FirstTest.php::testOne', 'tests/SecondTest.php::testTwo'],
            $this->startedIds($events),
            'The bootstrap output swallowed a test:start frame.',
        );
        self::assertTrue($this->completed($events));
    }

    public function testDiscoveryStdoutCannotCorruptAWorkerFrame(): void
    {
        $this->project();
        $this->writeTest(
            'NoisyTest',
            'public function testNoisy(): void { self::assertTrue(true); }',
            "echo 'discovery noise without a newline';",
        );
        $this->writeTest('QuietTest', 'public function testQuiet(): void { self::assertTrue(true); }');

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit);
        self::assertSame(
            ['tests/NoisyTest.php::testNoisy', 'tests/QuietTest.php::testQuiet'],
            $this->startedIds($events),
            'Discovery output swallowed a test:start frame.',
        );
    }

    public function testAnIsolatedTestsStderrIsStillAttributedWithFramedEvents(): void
    {
        $this->project();
        $this->writeTest('StderrProbeTest', <<<'PHP'
            #[\LucianoPereira\Crucible\Attributes\RunInSeparateProcess]
            public function testProbe(): void
            {
                fwrite(STDOUT, 'raw stdout without a newline');
                fwrite(STDERR, 'probe says hello');
                self::assertTrue(true);
            }
            PHP);
        $this->writeTest('OtherTest', 'public function testOther(): void { self::assertTrue(true); }');

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(2, $exit, 'D-124: an isolated test that wrote to STDERR errors.');
        self::assertSame('error', $this->outcomeOf($events, 'tests/StderrProbeTest.php::testProbe'));
        self::assertStringContainsString(
            'probe says hello',
            $this->failureOf($events, 'tests/StderrProbeTest.php::testProbe'),
        );
    }

    public function testUserStderrWithoutANewlineCannotSwallowAFrame(): void
    {
        $worker = $this->spawn(
            'fwrite(STDERR, "raw noise without newline");'
            . 'fwrite(STDERR, ' . var_export(WorkerProcess::EVENT_MARKER, true) . ' . "{\"event\":\"test:start\",\"id\":\"tests/FooTest.php::testA\"}\n");'
            . 'fwrite(STDERR, "text after the frame");',
        );

        self::assertSame(
            '{"event":"test:start","id":"tests/FooTest.php::testA"}',
            $this->nextFrame($worker),
            'The marker, not a user newline, delimits the frame.',
        );
        self::assertSame('raw noise without newline', $worker->takeStderr());
        self::assertSame(3, $worker->close());
    }

    public function testAPartialFrameAtExitIsNotAUserLine(): void
    {
        $worker = $this->spawn(
            'fwrite(STDERR, ' . var_export(WorkerProcess::EVENT_MARKER, true) . ' . "{\"event\":\"test:start\"");',
        );

        self::assertNull($worker->nextLine(), 'An incomplete frame is not a line.');
        self::assertSame(3, $worker->close(), 'The transport never blocks or hangs on a partial frame.');
    }

    private function spawn(string $body): WorkerProcess
    {
        $script = tempnam(sys_get_temp_dir(), 'crucible-transport-script-');

        if ($script === false) {
            self::fail('The transport script could not be created.');
        }

        $this->scripts[] = $script;
        file_put_contents($script, '<?php stream_get_contents(STDIN); ' . $body . ' exit(3);');

        $worker = WorkerProcess::spawn(
            $script,
            new WorkingDirectory(sys_get_temp_dir()),
            new WorkerManifest('/project/crucible.php', [new WorkUnit('tests/FooTest.php')]),
            [],
        );

        self::assertInstanceOf(WorkerProcess::class, $worker);

        return $worker;
    }

    private function nextFrame(WorkerProcess $worker): string
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $line = $worker->nextLine();

            if ($line !== null) {
                return $line;
            }

            usleep(5_000);
        }

        self::fail('No frame arrived.');
    }

    private function project(string $bootstrap = ''): void
    {
        $bootstrapCall = $bootstrap === '' ? '' : "->bootstrap('" . $bootstrap . "')";

        file_put_contents($this->project . '/crucible.php', <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\\Crucible\\Configuration\\Crucible;

            return Crucible::configure(){$bootstrapCall}->testSuite('unit', 'tests')->build();
            PHP);
    }

    private function writeTest(string $class, string $body, string $prelude = ''): void
    {
        file_put_contents($this->project . '/tests/' . $class . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\\Crucible\\Framework\\TestCase;

            {$prelude}

            final class {$class} extends TestCase
            {
            {$body}
            }
            PHP);
    }

    /**
     * @param list<array<mixed, mixed>> $events
     *
     * @return list<string>
     */
    private function startedIds(array $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'test:start' && is_string($event['id'] ?? null)) {
                $ids[] = $event['id'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param list<array<mixed, mixed>> $events
     */
    private function outcomeOf(array $events, string $id): ?string
    {
        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'test:finish' && ($event['id'] ?? null) === $id) {
                return is_string($event['outcome'] ?? null) ? $event['outcome'] : null;
            }
        }

        return null;
    }

    /**
     * @param list<array<mixed, mixed>> $events
     */
    private function failureOf(array $events, string $id): string
    {
        foreach ($events as $event) {
            if (($event['event'] ?? null) !== 'test:finish' || ($event['id'] ?? null) !== $id) {
                continue;
            }

            $error = is_array($event['error'] ?? null) ? $event['error'] : [];

            return is_string($error['message'] ?? null) ? $error['message'] : '';
        }

        return '';
    }

    /**
     * @param list<array<mixed, mixed>> $events
     */
    private function completed(array $events): bool
    {
        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'run:finish') {
                return ($event['complete'] ?? false) === true;
            }
        }

        self::fail('The run produced no run:finish event.');
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, list<array<mixed, mixed>>}
     */
    private function crucible(array $arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/crucible', ...$arguments, '--log-events-json', $events],
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

        if ($contents === '') {
            self::fail('No event stream was written. stdout: ' . $stdout . ' stderr: ' . $stderr);
        }

        /** @var list<non-empty-string> $lines */
        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn(string $line): bool => $line !== '',
        ));

        $decoded = [];

        foreach ($lines as $line) {
            $event = json_decode($line, true);

            if (is_array($event)) {
                $decoded[] = $event;
            }
        }

        return [$exit, $decoded];
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
