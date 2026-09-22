<?php

declare(strict_types=1);

namespace CrucibleConformance\AssertionTail;

use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * The XML family. Canonical form decides equality, so the interesting
 * cases are the ones that separate "formatting" from "content": text
 * whitespace counts, whitespace between elements does not, and a
 * document that will not parse is an error rather than a failure.
 */
final class XmlTest extends TestCase
{
    private function data(string $name): string
    {
        return dirname(__DIR__) . '/data/' . $name;
    }

    public function testStringEqualsStringIgnoresLayout(): void
    {
        $this->assertXmlStringEqualsXmlString('<a><b/></a>', "<a>\n  <b/>\n</a>");
    }

    public function testStringEqualsStringIgnoresAttributeOrder(): void
    {
        $this->assertXmlStringEqualsXmlString('<a x="1" y="2"/>', '<a y="2" x="1"/>');
    }

    public function testStringEqualsStringIgnoresComments(): void
    {
        $this->assertXmlStringEqualsXmlString('<a><b/></a>', '<a><!-- hi --><b/></a>');
    }

    public function testStringEqualsStringMindsTextWhitespace(): void
    {
        $this->assertXmlStringEqualsXmlString('<a>text</a>', '<a>  text  </a>');
    }

    public function testStringEqualsStringFailsOnDifferentContent(): void
    {
        $this->assertXmlStringEqualsXmlString('<a><b/></a>', '<a><c/></a>');
    }

    public function testStringEqualsStringConsideringCommentsFails(): void
    {
        $this->assertXmlStringEqualsXmlStringConsideringComments('<a><b/></a>', '<a><!-- hi --><b/></a>');
    }

    public function testStringEqualsStringConsideringCommentsHolds(): void
    {
        $this->assertXmlStringEqualsXmlStringConsideringComments('<a><!-- hi --><b/></a>', '<a><!-- hi --><b/></a>');
    }

    public function testStringNotEqualsStringHolds(): void
    {
        $this->assertXmlStringNotEqualsXmlString('<a><b/></a>', '<a><c/></a>');
    }

    public function testStringNotEqualsStringFails(): void
    {
        $this->assertXmlStringNotEqualsXmlString('<a><b/></a>', '<a><b/></a>');
    }

    public function testStringNotEqualsStringConsideringCommentsHolds(): void
    {
        $this->assertXmlStringNotEqualsXmlStringConsideringComments('<a><b/></a>', '<a><!-- hi --><b/></a>');
    }

    public function testStringEqualsFileHolds(): void
    {
        $this->assertXmlStringEqualsXmlFile($this->data('doc.xml'), "<a>\n <b/>\n</a>");
    }

    public function testStringEqualsFileFails(): void
    {
        $this->assertXmlStringEqualsXmlFile($this->data('doc.xml'), '<a><c/></a>');
    }

    public function testStringNotEqualsFileHolds(): void
    {
        $this->assertXmlStringNotEqualsXmlFile($this->data('doc.xml'), '<a><c/></a>');
    }

    public function testStringEqualsFileConsideringCommentsFails(): void
    {
        $this->assertXmlStringEqualsXmlFileConsideringComments($this->data('doc.xml'), '<a><!-- note --><b/></a>');
    }

    public function testStringNotEqualsFileConsideringCommentsHolds(): void
    {
        $this->assertXmlStringNotEqualsXmlFileConsideringComments($this->data('doc.xml'), '<a><!-- note --><b/></a>');
    }

    public function testFileEqualsFileHolds(): void
    {
        $this->assertXmlFileEqualsXmlFile($this->data('doc.xml'), $this->data('doc-spaced.xml'));
    }

    public function testFileEqualsFileFails(): void
    {
        $this->assertXmlFileEqualsXmlFile($this->data('doc.xml'), $this->data('other.xml'));
    }

    public function testFileNotEqualsFileHolds(): void
    {
        $this->assertXmlFileNotEqualsXmlFile($this->data('doc.xml'), $this->data('other.xml'));
    }

    public function testFileEqualsFileConsideringCommentsFails(): void
    {
        $this->assertXmlFileEqualsXmlFileConsideringComments($this->data('doc.xml'), $this->data('doc-commented.xml'));
    }

    public function testFileNotEqualsFileConsideringCommentsHolds(): void
    {
        $this->assertXmlFileNotEqualsXmlFileConsideringComments($this->data('doc.xml'), $this->data('doc-commented.xml'));
    }

    public function testMalformedActualIsAnError(): void
    {
        $this->assertXmlStringEqualsXmlString('<a/>', '<a>');
    }

    public function testMalformedExpectedIsAnError(): void
    {
        $this->assertXmlStringEqualsXmlString('<a', '<a/>');
    }
}
