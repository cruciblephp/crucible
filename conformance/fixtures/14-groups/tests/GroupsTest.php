<?php

declare(strict_types=1);

namespace CrucibleConformance\Groups;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/*
 * Run with --group=wanted --exclude-group=unwanted on both runners
 * (options.json). Deselected tests FAIL if executed, so wrong group
 * semantics cannot conform. testInBothGroups pins exclusion-wins.
 */
final class GroupsTest extends TestCase
{
    #[Group('wanted')]
    public function testInWantedGroup(): void
    {
        $this->assertTrue(true);
    }

    #[Group('wanted')]
    #[Group('unwanted')]
    public function testInBothGroups(): void
    {
        $this->fail('Exclusion must win over inclusion.');
    }

    #[Group('unwanted')]
    public function testInUnwantedGroup(): void
    {
        $this->fail('An excluded test must never run.');
    }

    public function testUngrouped(): void
    {
        $this->fail('With --group given, an ungrouped test must never run.');
    }
}
