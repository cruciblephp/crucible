<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Attributes\TodoStatus;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Test\TestGroup;

use function basename;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Top-level `todo()` — the planned-but-unwritten test, carried as the
 * same engine-visible metadata `->todo()` produces (D-045), so --todos
 * and the runner's pre-run block see it without knowing the spelling.
 */
#[CoversClass(PestBuilder::class)]
final class PestTodoGlobalTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
    }

    public function testATopLevelTodoCarriesTheSameMetadataAsTheChainedForm(): void
    {
        $group = $this->build(<<<'PHP'
            <?php
            \todo('ship the console rework');
            \test('ship it, chained')->todo();
            PHP);

        self::assertCount(2, $group->tests);

        foreach ($group->tests as $test) {
            $todo = $test->metadata->first(Todo::class);

            self::assertInstanceOf(Todo::class, $todo);
            self::assertSame(TodoStatus::Todo, $todo->status);
        }
    }

    public function testATopLevelTodoCarriesItsAssigneeIssueAndNote(): void
    {
        $group = $this->build(<<<'PHP'
            <?php
            \todo('wire the spinner', 'lucho', 42, 'after the console package lands');
            PHP);

        $todo = $group->tests[0]->metadata->first(Todo::class);

        self::assertInstanceOf(Todo::class, $todo);
        self::assertSame('lucho', $todo->assignee);
        self::assertSame(42, $todo->issue);
        self::assertSame('after the console package lands', $todo->note);
    }

    private function build(string $source): TestGroup
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-pest-todo-');

        if ($file === false) {
            self::fail('Cannot create a temp file.');
        }

        file_put_contents($file, $source);
        $this->cleanup[] = $file;

        $relative = basename($file);

        if ($relative === '') {
            self::fail('tempnam() produced a path with no basename.');
        }

        return (new PestBuilder())->build($file, $relative);
    }
}
