<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The conformance harness: runs every fixture suite against both the
 * PHPUnit oracle (black-box) and Crucible, then asserts identical
 * observable behavior — per-test outcomes and exit codes. Development
 * tool; requires phpunit-main/ (gitignored reference) to be present
 * with its dependencies installed.
 */


$root = \dirname(__DIR__);

/** The single source of truth for every oracle's probe path and install command. */
\define('ORACLES', require __DIR__ . '/oracles-registry.php');

if (!\is_file($root . '/phpunit-main/vendor/autoload.php')) {
    \fwrite(STDERR, "The PHPUnit oracle is not installed: run composer install --no-dev in phpunit-main/.\n");

    exit(2);
}

/**
 * stderr goes to a temp file, not a second pipe: draining pipes in
 * sequence deadlocks the moment a child writes more than the 64K pipe
 * buffer to the one not being read, and a crashing child (a fatal with
 * a deep stack trace) does exactly that. A hang here reads as "the
 * suite is slow" and costs far more than the file.
 *
 * @return array{int, string}
 */
function run_process(array $command, string $cwd): array
{
    $errorFile = \tempnam(\sys_get_temp_dir(), 'crucible-conformance-stderr-');

    if ($errorFile === false) {
        return [255, ''];
    }

    $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']], $pipes, $cwd);

    if ($process === false) {
        \unlink($errorFile);

        return [255, ''];
    }

    $stdout = (string) \stream_get_contents($pipes[1]);
    $exit   = \proc_close($process);
    $stderr = (string) \file_get_contents($errorFile);

    \unlink($errorFile);

    return [$exit, $stdout . $stderr];
}

/**
 * Per-test outcomes from the oracle's JUnit report, keyed
 * "class::method|dataset". Outcome classes: pass, fail, error, skip,
 * risky.
 *
 * The file belongs in the key: two test classes may legitimately name
 * a method the same thing, and keying on the method alone silently
 * collapsed them — the last one read won, and a divergence in the other
 * could not be seen. The file is what both sides state directly, and it
 * needs no agreement about namespaces: the oracle reports it on the
 * testcase, and Crucible's test id is built from it.
 *
 * @return array<string, string>
 */
function oracle_outcomes(string $junitFile): array
{
    $xml = \simplexml_load_string((string) \file_get_contents($junitFile));

    if ($xml === false) {
        return [];
    }

    $outcomes = [];

    foreach ($xml->xpath('//testcase') ?: [] as $testcase) {
        $name = (string) $testcase['name'];

        \preg_match('/^(?<m>.+?)(?: with data set (?:"(?<n>.+)"|#(?<i>\d+)))?$/s', $name, $match);

        $method  = $match['m'] ?? $name;
        $dataset = ($match['n'] ?? '') !== '' ? $match['n'] : (($match['i'] ?? '') !== '' ? $match['i'] : '');

        $outcome = 'pass';

        if (isset($testcase->failure)) {
            $outcome = 'fail';
        } elseif (isset($testcase->error)) {
            $type    = (string) $testcase->error['type'];
            $outcome = \stripos($type, 'risky') !== false ? 'risky' : 'error';
        } elseif (isset($testcase->skipped)) {
            $outcome = 'skip';
        }

        $outcomes[\basename((string) $testcase['file'], '.php') . '::' . $method . '|' . $dataset] = $outcome;
    }

    return $outcomes;
}

/**
 * Per-test outcomes from Crucible's NDJSON event stream, same keying and
 * outcome classes as the oracle side (incomplete normalizes to skip —
 * JUnit granularity).
 *
 * @return array<string, string>
 */
