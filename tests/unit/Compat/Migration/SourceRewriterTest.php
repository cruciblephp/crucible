<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Compat\Migration;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\Migration\KnownCouplingPatterns;
use LucianoPereira\Crucible\Compat\Migration\SourceRewriter;
use LucianoPereira\Crucible\Framework\TestCase;

use function preg_match;

/**
 * Exercises SourceRewriter against the exact shapes found in the real
 * Monolog 3.9.0 compat audit this feature was built from (see
 * DESIGN.md-adjacent session notes) — not fabricated fixtures.
 */
#[CoversClass(SourceRewriter::class)]
final class SourceRewriterTest extends TestCase
{
    public function testLocateMethodFindsTheDeclarationToClosingBraceSpan(): void
    {
        $source = <<<'PHP'
            <?php
            class T {
                public function other(): void
                {
                    $this->assertTrue(true);
                }

                public function testIt(): void
                {
                    $this->assertTrue(true);
                }
            }
            PHP;

        $span = SourceRewriter::locateMethod($source, 'testIt');

        $this->assertNotNull($span);
        $this->assertSame(8, $span[0]);
        $this->assertSame(11, $span[1]);
    }

    public function testLocateMethodIgnoresACallToTheSameNameElsewhere(): void
    {
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    $this->testIt();
                    $this->assertTrue(true);
                }
            }
            PHP;

        $span = SourceRewriter::locateMethod($source, 'testIt');

        $this->assertNotNull($span);
        // The declaration, not the call on line 5.
        $this->assertSame(3, $span[0]);
    }

    public function testLocateMethodReturnsNullForAnAbstractMethod(): void
    {
        $source = <<<'PHP'
            <?php
            abstract class T {
                abstract public function testIt(): void;
            }
            PHP;

        $this->assertNull(SourceRewriter::locateMethod($source, 'testIt'));
    }

    public function testLocateMethodReturnsNullWhenNotFound(): void
    {
        $source = <<<'PHP'
            <?php
            class T {
                public function testOther(): void {}
            }
            PHP;

        $this->assertNull(SourceRewriter::locateMethod($source, 'testIt'));
    }

    public function testVendorPathAssertionOnAnArrayIndexedHigherFrameWeakensToAStringCheck(): void
    {
        // The real NormalizerFormatterTest::testFormatExceptionWithBasePath
        // regression this session found the hard way: an earlier version
        // of this pattern rewrote trace[1] the same way as trace[0], but
        // real PHPUnit's frame 1 is ALSO TestCase.php (two consecutive
        // TestCase.php frames, an internal PHPUnit quirk) while Crucible's
        // frame 1 is a different file (TestBuilder.php) entirely -- the
        // "some TestCase.php exists" rewrite is only true for frame 0.
        // Frame 1+ weakens further: no claim about the file at all.
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    self::assertStringStartsWith('vendor/phpunit/phpunit/src/Framework/TestCase.php:', $formatted['exception']['trace'][1]);
                }
            }
            PHP;

        $result = SourceRewriter::apply($source, 1, 7, KnownCouplingPatterns::all());

        $this->assertCount(1, $result['matches']);
        $this->assertStringContainsString("assertIsString(\$formatted['exception']['trace'][1])", $result['source']);
        $this->assertStringNotContainsString('vendor/phpunit/phpunit', $result['source']);
    }

    public function testVendorPathAssertionWithAFrameNumberPrefixOnAHigherFrameKeepsOnlyTheFrameMarker(): void
    {
        // The real LineFormatterTest::testBasePath shape: the frame
        // index is encoded in the literal itself ("#1 "), not in the
        // asserted-on expression's array index. The "#1 " marker
        // itself IS verifiably real in both engines, so it's kept;
        // the file path after it is not, so it's dropped.
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    $this->assertStringContainsString('    #1 vendor/phpunit/phpunit/', $message);
                }
            }
            PHP;

        $result = SourceRewriter::apply($source, 1, 7, KnownCouplingPatterns::all());

        $this->assertCount(1, $result['matches']);
        // preg_quote() also escapes "#", so the generated regex source
        // literally contains "\#1", not "#1".
        $this->assertStringContainsString('assertMatchesRegularExpression(\'/    \#1 \S+/\', $message)', $result['source']);
        $this->assertStringNotContainsString('vendor/phpunit/phpunit', $result['source']);
    }

    public function testVendorPathAssertionBecomesAWildcardRegex(): void
    {
        // The real NormalizerFormatterTest::testFormatExceptionWithBasePath shape.
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    self::assertStringStartsWith('vendor/phpunit/phpunit/src/Framework/TestCase.php:', $formatted['exception']['trace'][0]);
                }
            }
            PHP;

        $patterns = KnownCouplingPatterns::all();
        $result   = SourceRewriter::apply($source, 1, 7, $patterns);

        $this->assertCount(1, $result['matches']);
        $this->assertStringContainsString('assertMatchesRegularExpression', $result['source']);
        $this->assertStringNotContainsString('vendor/phpunit/phpunit', $result['source']);
        $this->assertStringContainsString("\$formatted['exception']['trace'][0]", $result['source']);

        // The regex must still match Crucible's own real, non-vendor-path output.
        $this->assertMatchesRegularExpression(
            '/' . $this->extractPattern($result['source']) . '/',
            '/home/lucho/Downloads/crucible/src/Framework/TestCase.php:92',
        );
    }

    public function testNoEquivalentFilenameBecomesAStructuralFrameCheck(): void
    {
        // The real LineFormatterTest::testDefFormatWithExceptionAndStacktraceParserFull shape.
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    $this->assertStringContainsString('TestSuite.php', $trace);
                    $this->assertStringContainsString('TestRunner.php', $trace);
                }
            }
            PHP;

        $patterns = KnownCouplingPatterns::all();
        $result   = SourceRewriter::apply($source, 1, 7, $patterns);

        // Only the TestSuite.php call is a known pattern (TestRunner.php
        // coincidentally exists in Crucible too, so it is not in the
        // denylist and must be left untouched).
        $this->assertCount(1, $result['matches']);
        $this->assertStringContainsString("assertStringContainsString('TestRunner.php', \$trace)", $result['source']);
        $this->assertStringNotContainsString('TestSuite.php', $result['source']);
        $this->assertStringContainsString("assertMatchesRegularExpression('/#\\d+ /', \$trace)", $result['source']);
    }

    public function testUnrelatedAssertionIsAByteExactNoOp(): void
    {
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    $this->assertStringContainsString('hello world', $x);
                }
            }
            PHP;

        $result = SourceRewriter::apply($source, 1, 7, KnownCouplingPatterns::all());

        $this->assertSame([], $result['matches']);
        $this->assertSame($source, $result['source']);
    }

    public function testOutOfRangeLineIsIgnored(): void
    {
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    $this->assertStringContainsString('TestSuite.php', $trace);
                }
            }
            PHP;

        // The known-bad call sits on line 5; a range that excludes it
        // must leave the source untouched.
        $result = SourceRewriter::apply($source, 1, 4, KnownCouplingPatterns::all());

        $this->assertSame([], $result['matches']);
        $this->assertSame($source, $result['source']);
    }

    public function testPreviewDoesNotTouchTheSource(): void
    {
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt(): void
                {
                    $this->assertStringContainsString('TestSuite.php', $trace);
                }
            }
            PHP;

        $matches = SourceRewriter::preview($source, 1, 7, KnownCouplingPatterns::all());

        $this->assertCount(1, $matches);
        $this->assertStringContainsString('TestSuite.php', $source);
    }

    private function extractPattern(string $rewrittenSource): string
    {
        preg_match("/assertMatchesRegularExpression\('\/(.*?)\/',/", $rewrittenSource, $match);

        return $match[1] ?? self::fail('Could not extract the generated regex from: ' . $rewrittenSource);
    }
}
