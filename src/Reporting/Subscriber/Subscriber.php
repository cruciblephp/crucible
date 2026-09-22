<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Subscriber;

use Attribute;
use LucianoPereira\Crucible\Reporting\Registry\PluginAttribute;

/**
 * Declares a subscriber plugin's metadata directly on the class that
 * implements {@see SubscriberContract} — read via
 * `ReflectionClass::getAttributes()` by {@see SubscriberRegistry}
 * without ever constructing the class, so a subscriber can be listed
 * and described even before its params are known to be valid.
 *
 * Mirrors {@see \LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormat}
 * exactly — the same second, class-string/reflection-based mechanism,
 * kept apart from `Extension`/`Check`'s instance-based registration by
 * design, not by oversight.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Subscriber extends PluginAttribute {}
