<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ReportFormat\Formats;

use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\Renderers\MarkdownRenderer;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormat, ReportFormatContract};

/**
 * Crucible's own Markdown report, as a report-format plugin — same
 * registration path as any third party's own format. Delegates to
 * {@see MarkdownRenderer}, untouched by this refactor.
 */
#[ReportFormat(
    key: 'markdown',
    description: 'Render results as a Markdown report.',
    comment: 'Pasteable into a PR description, a wiki page, or a chat message and readable in all of them.',
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class MarkdownReportFormat implements ReportFormatContract
{
    public function render(Document $document, ReportContext $context, array $params): string
    {
        return (new MarkdownRenderer())->render($document);
    }
}
