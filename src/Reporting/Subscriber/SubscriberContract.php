<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Subscriber;

use LucianoPereira\Crucible\Event\Listener;

/**
 * A {@see Listener} that is also a registrable, describable subscriber
 * plugin. Unlike `ReportFormatContract::render()`, which takes its
 * params as a call-time argument, `Listener::handle()` has no room for
 * extra arguments — a subscriber's params (e.g. an output path) are
 * supplied at construction instead, via {@see SubscriberRegistry::resolve()}.
 * A resolved instance subscribes straight to the `Emitter`; there is no
 * generic wrapper listener the way `GenericReportWriter` wraps report
 * formats, because this contract already *is* `Listener`.
 */
interface SubscriberContract extends Listener {}
