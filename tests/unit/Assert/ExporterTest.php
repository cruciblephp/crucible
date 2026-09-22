<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Framework\TestCase;
use stdClass;

#[CoversClass(Exporter::class)]
final class ExporterTest extends TestCase
{
    public function testScalars(): void
    {
        $this->assertSame('null', Exporter::export(null));
        $this->assertSame('true', Exporter::export(true));
        $this->assertSame('false', Exporter::export(false));
        $this->assertSame('3', Exporter::export(3));
        $this->assertSame('3.0', Exporter::export(3.0));
        $this->assertSame("'hi'", Exporter::export('hi'));
    }

    public function testArraysAreMultilineAndNested(): void
    {
        $this->assertSame('[]', Exporter::export([]));
        $this->assertSame(
            "[\n    0 => 1,\n    'k' => [\n        0 => 2,\n    ],\n]",
            Exporter::export([1, 'k' => [2]]),
        );
    }

    public function testObjectsExportClassAndProperties(): void
    {
        $object    = new stdClass();
        $object->a = 1;

        $this->assertSame("stdClass {\n    a: 1,\n}", Exporter::export($object));
    }

    public function testObjectRecursionIsGuarded(): void
    {
        $object       = new stdClass();
        $object->self = $object;

        $this->assertStringContainsString('*RECURSION*', Exporter::export($object));
    }

    public function testEnumsExportAsClassAndCase(): void
    {
        $this->assertSame(
            'LucianoPereira\Crucible\Configuration\ExecutionOrder::Random',
            Exporter::export(ExecutionOrder::Random),
        );
    }

    public function testDescribeIsShortForCompoundValues(): void
    {
        $this->assertSame('an array', Exporter::describe([1, 2]));
        $this->assertSame('an instance of stdClass', Exporter::describe(new stdClass()));
        $this->assertSame("'x'", Exporter::describe('x'));
    }
}
