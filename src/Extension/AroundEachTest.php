<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Extension;

use LucianoPereira\Crucible\Test\TestId;

/**
 * The around-test role (D-172): an extension that brackets every test
 * in the process that runs it. This is the extension point a project
 * needs to wrap tests in a rolled-back database transaction — what
 * dama/doctrine-test-bundle does through PHPUnit event subscribers, and
 * cannot: Crucible's subscribers are reporting plugins that live in
 * the supervisor under `--parallel`, one process away from the
 * connection a transaction would run on.
 *
 * An implementation is dispatched wherever the test runs — the
 * in-process runner and every worker alike, because each loads
 * `crucible.php` and builds the same instances — and its {@see around()}
 * call brackets the WHOLE per-test lifecycle: setUp(), the `#[Before]`
 * hooks, the pre-conditions, the body, the post-conditions, the
 * `#[After]` hooks, tearDown(). The after part of a rollback therefore
 * belongs in a `finally`, which is the one thing a `#[Before]`/`#[After]`
 * trait could not give: `#[After]` is skipped when setUp() throws, so
 * the trait's cleanup fell to the next test (F-04, ticket #2 item 4).
 *
 *     final class Transactional implements AroundEachTest
 *     {
 *         public function around(TestId $id, callable $test): mixed
 *         {
 *             $this->connection->beginTransaction();
 *
 *             try {
 *                 return $test();
 *             } finally {
 *                 $this->connection->rollBack();
 *             }
 *         }
 *     }
 *
 * The rules, all deliberate:
 *
 * - Registration order is nesting order — first registered runs
 *   outermost, so `->extension()` call order reads as the wrap order.
 * - Call `$test()` exactly once; what it returns is the test's return
 *   value and feeds `#[Depends]` injection. An extension that returns
 *   something else injects that instead.
 * - A throw before `$test()` classifies the test from that throw, and a
 *   throw after its outcome does the same: the extension's exception
 *   becomes the test's, like a failing setUp() would.
 * - The bracket opens per attempt: a retried test re-enters `around()`,
 *   so each attempt gets a fresh transaction.
 * - A test that never executes — todo-blocked, requirement-gated,
 *   dependency-blocked, or a group whose `beforeAll` failed — is never
 *   passed here: there is nothing to wrap.
 * - What the hook prints is the test's output, and what it runs counts
 *   against the test's duration and coverage: the bracket lives inside
 *   the runner's per-test window.
 * - `--no-extensions` silences configured hooks for the whole run,
 *   workers included.
 */
interface AroundEachTest extends Extension
{
    /**
     * Wrap one test's execution. Call `$test()` to run it; return what
     * it returned.
     *
     * @param callable(): mixed $test
     */
    public function around(TestId $id, callable $test): mixed;
}
