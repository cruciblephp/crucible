<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ReportFormat;

use Attribute;
use LucianoPereira\Crucible\Reporting\Registry\PluginAttribute;

/**
 * Declares a report-format plugin's metadata directly on the class
 * that implements {@see ReportFormatContract} — read via
 * `ReflectionClass::getAttributes()` by {@see ReportFormatRegistry}
 * without ever constructing the class, so a format can be listed and
 * described even before its params are known to be valid.
 *
 * Deliberately separate from the existing `Extension`/`Check` plugin
 * system (`src/Extension/`), which registers already-constructed
 * instances instead — a second, class-string/reflection-based
 * mechanism, kept apart from that one by design, not by oversight.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ReportFormat extends PluginAttribute {}
