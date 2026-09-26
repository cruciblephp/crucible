<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible;

use function dirname;
use function file_get_contents;
use function is_string;
use function preg_replace;
use function trim;

final class Version
{
    public const string NUMBER = '1.1.0';
    public const string AUTHOR = 'Luciano Federico Pereira';

    /** The project's own home, for the places branding says more than a byline. */
    public const string DOMAIN = 'cruciblephp.com';

    /**
     * The PHPUnit major release whose observable behavior Crucible targets
     * for drop-in compatibility. A compatibility statement, not a code
     * lineage: Crucible is an independent implementation.
     */
    public const string COMPATIBILITY_TARGET = 'PHPUnit 13';

    /**
     * The same statement as a bare version, for documents whose schema
     * carries a field naming the PHPUnit release they conform to. A
     * format declaration a consumer version-checks against, not a claim
     * of lineage: the sentence above still applies.
     */
    public const string COMPATIBILITY_SERIES = '13';

    /**
     * The logo, as inline SVG markup: `assets/crucible.svg`, which ships in
     * the package, without its XML declaration. For the reports a person
     * reads — the HTML pages and the PDF — never for the machine formats,
     * whose shape the incumbents' own writers fix. Empty when the file
     * cannot be read, and a report then simply goes without it.
     */
    public static function logo(): string
    {
        static $logo = null;

        if (!is_string($logo)) {
            $svg  = @file_get_contents(dirname(__DIR__) . '/assets/crucible.svg');
            $logo = is_string($svg) ? trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg)) : '';
        }

        return $logo;
    }

    /**
     * The logo for an HTML report's heading, embedded so each page stays
     * one self-contained file, and hidden from screen readers, since the
     * heading's text already names the report. Sized by the page's
     * `.logo svg` rule.
     */
    public static function logoHtml(): string
    {
        $logo = self::logo();

        return $logo === '' ? '' : '<span class="logo" aria-hidden="true">' . $logo . '</span>';
    }
}
