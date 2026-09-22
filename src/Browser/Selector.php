<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

use function in_array;
use function preg_match;
use function sprintf;
use function str_replace;
use function strtolower;

/**
 * The Pest selector grammar (spec/pest-api.md §6): visible text,
 * CSS (".btn", "#id"), and "@name" for data-test attributes. Targets
 * resolve to Playwright selector-engine strings; form fields get the
 * friendlier resolution (a bare word matches name=, then id=, then
 * data-test=) the incumbent's examples rely on (fill('email', ...)).
 */
final readonly class Selector
{
    /**
     * Bare words that are HTML element names select the element, not
     * its text — `click('Login')` means text, `assertCount('li', 3)`
     * means list items.
     */
    private const array HTML_TAGS = [
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'blockquote', 'body', 'br',
        'button', 'canvas', 'caption', 'code', 'col', 'colgroup', 'datalist', 'dd', 'details', 'dialog',
        'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'header', 'hr', 'i', 'iframe', 'img', 'input', 'label', 'legend', 'li', 'main',
        'map', 'mark', 'nav', 'ol', 'optgroup', 'option', 'output', 'p', 'picture', 'pre', 'progress',
        'q', 'section', 'select', 'small', 'span', 'strong', 'summary', 'sup', 'table', 'tbody', 'td',
        'template', 'textarea', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul', 'video',
    ];

    /**
     * A click/hover/read target: `@x` → data-test, CSS (including bare
     * element names) stays CSS, anything else means "the element with
     * this visible text".
     */
    public static function resolve(string $target): string
    {
        if (preg_match('/^@([\w-]+)$/', $target, $match) === 1) {
            return sprintf('[data-test="%s"]', $match[1]);
        }

        if (in_array(strtolower($target), self::HTML_TAGS, true)) {
            return $target;
        }

        if (self::looksLikeCss($target)) {
            return $target;
        }

        return 'text=' . $target;
    }

    /**
     * A form-field target: a bare word tries name, id, then data-test
     * (one CSS selector list — first match wins); explicit selectors
     * pass through resolve().
     */
    public static function field(string $field): string
    {
        if (preg_match('/^[\w-]+$/', $field) === 1) {
            $escaped = self::escape($field);

            return sprintf('[name="%1$s"], #%2$s, [data-test="%1$s"]', $escaped, $field);
        }

        return self::resolve($field);
    }

    /**
     * One radio in a group: the pair identifies it, since a radio's
     * name is shared by every option and only the value tells them
     * apart.
     */
    public static function radio(string $field, string $value): string
    {
        return sprintf('[name="%s"][value="%s"]', $field, $value);
    }

    private static function looksLikeCss(string $target): bool
    {
        return preg_match('/^[.#\[]|[>~+:\[\]]/', $target) === 1;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
