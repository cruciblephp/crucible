<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The zero-argument matchers deliberately NOT swept, each with its
 * reason — as data rather than as prose in matchers.php's docblock.
 *
 * The sweep's verdict says "N cells over 44 matchers", which is a true
 * number answering the wrong question until it also says 44 of what.
 * Naming the exclusions here is what lets compare.php state that
 * denominator and refuse a zero-argument matcher that is neither swept
 * nor excluded — so the next matcher added cannot silently shrink the
 * sweep's share while the verdict keeps reading OK.
 *
 * A matcher that takes arguments is not listed: it is excluded by the
 * harness having no axis for it, which is a gap (see the argument-axis
 * item), not a decision.
 *
 * @return array<non-empty-string, non-empty-string> matcher => reason
 */

return [
    // Namespace-targeted in BOTH engines now (D1, 2026-09-01): a
    // subject resolves to the symbol it names plus everything beneath
    // it in the configured source, and a target matching nothing passes
    // — as the incumbent does — while warning.
    //
    // The reason for excluding them CHANGED with that. It used to be
    // "Crucible answers one class-string at a time by decision (D-066),
    // so every row would diverge"; the divergence is gone, and what is
    // left is that a per-VALUE grid asks these the wrong question. The
    // corpus holds values; these matchers take a namespace and answer
    // about a resolved SET. Sweeping them here would record verdicts
    // about the string 'abc' being a class, which is not what either
    // engine is being asked.
    //
    // The plural names are aliases of the singular ones in both engines
    // — in Pest literally `return $this->toBeClass();` — so they are
    // listed for the denominator's sake, not as separate behaviour.
    //
    // Reason 'arch' ENROLS: arch.php derives compare-arch.php's sweep
    // from these entries, so a name here is swept, not excused.
    'toBeAbstract'          => 'arch',
    'toBeClasses'           => 'arch',
    'toBeEnums'             => 'arch',
    'toBeIntBackedEnums'    => 'arch',
    'toBeInterfaces'        => 'arch',
    'toBeStringBackedEnums' => 'arch',
    'toBeTraits'            => 'arch',
    // The dependency, documentation and casing matchers, reachable in
    // the pest spelling since D1. Same reason: they resolve a namespace
    // to a SET and answer about it, so a per-value grid asks them the
    // wrong question.
    'toBeCasedCorrectly'         => 'arch',
    'toBeUsedInNothing'          => 'arch',
    'toExtendNothing'            => 'arch',
    'toHaveMethodsDocumented'    => 'arch',
    'toHavePropertiesDocumented' => 'arch',
    'toImplementNothing'         => 'arch',
    'toUseNothing'               => 'arch',
    'toUseStrictEquality'        => 'arch',
    'toUseStrictTypes'           => 'arch',
    'toBeClass'                  => 'arch',
    'toBeEnum'                   => 'arch',
    'toBeFinal'                  => 'arch',
    'toBeIntBackedEnum'          => 'arch',
    'toBeInterface'              => 'arch',
    'toBeInvokable'              => 'arch',
    'toBeReadonly'               => 'arch',
    'toBeStringBackedEnum'       => 'arch',
    'toBeTrait'                  => 'arch',
    'toHaveConstructor'          => 'arch',
    'toHaveDestructor'           => 'arch',

    // Answer about paths on the running machine, which differ between
    // the two checkouts by construction.
    'toBeDirectory'         => 'filesystem',
    'toBeFile'              => 'filesystem',
    'toBeReadableDirectory' => 'filesystem',
    'toBeReadableFile'      => 'filesystem',
    'toBeWritableDirectory' => 'filesystem',
    'toBeWritableFile'      => 'filesystem',

    // Need a live test context and write files; a sweep would record
    // where it ran, not what it does.
    'toMatchInlineSnapshot' => 'snapshot',
    'toMatchSnapshot'       => 'snapshot',

    // Variadic: called with no argument they are a usage error, not a
    // verdict.
    'toContain'      => 'variadic',
    'toContainEqual' => 'variadic',
];