function crucible_outcomes(string $eventsFile): array
{
    $outcomes = [];

    foreach (\explode("\n", (string) \file_get_contents($eventsFile)) as $line) {
        if ($line === '') {
            continue;
        }

        $event = \json_decode($line, true);

        if (!\is_array($event) || ($event['event'] ?? '') !== 'test:finish') {
            continue;
        }

        $id    = (string) $event['id'];
        $parts = \explode('::', $id, 2);
        $name  = $parts[1] ?? $id;
        $bits  = \explode('#', $name, 2);

        $short = \basename($parts[0], '.php');

        $key = $short . '::' . $bits[0] . '|' . ($bits[1] ?? '');

        $outcomes[$key] = match ((string) $event['outcome']) {
            // JUnit-granularity normalization: the oracle's JUnit
            // report shows risky as pass and incomplete as skipped.
            // Full-fidelity risky/incomplete counts are compared via
            // the summary channel below.
            'risky'              => 'pass',
            'skip', 'incomplete' => 'skip',
            default              => (string) $event['outcome'],
        };
    }

    return $outcomes;
}

/**
 * Aggregate counts from the oracle's console summary — the channel
 * that preserves risky/incomplete fidelity JUnit lacks.
 *
 * @return array<string, int>
 */
function oracle_summary(string $output): array
{
    $counts = ['tests' => 0, 'errors' => 0, 'failures' => 0, 'skipped' => 0, 'incomplete' => 0, 'risky' => 0, 'deprecations' => 0, 'notices' => 0, 'warnings' => 0];

    if (\preg_match('/OK \((\d+) tests?/', $output, $match) === 1) {
        $counts['tests'] = (int) $match[1];

        return $counts;
    }

    foreach (['Tests' => 'tests', 'Errors' => 'errors', 'Failures' => 'failures', 'Skipped' => 'skipped', 'Incomplete' => 'incomplete', 'Risky' => 'risky', 'Deprecations' => 'deprecations', 'Notices' => 'notices', 'Warnings' => 'warnings'] as $label => $key) {
        if (\preg_match('/' . $label . ': (\d+)/', $output, $match) === 1) {
            $counts[$key] = (int) $match[1];
        }
    }

    return $counts;
}

/**
 * Aggregate counts from Crucible's run:finish event.
 *
 * @return array<string, int>
 */
function crucible_summary(string $eventsFile): array
{
    foreach (\explode("\n", (string) \file_get_contents($eventsFile)) as $line) {
        $event = $line !== '' ? \json_decode($line, true) : null;

        if (\is_array($event) && ($event['event'] ?? '') === 'run:finish') {
            $counts = $event['counts'];
            $issues = \is_array($event['issues'] ?? null) ? $event['issues'] : [];

            return [
                'tests'        => (int) \array_sum($counts),
                'errors'       => (int) $counts['error'],
                'failures'     => (int) $counts['fail'],
                'skipped'      => (int) $counts['skip'],
                'incomplete'   => (int) $counts['incomplete'],
                'risky'        => (int) $counts['risky'],
                'deprecations' => (int) ($issues['deprecations'] ?? 0),
                'notices'      => (int) ($issues['notices'] ?? 0),
                'warnings'     => (int) ($issues['warnings'] ?? 0),
            ];
        }
    }

    return [];
}

/**
 * Execution sequence from the oracle's JUnit report: testcase keys in
 * document order, which is execution order.
 *
 * @return list<string>
 */
function oracle_sequence(string $junitFile): array
{
    $xml = \simplexml_load_string((string) \file_get_contents($junitFile));

    if ($xml === false) {
        return [];
    }

    $sequence = [];

    foreach ($xml->xpath('//testcase') ?: [] as $testcase) {
        $name = (string) $testcase['name'];

        \preg_match('/^(?<m>.+?)(?: with data set (?:"(?<n>.+)"|#(?<i>\d+)))?$/s', $name, $match);

        $method  = $match['m'] ?? $name;
        $dataset = ($match['n'] ?? '') !== '' ? $match['n'] : (($match['i'] ?? '') !== '' ? $match['i'] : '');

        $sequence[] = $method . '|' . $dataset;
    }

    return $sequence;
}

/**
 * Execution sequence from Crucible's NDJSON stream: test:finish keys in
 * stream order.
 *
 * @return list<string>
 */
