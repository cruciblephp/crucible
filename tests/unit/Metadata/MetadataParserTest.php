<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Metadata;

use LucianoPereira\Crucible\Attributes\Before;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Attributes\Depends;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\RequiresPhp;
use LucianoPereira\Crucible\Attributes\Small;
use LucianoPereira\Crucible\Attributes\Test;
use LucianoPereira\Crucible\Attributes\TestDox;
use LucianoPereira\Crucible\Attributes\TestWith;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Metadata\MetadataParser;

#[CoversClass(MetadataParser::class)]
#[CoversClass(MetadataCollection::class)]
final class MetadataParserTest extends TestCase
{
    public function testCollectsClassLevelAttributesIncludingInherited(): void
    {
        $metadata = (new MetadataParser())->forClass(FixtureChild::class);

        $groups = $metadata->ofType(Group::class);

        $this->assertCount(2, $groups);
        $this->assertSame('child', $groups[0]->name);
        $this->assertSame('parent', $groups[1]->name);
        $this->assertTrue($metadata->has(Small::class));
        $this->assertSame(MetadataParser::class, $metadata->first(CoversClass::class)?->className);
    }

    public function testCollectsMethodAttributesInDeclarationOrder(): void
    {
        $metadata = (new MetadataParser())->forMethod(FixtureChild::class, 'sums');

        $this->assertTrue($metadata->has(Test::class));
        $this->assertSame('provideSums', $metadata->first(DataProvider::class)?->methodName);

        $rows = $metadata->ofType(TestWith::class);
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2, 3], $rows[0]->data);
        $this->assertSame('named row', $rows[1]->name);

        $this->assertSame(5, $metadata->first(Before::class)?->priority);
        $this->assertSame('renders sums', $metadata->first(TestDox::class)?->text);
    }

    public function testMethodMetadataPrecedesClassMetadataInMergedView(): void
    {
        $metadata = (new MetadataParser())->forClassAndMethod(FixtureChild::class, 'sums');

        $groups = $metadata->ofType(Group::class);

        $this->assertSame(['fast', 'child', 'parent'], [$groups[0]->name, $groups[1]->name, $groups[2]->name]);
        $this->assertSame('8.5', $metadata->first(RequiresPhp::class)?->versionRequirement);
    }

    public function testForeignAttributesAreIgnored(): void
    {
        $metadata = (new MetadataParser())->forMethod(FixtureChild::class, 'decorated');

        $this->assertCount(1, $metadata);
        $this->assertInstanceOf(Depends::class, $metadata->attributes[0]);
        $this->assertSame('sums', $metadata->first(Depends::class)?->methodName);
    }

    public function testProgrammaticConstructionForDialectFrontends(): void
    {
        $group = new Group('pest');
        $test  = new Test();

        $metadata = MetadataCollection::from($group, $test);

        $this->assertCount(2, $metadata);
        $this->assertTrue($metadata->has(Test::class));
        $this->assertSame([$group, $test], [...$metadata]);
    }
}
