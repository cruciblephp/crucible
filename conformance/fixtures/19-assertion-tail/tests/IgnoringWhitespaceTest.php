<?php

declare(strict_types=1);

namespace CrucibleConformance\AssertionTail;

use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * The *IgnoringWhitespace family. The cases that matter are the two
 * readings of "ignoring": runs of whitespace collapse to one space, but
 * whitespace is not removed outright — "a b" and "ab" stay unequal.
 */
final class IgnoringWhitespaceTest extends TestCase
{
    private function data(string $name): string
    {
        return dirname(__DIR__) . '/data/' . $name;
    }

    public function testStringEqualsStringCollapsesRuns(): void
    {
        $this->assertStringEqualsStringIgnoringWhitespace('a b', "a \t\n b");
    }

    public function testStringEqualsStringTrimsEnds(): void
    {
        $this->assertStringEqualsStringIgnoringWhitespace('a b', "  a b\n");
    }

    public function testStringEqualsStringDoesNotRemoveWhitespaceOutright(): void
    {
        $this->assertStringEqualsStringIgnoringWhitespace('a b', 'ab');
    }

    public function testStringEqualsStringFailsOnDifferentContent(): void
    {
        $this->assertStringEqualsStringIgnoringWhitespace('a b', 'a c');
    }

    public function testStringNotEqualsStringHolds(): void
    {
        $this->assertStringNotEqualsStringIgnoringWhitespace('a b', 'a c');
    }

    public function testStringNotEqualsStringFails(): void
    {
        $this->assertStringNotEqualsStringIgnoringWhitespace('a b', "a  b");
    }

    public function testStringEqualsFileHolds(): void
    {
        $this->assertStringEqualsFileIgnoringWhitespace($this->data('spaced.txt'), 'hello world');
    }

    public function testStringEqualsFileFails(): void
    {
        $this->assertStringEqualsFileIgnoringWhitespace($this->data('spaced.txt'), 'goodbye world');
    }

    public function testStringNotEqualsFileHolds(): void
    {
        $this->assertStringNotEqualsFileIgnoringWhitespace($this->data('spaced.txt'), 'goodbye world');
    }

    public function testStringNotEqualsFileFails(): void
    {
        $this->assertStringNotEqualsFileIgnoringWhitespace($this->data('spaced.txt'), 'hello world');
    }

    public function testFileEqualsFileHolds(): void
    {
        $this->assertFileEqualsFileIgnoringWhitespace($this->data('spaced.txt'), $this->data('tight.txt'));
    }

    public function testFileEqualsFileFails(): void
    {
        $this->assertFileEqualsFileIgnoringWhitespace($this->data('spaced.txt'), $this->data('other.txt'));
    }

    public function testFileNotEqualsFileHolds(): void
    {
        $this->assertFileNotEqualsFileIgnoringWhitespace($this->data('spaced.txt'), $this->data('other.txt'));
    }

    public function testFileNotEqualsFileFails(): void
    {
        $this->assertFileNotEqualsFileIgnoringWhitespace($this->data('spaced.txt'), $this->data('tight.txt'));
    }
}
