<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ProgressView;

use Attribute;
use LucianoPereira\Crucible\Reporting\Registry\PluginAttribute;

/**
 * Declares a progress-view plugin's metadata directly on the class
 * that implements {@see ProgressViewContract} — read via
 * `ReflectionClass::getAttributes()` by {@see ProgressViewRegistry}
 * without ever constructing the class, so a view can be listed and
 * described even before its params are known to be valid.
 *
 * Mirrors {@see \LucianoPereira\Crucible\Reporting\Subscriber\Subscriber}
 * and {@see \LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormat}
 * exactly — the same class-string/reflection-based mechanism, kept
 * apart from `Extension`/`Check`'s instance-based registration by
 * design, not by oversight.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProgressView extends PluginAttribute {}
