<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * The snapshot surface (D-042) from the crucible dialect: the same
 * engine assertion the phpunit dialect reaches, one matcher here.
 */

\test('a receipt keeps its shape', function (): void {
    \expect([
        'items' => ['espresso' => 2, 'cornetto' => 1],
        'total' => '7.40',
        'paid'  => true,
    ])->toMatchSnapshot();
});

/*
 * The inline flavor (D-076), which nothing had ever executed: the
 * parity grid excludes it as needing a live test context, and its only
 * other appearances in the suite are inside heredoc SOURCE that the
 * rewriter parses as text. A mismatching case is deliberately not
 * asserted here — `--update-snapshots` would rewrite it into agreement
 * and the test would pass having asked nothing.
 */
\test('an inline snapshot compares against the value recorded in the call', function (): void {
    \expect('v')->toMatchInlineSnapshot("'v'");
    \expect(42)->toMatchInlineSnapshot('42');
});

\test('negating an inline snapshot is refused', function (): void {
    \expect(fn() => \expect('v')->not->toMatchInlineSnapshot("'v'"))
        ->toThrow(\LucianoPereira\Crucible\Assert\AssertionFailedError::class, 'has no meaning');
});

\test('several snapshots number themselves', function (): void {
    \expect('first')->toMatchSnapshot();
    \expect('second')->toMatchSnapshot();
    \expect(['named' => true])->toMatchSnapshot('the named one');
});
