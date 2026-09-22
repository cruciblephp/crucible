<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Fixtures\Inline;

/**
 * The expression forms the doctest wrapper's parentheses have to be
 * neutral over. Every real doctest in the tree is one `expect()->toBe()`
 * shape, so without this file the neutrality claim would rest on
 * reasoning rather than on running them.
 *
 * @phpcpd-keep Inline-dialect fixture: discovered by scan, never referenced.
 *
 * @crucible expect(match (true) { default => 7 })->toBe(7)
 * @crucible expect((fn(): int => 8)())->toBe(8)
 * @crucible expect([1, 2][1])->toBe(2)
 * @crucible expect(null ?? 'fallback')->toBe('fallback')
 * @crucible expect((new \ArrayObject([1, 2]))->count())->toBe(2)
 * @crucible expect(PHP_INT_MAX)->toBe(9223372036854775807)
 */
final class ExpressionForms {}
