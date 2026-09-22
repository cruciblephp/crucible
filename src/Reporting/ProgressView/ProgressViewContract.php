<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ProgressView;

use LucianoPereira\Crucible\Event\Listener;

/**
 * A Listener that is also a registrable, describable progress-view
 * plugin — the live, STDOUT-facing printer a run subscribes exactly
 * one of. A conforming class's constructor must accept the stream as
 * its first positional parameter (`ProgressViewRegistry::resolve()`
 * always passes it first, before any configured params); anything
 * else the class needs comes from its own `#[ProgressView(params:
 * [...])]` schema, validated the same way any other plugin kind's
 * params are.
 */
interface ProgressViewContract extends Listener {}
