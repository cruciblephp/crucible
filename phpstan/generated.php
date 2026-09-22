<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Type-checks the code Crucible writes at runtime.
 *
 * Generated code is the one region no gate reads: PHPStan cannot see
 * inside a string literal, phpcpd finds no clone there (D-108), and the
 * mutation runner drops eval()'d pseudo-paths. That is a CHOICE, not a
 * law — a string is invisible, the same string in a file is not.
 *
 * So every shape in the corpora is generated with recording on, written
 * out, and analysed at level max. Nothing is committed and nothing is
 * compared against a stored copy: the sources are produced fresh every
 * run, which is the only way this cannot pass vacuously. A snapshot
 * checked in and merely read would keep type-checking a generator that
 * has since changed.
 */

use LucianoPereira\Crucible\Bridge\PestSnapshots\SnapshotIdentityWrapper;
use LucianoPereira\Crucible\Dialect\Inline\InlineBuilder;
use LucianoPereira\Crucible\Dialect\Pest\TraitComposer;
use LucianoPereira\Crucible\Double\Mockery\MockeryContainer;
use LucianoPereira\Crucible\Double\TestDoubles;
use LucianoPereira\Crucible\Generated\GeneratedCode;
use LucianoPereira\Crucible\PHPStan\ApprovedGeneratorRule;

$root = \dirname(__DIR__);

require $root . '/vendor/autoload.php';
require $root . '/conformance/drift.php';
require $root . '/tests/_fixtures/double-shapes/shapes.php';
require $root . '/tests/_fixtures/trait-shapes/shapes.php';
require $root . '/tests/_fixtures/mockery-shapes/shapes.php';
require $root . '/tests/_fixtures/snapshot-shapes/shapes.php';

$directory = $root . '/.phpstan.cache/generated';

if (!\is_dir($directory) && !\mkdir($directory, 0o777, true) && !\is_dir($directory)) {
    \fwrite(STDERR, 'Cannot create ' . $directory . \PHP_EOL);

    exit(2);
}

foreach (\glob($directory . '/*.php') ?: [] as $stale) {
    \unlink($stale);
}


/*
 * A file count is a true number answering the wrong question: "21 files"
 * stayed true and readable for months while four of the five generators
 * went unanalysed. So the verdict states its own size -- what each
 * generator contributed -- and a generator that contributes NOTHING
 * fails the run rather than shrinking a total nobody reads.
 *
 * Attribution is positional, not scraped: each block below drives
 * exactly one generator, and the delta in recorded files across it is
 * that generator's contribution. Sources are named by md5 of their
 * content, so this counts DISTINCT SHAPES and two identical calls
 * collapse into one -- which is the number worth gating on. It is still
 * not "structures" in the registry sense; that arrives with
 * GeneratedTypes::structures() post-1.0.
 */
$recorded = static fn(): int => \count(\glob($directory . '/*.php') ?: []);

/** @var array<string, int> $contribution */
$contribution = [];
$mark         = 0;

$attribute = static function (string $generator) use (&$contribution, &$mark, $recorded): void {
    $now = $recorded();

    $contribution[$generator] = $now - $mark;
    $mark                     = $now;
};

$shapes    = 'LucianoPereira\\Crucible\\Tests\\Fixtures\\DoubleShapes\\';
$traits    = 'LucianoPereira\\Crucible\\Tests\\Fixtures\\TraitShapes\\';
$mockery   = 'LucianoPereira\\Crucible\\Tests\\Fixtures\\MockeryShapes\\';
$snapshots = 'LucianoPereira\\Crucible\\Tests\\Fixtures\\SnapshotShapes\\';
$doubles   = new TestDoubles();

// Scoped: recording stops when this returns, however it returns. A
// generator throwing midway used to leave the hook on.
GeneratedCode::recording($directory, static function () use ($shapes, $traits, $mockery, $snapshots, $doubles, $attribute): void {
    // Every double shape the corpus holds. ObjectDefault and its
    // argument-carrying sibling are the ones that used to end the process.
    foreach ([
        'ByRef', 'EnumDefault', 'NeverReturn', 'ObjectDefault', 'ObjectDefaultWithArguments',
        'ScalarDefaults', 'SelfReturn', 'StaticMethod', 'Tentative', 'UnionType', 'Variadic', 'VoidReturn',
    ] as $shape) {
        /** @var class-string $target */
        $target = $shapes . $shape;
        $doubles->create($target);
    }

    $attribute(\LucianoPereira\Crucible\Double\Generator::class);

    // The compositions that compose; the refused ones generate nothing by
    // definition, which is what their own corpus proves.
    /** @var class-string $plain */
    $plain = $traits . 'PlainBase';
    TraitComposer::compose($plain, [$traits . 'Greets']);
    TraitComposer::compose($plain, [$traits . 'Greets', $traits . 'Counts']);
    TraitComposer::compose($plain, [$traits . 'Greets', $traits . 'DemandsGreeting']);

    $attribute(TraitComposer::class);

    // A name nothing declares, a trait mixed into the double, the mockery
    // surface's own generated shape.
    MockeryContainer::mock('CrucibleGeneratedProbeThing');
    MockeryContainer::mock($mockery . 'ShapeTrait');
    MockeryContainer::mock($mockery . 'ShapeInterface');

    // The namespaced arm of declareEmptyClass (:305). Every other probe
    // name is either a real class or has no backslash, so only the global
    // arm (:304) was ever driven and the namespaced source was generated by
    // nothing the tier reads.
    MockeryContainer::mock('CrucibleGenerated\\Probe\\NamespacedThing');

    $attribute(MockeryContainer::class);

    /** @var class-string $snapshotting */
    $snapshotting = $snapshots . 'Snapshotting';
    SnapshotIdentityWrapper::wrap($snapshotting);

    $attribute(SnapshotIdentityWrapper::class);
});

