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
use LucianoPereira\Crucible\Compat\Migration\CompositeEqualsPattern;
use LucianoPereira\Crucible\Compat\Migration\KnownCouplingPatterns;
use LucianoPereira\Crucible\Compat\Migration\SourceRewriter;
use LucianoPereira\Crucible\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Exercised through `SourceRewriter::apply()`/`preview()` — its real
 * entry points — rather than calling `CompositeEqualsPattern::locate()`
 * directly, so wiring and detection are verified together. The
 * positive fixture is the real Monolog 3.9.0
 * `IntrospectionProcessorTest::testLevelEqual` shape verbatim; the
 * negative fixtures are real adjacent shapes from the same file
 * (`testLevelTooLow` has no backing array assignment at all) or
 * deliberately-scoped-out variants (a 3rd argument, a non-variable
 * argument, every key coupled).
 */
#[CoversClass(CompositeEqualsPattern::class)]
final class CompositeEqualsPatternTest extends TestCase
{
    public function testRealIntrospectionProcessorShapeDropsCoupledKeysKeepsTheRest(): void
    {
        $source = <<<'PHP_WRAP'
        <?php
        class T {
            public function testLevelEqual()
            {
                $input = $this->getRecord(Level::Critical);
        
                $expected = clone $input;
                $expected['extra'] = [
                    'file' => null,
                    'line' => null,
                    'class' => 'PHPUnit\Framework\TestCase',
                    'function' => 'runTest',
                    'callType' => '->',
                ];
        
                $processor = new IntrospectionProcessor(Level::Critical);
                $actual = $processor($input);
        
                $this->assertEquals($expected, $actual);
            }
        }
        PHP_WRAP;

        $result = SourceRewriter::apply($source, 1, 20, KnownCouplingPatterns::all());

        $this->assertCount(1, $result['matches']);

        // The original $expected assignment stays -- harmless dead
        // code, never deleted (deletion risks a dangling reference if
        // the variable is used elsewhere; this pattern only ever
        // replaces the comparison call's own text span).
        $this->assertStringContainsString("\$expected['extra'] = [", $result['source']);
        $this->assertStringContainsString('PHPUnit\Framework\TestCase', $result['source']);

        // The 3 non-coupled keys survive as direct equality checks...
        $this->assertStringContainsString("assertSame(null, \$actual['extra']['file'])", $result['source']);
        $this->assertStringContainsString("assertSame(null, \$actual['extra']['line'])", $result['source']);
        $this->assertStringContainsString("assertSame('->', \$actual['extra']['callType'])", $result['source']);

        // ...the 2 coupled keys are simply omitted from the GENERATED
        // assertions, not guessed at (the untouched dead-code array
        // literal above still mentions 'class'/'function' verbatim --
        // that's expected, only the new statements matter here).
        $this->assertStringNotContainsString("\$actual['extra']['class']", $result['source']);
        $this->assertStringNotContainsString("\$actual['extra']['function']", $result['source']);

        // The original assertEquals($expected, $actual) call itself
        // is gone.
        $this->assertStringNotContainsString('assertEquals($expected, $actual)', $result['source']);

        $this->assertValidPhp($result['source']);
    }

    public function testNoBackingArrayAssignmentIsNotAutoFixed(): void
    {
        // The real, adjacent IntrospectionProcessorTest::testLevelTooLow
        // shape: $expected is a bare clone with nothing built onto it
        // afterward -- nothing to safely rewrite from.
        $source = <<<'PHP'
            <?php
            class T {
                public function testLevelTooLow()
                {
                    $input = $this->getRecord(Level::Debug);

                    $expected = clone $input;

                    $processor = new IntrospectionProcessor(Level::Critical);
                    $actual = $processor($input);

                    $this->assertEquals($expected, $actual);
                }
            }
            PHP;

        $result = SourceRewriter::apply($source, 1, 12, KnownCouplingPatterns::all());

        $this->assertSame([], $result['matches']);
        $this->assertSame($source, $result['source']);
    }