function crucible_sequence(string $eventsFile): array
{
    $sequence = [];

    foreach (\explode("\n", (string) \file_get_contents($eventsFile)) as $line) {
        $event = $line !== '' ? \json_decode($line, true) : null;

        if (!\is_array($event) || ($event['event'] ?? '') !== 'test:finish') {
            continue;
        }

        $id   = (string) $event['id'];
        $name = \explode('::', $id, 2)[1] ?? $id;
        $bits = \explode('#', $name, 2);

        $sequence[] = $bits[0] . '|' . ($bits[1] ?? '');
    }

    return $sequence;
}

$fixtures = \glob($root . '/conformance/fixtures/*') ?: [];
\sort($fixtures);

/*
 * --coverage: run the Crucible side under a driver scoped to src/, so
 * what this suite executes of the engine reaches the coverage map
 * instead of reading as untested. The artifacts land in
 * .crucible.cache/coverage.external, which the next `crucible
 * --coverage` merges, counts out loud and clears. Deliberately NOT
 * coverage.tmp: that one holds this-run worker artifacts and a
 * coverage run sweeps it clean before starting, which would delete
 * these every time.
 *
 * The oracle side is deliberately NOT instrumented: it does not load
 * Crucible, so there is nothing of Crucible's to observe, and slowing
 * it down would only make the comparison more expensive.
 */
$coverageArgs = [];

if (\in_array('--coverage', $argv, true)) {
    $artifacts = $root . '/.crucible.cache/coverage.external';

    if (!\is_dir($artifacts) && !\mkdir($artifacts, 0o777, true) && !\is_dir($artifacts)) {
        \fwrite(STDERR, 'Cannot create ' . $artifacts . "\n");

        exit(2);
    }

    // The child gets default ini, not this process's -d flags, so the
    // driver has to be enabled for it explicitly. Which one is
    // available is this machine's business; the prelude asks
    // DriverFactory the same question and writes nothing if the answer
    // is neither.
    $enable = match (true) {
        \extension_loaded('pcov')   => ['-d', 'pcov.enabled=1'],
        \extension_loaded('xdebug') => ['-d', 'xdebug.mode=coverage'],
        default                     => [],
    };

    if ($enable === []) {
        \fwrite(STDERR, "--coverage needs pcov or xdebug loaded; running without it.\n");
    } else {
        \putenv('CRUCIBLE_COVERAGE_ARTIFACTS=' . $artifacts);
        $coverageArgs = [...$enable, '-d', 'auto_prepend_file=' . $root . '/conformance/coverage-prelude.php'];
    }
}

$drifts  = 0;
$skipped = 0;

