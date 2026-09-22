<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\ReportFormat\Fixtures;

use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormat, ReportFormatContract};

/** Declares a requirement on a PHP extension that is never loaded, to test unavailability. */
#[ReportFormat(
    key: 'missing-ext',
    description: 'Requires a fictional extension.',
    requires: ['ext-totally-not-a-real-extension'],
)]
final class MissingExtensionFormat implements ReportFormatContract
{
    public function render(Document $document, ReportContext $context, array $params): string
    {
        return '';
    }
}