    public function testNonVariableArgumentIsNotAutoFixed(): void
    {
        // The real, adjacent IntrospectionProcessorTest::testProcessorFromClass
        // shape: a property/array access, not a bare variable.
        $source = <<<'PHP'
            <?php
            class T {
                public function testIt()
                {
                    $this->assertEquals('Acme\Tester', $record->extra['class']);
                }
            }
            PHP;

        $result = SourceRewriter::apply($source, 1, 7, KnownCouplingPatterns::all());

        $this->assertSame([], $result['matches']);
        $this->assertSame($source, $result['source']);
    }

    public function testAThirdArgumentIsNotAutoFixed(): void
    {
        $source = <<<'PHP_WRAP'
        <?php
        class T {
            public function testIt()
            {
                $expected['extra'] = ['class' => 'PHPUnit\Framework\TestCase'];
                $this->assertEquals($expected, $actual, 'a message');
            }
        }
        PHP_WRAP;

        $result = SourceRewriter::apply($source, 1, 7, KnownCouplingPatterns::all());

        $this->assertSame([], $result['matches']);
        $this->assertSame($source, $result['source']);
    }

    public function testEveryKeyCoupledIsNotAutoFixed(): void
    {
        $source = <<<'PHP_WRAP'
        <?php
        class T {
            public function testIt()
            {
                $expected['extra'] = [
                    'class' => 'PHPUnit\Framework\TestCase',
                    'function' => 'runTest',
                ];
        
                $this->assertEquals($expected, $actual);
            }
        }
        PHP_WRAP;

        $result = SourceRewriter::apply($source, 1, 10, KnownCouplingPatterns::all());

        $this->assertSame([], $result['matches']);
        $this->assertSame($source, $result['source']);
    }

    public function testLongArraySyntaxIsNotAutoFixed(): void
    {
        // array(...) long syntax is deliberately out of scope -- only
        // the short [ ... ] literal this pattern was verified against.
        $source = <<<'PHP_WRAP'
        <?php
        class T {
            public function testIt()
            {
                $expected['extra'] = array(
                    'class' => 'PHPUnit\Framework\TestCase',
                    'callType' => '->',
                );
        
                $this->assertEquals($expected, $actual);
            }
        }
        PHP_WRAP;

        $result = SourceRewriter::apply($source, 1, 10, KnownCouplingPatterns::all());

        $this->assertSame([], $result['matches']);
        $this->assertSame($source, $result['source']);
    }

    public function testStaticCallPrefixIsPreservedOnEveryGeneratedStatement(): void
    {
        $source = <<<'PHP_WRAP'
        <?php
        class T {
            public function testIt()
            {
                $expected['extra'] = [
                    'callType' => '->',
                    'class' => 'PHPUnit\Framework\TestCase',
                    'line' => 5,
                ];
        
                self::assertEquals($expected, $actual);
            }
        }
        PHP_WRAP;

        $result = SourceRewriter::apply($source, 1, 11, KnownCouplingPatterns::all());

        $this->assertCount(1, $result['matches']);
        $this->assertStringContainsString("self::assertSame('->', \$actual['extra']['callType'])", $result['source']);
        $this->assertStringContainsString("self::assertSame(5, \$actual['extra']['line'])", $result['source']);
        $this->assertValidPhp($result['source']);
    }

    public function testPreviewDoesNotTouchTheSource(): void
    {
        $source = <<<'PHP_WRAP'
        <?php
        class T {
            public function testIt()
            {
                $expected['extra'] = [
                    'class' => 'PHPUnit\Framework\TestCase',
                    'callType' => '->',
                ];
        
                $this->assertEquals($expected, $actual);
            }
        }
        PHP_WRAP;

        $matches = SourceRewriter::preview($source, 1, 10, KnownCouplingPatterns::all());

        $this->assertCount(1, $matches);
        $this->assertStringContainsString('assertEquals($expected, $actual)', $source);
    }

    private function assertValidPhp(string $source): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'crucible-composite-equals-test-');
        file_put_contents($tmp, $source);

        $output = [];
        $exit   = null;
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
        unlink($tmp);

        $this->assertSame(0, $exit, implode("\n", $output));
    }
}