foreach ($fixtures as $fixture) {
    if (!\is_dir($fixture)) {
        continue;
    }

    $name = \basename($fixture);
    $tmp  = \sys_get_temp_dir() . '/crucible-conformance-' . \uniqid();
    \mkdir($tmp, 0o777, true);

    // Optional per-fixture harness options: extra CLI args passed to
    // BOTH runners, and order-sensitive comparison for ordering probes.
    $options = \is_file($fixture . '/options.json')
        ? (array) \json_decode((string) \file_get_contents($fixture . '/options.json'), true)
        : [];

    /** @var list<string> $extraArgs */
    $extraArgs       = \array_values(\array_map(strval(...), (array) ($options['args'] ?? [])));
    $compareSequence = (bool) ($options['compareSequence'] ?? false);

    // The mockery lane (D-066): the oracle is the real mockery/mockery
    // with its own PHPUnit, from the mockery-main reference install —
    // the crucible side runs the identical fixture through its Mockery
    // grammar and D-019 aliases.
    $mockeryLane   = ($options['oracle'] ?? 'phpunit') === 'mockery';
    $oracleWrapper = $mockeryLane
        ? $root . '/conformance/mockery-oracle.php'
        : $root . '/conformance/phpunit-oracle.php';

    // An oracle that is not installed cannot disagree with anything.
    // Reporting that as drift would make the whole suite cry wolf on
    // any machine without the optional reference checkout.
    if ($mockeryLane && !\file_exists($root . '/' . ORACLES['mockery-main']['probe'])) {
        $skipped++;
        echo \sprintf("SKIPPED   %s (the mockery oracle is not installed: %s)\n", $name, ORACLES['mockery-main']['install']);

        continue;
    }

    // Side A: the oracle.
    [$oracleExit, $oracleOutput] = \run_process(
        ['php', $oracleWrapper, '--log-junit', $tmp . '/junit.xml', ...$extraArgs, $fixture . '/tests'],
        $tmp,
    );
    $oracle = \oracle_outcomes($tmp . '/junit.xml');

    // Side B: Crucible, through the compat aliases.
    \file_put_contents($tmp . '/crucible-config.php', \sprintf(
        "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->testSuite('conformance', '%s');\n",
        $root . '/src/Compat/phpunit-aliases.php',
        $fixture . '/tests',
    ));

    [$crucibleExit] = \run_process(
        ['php', ...$coverageArgs, $root . '/crucible', '--configuration', $tmp . '/crucible-config.php', '--log-events-json', $tmp . '/events.ndjson', ...$extraArgs],
        $tmp,
    );
    $crucible = \crucible_outcomes($tmp . '/events.ndjson');

    // Compare.
    $problems = [];

    if ($oracleExit !== $crucibleExit) {
        $problems[] = \sprintf('exit code: oracle %d, crucible %d', $oracleExit, $crucibleExit);
    }

    $keys = \array_unique([...\array_keys($oracle), ...\array_keys($crucible)]);
    \sort($keys);

    foreach ($keys as $key) {
        $a = $oracle[$key] ?? '(missing)';
        $b = $crucible[$key] ?? '(missing)';

        if ($a !== $b) {
            $problems[] = \sprintf('%s: oracle %s, crucible %s', $key, $a, $b);
        }
    }

    // Order-sensitive comparison for ordering probes: the execution
    // sequence itself is the observable behavior under --order-by.
    if ($compareSequence) {
        $oracleSequence   = \oracle_sequence($tmp . '/junit.xml');
        $crucibleSequence = \crucible_sequence($tmp . '/events.ndjson');

        if ($oracleSequence !== $crucibleSequence) {
            $problems[] = \sprintf(
                "sequence:\n            oracle [%s]\n            crucible  [%s]",
                \implode(', ', $oracleSequence),
                \implode(', ', $crucibleSequence),
            );
        }
    }

    // Full-fidelity aggregate channel (risky/incomplete included).
    $oracleCounts   = \oracle_summary($oracleOutput);
    $crucibleCounts = \crucible_summary($tmp . '/events.ndjson');

    foreach ($oracleCounts as $metric => $expected) {
        $actual = $crucibleCounts[$metric] ?? -1;

        if ($expected !== $actual) {
            $problems[] = \sprintf('summary %s: oracle %d, crucible %d', $metric, $expected, $actual);
        }
    }

    if ($problems === []) {
        echo \sprintf("CONFORMS  %s (%d tests, exit %d)\n", $name, \count($oracle), $oracleExit);
    } else {
        $drifts++;
        echo \sprintf("DRIFT     %s\n", $name);

        foreach ($problems as $problem) {
            echo '          - ' . $problem . "\n";
        }
    }
}

echo $drifts === 0 ? "\nAll fixtures conform.\n" : \sprintf("\n%d fixture(s) drift.\n", $drifts);

if ($skipped > 0) {
    echo \sprintf("%d fixture(s) skipped for a missing oracle — not a verdict either way.\n", $skipped);
}

if ($coverageArgs !== []) {
    $written = \count(\glob($root . '/.crucible.cache/coverage.external/external-*.json') ?: []);

    echo \sprintf(
        "%d coverage artifact(s) written; the next `crucible --coverage` merges and clears them.\n",
        $written,
    );
}

exit($drifts === 0 ? 0 : 1);
