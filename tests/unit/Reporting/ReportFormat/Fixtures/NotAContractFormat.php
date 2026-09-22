<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\ReportFormat\Fixtures;

use LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormat;

/** Carries the attribute but does NOT implement ReportFormatContract. */
#[ReportFormat(key: 'not-a-contract', description: 'Misconfigured — missing the interface.')]
final class NotAContractFormat {}
