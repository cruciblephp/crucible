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

#[ReportFormat(
    key: 'valid',
    description: 'A valid fixture format.',
    comment: 'Used to test the happy path.',
    requires: [],
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output path'],
        ['name' => 'width', 'type' => 'int', 'required' => false, 'default' => 80, 'description' => 'Width'],
    ],
)]
final class ValidFormat implements ReportFormatContract
{
    public function render(Document $document, ReportContext $context, array $params): string
    {
        return 'rendered:' . $context->title;
    }
}
