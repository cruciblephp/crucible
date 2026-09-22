<?php

declare(strict_types=1);

namespace CrucibleConformance\Issues;

use PHPUnit\Framework\TestCase;

use function trigger_error;

use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;

/*
 * Issue accounting probe: deprecations, notices, and warnings leave
 * every outcome green but must land in the summary tallies with the
 * same counts on both runners — including the repeated deprecation,
 * which pins distinct-vs-occurrence counting semantics.
 */
final class IssuesTest extends TestCase
{
    public function testTriggersOneDeprecation(): void
    {
        trigger_error('the old way is deprecated', E_USER_DEPRECATED);

        $this->assertTrue(true);
    }

    public function testTriggersTheSameDeprecationTwice(): void
    {
        trigger_error('twice-triggered deprecation', E_USER_DEPRECATED);
        trigger_error('twice-triggered deprecation', E_USER_DEPRECATED);

        $this->assertTrue(true);
    }

    public function testTriggersANotice(): void
    {
        trigger_error('a user notice', E_USER_NOTICE);

        $this->assertTrue(true);
    }

    public function testTriggersAWarning(): void
    {
        trigger_error('a user warning', E_USER_WARNING);

        $this->assertTrue(true);
    }

    public function testStaysClean(): void
    {
        $this->assertTrue(true);
    }
}
