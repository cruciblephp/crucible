<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use DOMDocument;
use LucianoPereira\Crucible\Assert\ComparisonFailure;
use LucianoPereira\Crucible\Assert\Differ;
use LucianoPereira\Crucible\Assert\XmlException;
use Override;

use function is_string;
use function libxml_clear_errors;
use function libxml_get_last_error;
use function libxml_use_internal_errors;
use function trim;

/**
 * XML equality by canonical form (C14N), which is what makes two
 * documents "the same" here rather than two strings matching.
 *
 * Observed against the incumbent, and the boundary is narrower than
 * "ignore formatting": whitespace *between elements* is insignificant,
 * whitespace *inside a text node* is not — `<a>text</a>` and
 * `<a>  text  </a>` are different documents. Attribute order, the XML
 * declaration, and `<b/>` versus `<b></b>` all wash out.
 *
 * Comments are dropped unless the ConsideringComments spelling asks for
 * them, which is exactly C14N's own withComments flag.
 */
final class XmlEqualsXml extends Constraint
{
    public function __construct(
        private readonly string $expected,
        private readonly bool $withComments = false,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other)
            && self::canonical($other, $this->withComments) === self::canonical($this->expected, $this->withComments);
    }

    public function toString(): string
    {
        return 'is equal XML to the expected document'
            . ($this->withComments ? ' considering comments' : '');
    }

    #[Override]
    protected function comparison(mixed $other): ?ComparisonFailure
    {
        if (!is_string($other)) {
            return null;
        }

        $expected = self::canonical($this->expected, $this->withComments);
        $actual   = self::canonical($other, $this->withComments);

        return new ComparisonFailure($expected, $actual, Differ::diff($expected, $actual));
    }

    /**
     * @throws XmlException when the document cannot be parsed
     */
    public static function canonical(string $xml, bool $withComments): string
    {
        $document                     = new DOMDocument();
        $document->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $loaded = trim($xml) !== '' && $document->loadXML($xml);
        $error  = libxml_get_last_error();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new XmlException($error === false ? 'The document could not be parsed as XML.' : trim($error->message));
        }

        $canonical = $document->C14N(false, $withComments);

        if ($canonical === false) {
            throw new XmlException('The document could not be canonicalized.');
        }

        return $canonical;
    }
}
