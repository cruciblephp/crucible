<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The shape of an XML document, as the set of every "element[attr,attr]"
 * it contains.
 *
 * None of these report formats ships a schema — not Clover, not
 * Cobertura, not Crap4J, and the JUnit "standard" is a convention with
 * as many dialects as producers. "Is this document right" therefore has
 * no answer. "Would a consumer of the incumbent's document recognise
 * Crucible's" does: run both over the same fixture and compare the
 * vocabulary.
 *
 * Values are deliberately not compared. Two runs legitimately differ on
 * timings, paths, hashes and percentages; the shape is the contract.
 */

/**
 * @return list<string> every "element[attr,attr]" shape at $path, which
 *                      may be one document or a directory of them
 */
function vocabulary(string $path): array
{
    $files  = \is_dir($path) ? (\glob($path . '/*.xml') ?: []) : [$path];
    $shapes = [];

    foreach ($files as $file) {
        if (!\is_file($file)) {
            continue;
        }

        $document = new \DOMDocument();
        \libxml_use_internal_errors(true);

        if (!$document->load($file)) {
            \libxml_clear_errors();

            continue;
        }

        \libxml_clear_errors();

        foreach ((new \DOMXPath($document))->query('//*') ?: [] as $node) {
            $attributes = [];

            foreach ($node->attributes ?? [] as $attribute) {
                $attributes[] = $attribute->name;
            }

            \sort($attributes);

            $shapes[$node->localName . ($attributes === [] ? '' : '[' . \implode(',', $attributes) . ']')] = true;
        }
    }

    $shapes = \array_keys($shapes);
    \sort($shapes);

    return $shapes;
}