$silent = \array_keys(\array_filter($contribution, static fn(int $shapes): bool => $shapes === 0));

if ($silent !== []) {
    \fwrite(
        STDERR,
        'Recorded nothing for: ' . \implode(', ', $silent) . \PHP_EOL
            . 'A registered generator that contributes no shape is not analysed, and a total would '
            . 'have hidden it.' . \PHP_EOL,
    );

    exit(2);
}

/*
 * The expression seam, gated differently on purpose.
 *
 * InlineBuilder is the fifth generator and does not belong in the
 * level-max analysis above: its payload is the AUTHOR's expression, so
 * analysing the composed source would grade the author rather than the
 * generator. Its gate is the one thing the generator is responsible
 * for -- that what it composes PARSES -- and build() raises a load
 * error if any expression does not compile, which is what makes this a
 * gate rather than a call.
 *
 * Recording is already off, so these sources stay out of the level-max
 * set while the generator still cannot be silently dropped.
 */
$corpus = 'tests/unit/Dialect/Fixtures/Inline/ExpressionForms.php';

try {
    $expression = (new InlineBuilder())->build($root . '/' . $corpus, $corpus);
} catch (\Throwable $refused) {
    // The failure this gate exists for. Reported like every other one
    // here rather than as an uncaught trace, because a gate nobody can
    // read the output of is half a gate.
    \fwrite(
        STDERR,
        'The expression corpus does not compose: ' . $refused->getMessage() . \PHP_EOL,
    );

    exit(2);
}

if ($expression === null || $expression->tests === []) {
    \fwrite(STDERR, 'The expression corpus composed nothing — its gate would be vacuous.' . \PHP_EOL);

    exit(2);
}

/*
 * Completeness, in both directions.
 *
 * ApprovedGeneratorRule says who MAY compile code at runtime; this file
 * says who actually gets checked. Until now those were two independent
 * hand-maintained lists, and "five generators, four in the corpus" is
 * precisely what a pair of lists nobody compares produces. Neither is
 * derived from the other -- one is a rule over the AST, the other is
 * driven code -- so the check is that they agree, in the shape
 * SkipReasonsTest::RECOGNISED already uses.
 */
$observed = [];

foreach ([...\array_keys($contribution), InlineBuilder::class] as $generator) {
    /** @var class-string $generator */
    $file = (new \ReflectionClass($generator))->getFileName();

    $observed[] = \str_replace($root . '/', '', (string) $file);
}

$declared = \array_filter(
    ApprovedGeneratorRule::approved(),
    static fn(string $path): bool => \str_starts_with($path, 'src/'),
    \ARRAY_FILTER_USE_KEY,
);

$drift = Drift::between($observed, $declared);

if (!$drift->clean()) {
    \fwrite(
        STDERR,
        "The corpus and ApprovedGeneratorRule disagree about who generates code:\n"
            . $drift->report('generators', 'drive it from a corpus here, or drop it from the rule')
            . "\nA generator the rule approves and no corpus drives is exactly the shape that let\n"
            . "five sites with four covered stand for months with every gate green.\n",
    );

    exit(2);
}

$written = \array_sum($contribution);

\printf('ANALYSING %d generated source file(s), by generator:%s', $written, \PHP_EOL);

foreach ($contribution as $generator => $shapes) {
    \printf('  %2d  %s%s', $shapes, $generator, \PHP_EOL);
}

\printf(
    'GATED BY PARSE, not by level max (the payload is the author\'s): %d expression(s)  %s%s',
    \count($expression->tests),
    InlineBuilder::class,
    \PHP_EOL,
);

\printf("%s", \PHP_EOL);

\passthru(
    \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($root . '/vendor/bin/phpstan')
        . ' analyse --configuration=' . \escapeshellarg($root . '/phpstan/generated.neon')
        . ' --memory-limit=1G --no-progress',
    $status,
);

exit($status);
