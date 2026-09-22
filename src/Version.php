<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible;

final class Version
{
    public const string NUMBER = '1.0.0';
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
}
