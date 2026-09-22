<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const INF;
use const NAN;

/**
 * The second Phase 2 tranche: file-content equality, JSON comparisons,
 * format strings, float specials, case/line-ending-insensitive
 * equality, object equality by protocol, readability.
 */
#[CoversClass(Assert::class)]
final class AssertTrancheTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testCaseAndLineEndingInsensitiveEquality(): void
    {
        Assert::assertEqualsIgnoringCase('Crucible', 'crucible');
        Assert::assertEqualsIgnoringCase(['a' => 'X'], ['a' => 'x']);
        Assert::assertNotEqualsIgnoringCase('crucible', 'basil');
        Assert::assertStringEqualsStringIgnoringLineEndings("a\nb", "a\r\nb");
        Assert::assertStringContainsStringIgnoringLineEndings("a\nb", "x a\r\nb y");

        $this->expectException(AssertionFailedError::class);
        Assert::assertStringEqualsStringIgnoringLineEndings("a\nb", "a b");
    }

    public function testFloatSpecials(): void
    {
        Assert::assertNan(NAN);
        Assert::assertInfinite(INF);
        Assert::assertInfinite(-INF);
        Assert::assertFinite(1.5);
        Assert::assertFinite(1);

        $this->expectException(AssertionFailedError::class);
        Assert::assertFinite(INF);
    }

    public function testObjectEqualsUsesTheDeclaredProtocol(): void
    {
        $money = new readonly class (5) {
            public function __construct(
                public int $amount,
            ) {}

            public function equals(self $other): bool
            {
                return $this->amount === $other->amount;
            }
        };

        Assert::assertObjectEquals(new ($money::class)(5), $money);

        try {
            Assert::assertObjectEquals(new ($money::class)(7), $money);
            $this->fail('Expected an assertion failure.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('according to', $e->getMessage());
        }
    }

    public function testStringMatchesFormat(): void
    {
        Assert::assertStringMatchesFormat('crucible %d by %s.', 'crucible 42 by Luciano.');
        Assert::assertStringMatchesFormat('%i items', '-3 items');
        Assert::assertStringMatchesFormat('hex %x end', 'hex deadBEEF end');
        Assert::assertStringMatchesFormat('%f s', '0.005 s');
        Assert::assertStringMatchesFormat('%f s', '1e-3 s');
        Assert::assertStringMatchesFormat('100%% done', '100% done');
        Assert::assertStringMatchesFormat("line %s\n%A", "line one\nanything\nat all");

        $this->expectException(AssertionFailedError::class);
        Assert::assertStringMatchesFormat('%d items', 'x items');
    }

    public function testFileContentEquality(): void
    {
        $a = $this->tempFile("Hello\n");
        $b = $this->tempFile("Hello\n");
        $c = $this->tempFile("HELLO\n");

        Assert::assertFileEquals($a, $b);
        Assert::assertFileNotEquals($a, $c);
        Assert::assertFileEqualsIgnoringCase($a, $c);
        Assert::assertStringEqualsFile($a, "Hello\n");
        Assert::assertStringNotEqualsFile($a, "Bye\n");
        Assert::assertStringEqualsFileIgnoringCase($c, "hello\n");

        $this->expectException(AssertionFailedError::class);
        Assert::assertFileEquals($a, $c);
    }

    public function testMissingFileIsAFailureNotAnError(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('exists and is readable');

        Assert::assertFileEquals('/nonexistent/a.txt', '/nonexistent/b.txt');
    }

    public function testJsonComparisons(): void
    {
        Assert::assertJsonStringEqualsJsonString('{"a":1,"b":[1,2]}', "{\n  \"b\": [1, 2], \"a\": 1\n}");
        Assert::assertJsonStringNotEqualsJsonString('{"a":1}', '{"a":2}');

        $expected = $this->tempFile('{"name":"crucible"}');
        Assert::assertJsonStringEqualsJsonFile($expected, '{ "name" : "crucible" }');
        Assert::assertJsonFileEqualsJsonFile($expected, $this->tempFile('{"name":"crucible"}'));

        $this->expectException(AssertionFailedError::class);
        Assert::assertJsonStringEqualsJsonString('{"a":1}', 'not json');
    }

    public function testReadabilityAndWritability(): void
    {
        $file = $this->tempFile('x');

        Assert::assertIsReadable($file);
        Assert::assertIsWritable($file);
        Assert::assertIsNotReadable($file . '.nope');
        Assert::assertFileIsReadable($file);
        Assert::assertFileIsWritable($file);
        Assert::assertDirectoryIsReadable(__DIR__);
        Assert::assertDirectoryIsWritable(sys_get_temp_dir());

        $this->expectException(AssertionFailedError::class);
        Assert::assertFileIsReadable($file . '.nope');
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crucible-test-');

        if ($path === false) {
            self::fail('Cannot create a temp file.');
        }

        $this->assertNotFalse(file_put_contents($path, $contents));
        $this->tempFiles[] = $path;

        return $path;
    }
}
