<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\Shard;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function sprintf;

#[CoversClass(Shard::class)]
final class ShardTest extends TestCase
{
    /**
     * @return list<TestGroup>
     */
    private function suite(int $tests): array
    {
        $definitions = [];

        for ($i = 0; $i < $tests; $i++) {
            $definitions[] = new TestDefinition(
                new TestId('tests/BigTest.php', sprintf('testNumber%d', $i)),
                static fn(array $values): mixed => null,
                MetadataCollection::from(),
            );
        }

        return [new TestGroup('Big', $definitions)];
    }

    public function testShardsPartitionTheSuiteCompletelyAndDisjointly(): void
    {
        $suite = $this->suite(50);
        $seen  = [];

        for ($index = 1; $index <= 4; $index++) {
            $shard = Shard::fromString($index . '/4');

            self::assertInstanceOf(Shard::class, $shard, 'A valid shard string failed to parse.');

            foreach ($shard->apply($suite) as $group) {
                foreach ($group->tests as $test) {
                    // Disjoint: no test appears in two shards.
                    $this->assertArrayNotHasKey($test->id->toString(), $seen);

                    $seen[$test->id->toString()] = true;
                }
            }
        }

        // Complete: every test landed in exactly one shard.
        $this->assertCount(50, $seen);
    }

    public function testMembershipIsStableWhenOtherTestsComeAndGo(): void
    {
        $shard = Shard::fromString('2/3');

        self::assertInstanceOf(Shard::class, $shard, 'A valid shard string failed to parse.');

        $small = $this->suite(20);
        $large = $this->suite(40); // the first 20 ids are identical

        $inSmall = $this->membership($shard->apply($small));
        $inLarge = $this->membership($shard->apply($large));

        // Every original test kept exactly its shard when 20 new
        // tests appeared — the stable-hash guarantee.
        foreach ($this->suite(20)[0]->tests as $test) {
            $id = $test->id->toString();

            $this->assertSame(isset($inSmall[$id]), isset($inLarge[$id]));
        }
    }

    /**
     * @param list<TestGroup> $groups
     *
     * @return array<string, true>
     */
    private function membership(array $groups): array
    {
        $ids = [];

        foreach ($groups as $group) {
            foreach ($group->tests as $test) {
                $ids[$test->id->toString()] = true;
            }
        }

        return $ids;
    }

    public function testMalformedShardStringsParseToNull(): void
    {
        $this->assertNull(Shard::fromString('0/4'));
        $this->assertNull(Shard::fromString('5/4'));
        $this->assertNull(Shard::fromString('1of4'));
        $this->assertNull(Shard::fromString('-1/4'));
        $this->assertNotNull(Shard::fromString('1/1'));
    }
}
