<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The command-line behaviours the snapshot harness holds still: every
 * early exit of a run, the selections, the folded suites, and the output
 * of a normal run. name => [project, argv].
 *
 * @return array<non-empty-string, array{project: non-empty-string, argv: list<string>}>
 */

return [
    // A normal run, green and red.
    'green'               => ['project' => 'green', 'argv' => []],
    'red'                 => ['project' => 'red', 'argv' => []],
    'red-stop-on-failure' => ['project' => 'red', 'argv' => ['--stop-on-failure']],
    'green-parallel'      => ['project' => 'green', 'argv' => ['--parallel', '2']],
    'green-testdox'       => ['project' => 'green', 'argv' => ['--testdox']],

    // Selection.
    'filter'                  => ['project' => 'green', 'argv' => ['--filter', 'Adds']],
    'filter-no-match'         => ['project' => 'green', 'argv' => ['--filter', 'nothing-matches-this']],
    'filter-no-match-allowed' => ['project' => 'green', 'argv' => ['--filter', 'nothing-matches-this', '--do-not-fail-on-empty-test-suite']],
    'group'                   => ['project' => 'green', 'argv' => ['--group', 'slow']],
    'exclude-group'           => ['project' => 'green', 'argv' => ['--exclude-group', 'slow']],
    'run-test-id'             => ['project' => 'green', 'argv' => ['--run-test-id', 'tests/CalculatorTest.php::testAdds']],
    'shard'                   => ['project' => 'green', 'argv' => ['--shard', '1/2']],
    'todos-none'              => ['project' => 'green', 'argv' => ['--todos']],

    // Listing.
    'list-tests'  => ['project' => 'green', 'argv' => ['--list-tests']],
    'list-groups' => ['project' => 'green', 'argv' => ['--list-groups']],
    'list-suites' => ['project' => 'mixed', 'argv' => ['--list-suites']],

    // The folded Vitest suite (D-079, D-125).
    'vitest-default'          => ['project' => 'mixed', 'argv' => []],
    'vitest-filter-leaves-js' => ['project' => 'mixed', 'argv' => ['--filter', 'Adds']],
    'vitest-named'            => ['project' => 'mixed', 'argv' => ['--testsuite', 'vitest']],
    'vitest-named-filtered'   => ['project' => 'mixed', 'argv' => ['--testsuite', 'unit,vitest', '--filter', 'totals']],
    'vitest-excluded'         => ['project' => 'mixed', 'argv' => ['--exclude-testsuite', 'vitest']],

    // Type tests (D-130).
    'types-default'             => ['project' => 'types', 'argv' => []],
    'types-named-filtered'      => ['project' => 'types', 'argv' => ['--testsuite', 'types', '--filter', 'string']],
    'types-excluded'            => ['project' => 'types', 'argv' => ['--exclude-testsuite', 'types']],
    'types-filter-leaves-types' => ['project' => 'types', 'argv' => ['--filter', 'Ok']],
    'types-list-suites'         => ['project' => 'types', 'argv' => ['--list-suites']],

    // Process isolation (D-124).
    'isolated-stderr' => ['project' => 'isolated', 'argv' => []],

    // Early exits.
    'empty-suite'          => ['project' => 'empty', 'argv' => []],
    'broken-configuration' => ['project' => 'broken', 'argv' => []],
    'throwing-dataset'     => ['project' => 'throwing-dataset', 'argv' => []],
    'unknown-testsuite'    => ['project' => 'green', 'argv' => ['--testsuite', 'nope']],
    'bad-order-by'         => ['project' => 'green', 'argv' => ['--order-by', 'sideways']],
    'bad-shard'            => ['project' => 'green', 'argv' => ['--shard', '3/2']],
    'malformed-subscriber' => ['project' => 'green', 'argv' => ['--subscriber', 'nocolon']],
    'unregistered-report'  => ['project' => 'green', 'argv' => ['--report', 'nosuchformat:out.txt']],
    'report-twice'         => ['project' => 'green', 'argv' => ['--log-pdf', 'a.pdf', '--report', 'pdf:b.pdf']],
    'unregistered-view'    => ['project' => 'green', 'argv' => ['--view', 'nosuchview']],
    'unknown-browser'      => ['project' => 'green', 'argv' => ['--browser', 'lynx']],
    'related-missing'      => ['project' => 'green', 'argv' => ['--related', 'src/Nope.php']],
    'missing-id-file'      => ['project' => 'green', 'argv' => ['--test-id-filter-file', 'nope.txt']],
    'unknown-option'       => ['project' => 'green', 'argv' => ['--no-such-option']],
];
