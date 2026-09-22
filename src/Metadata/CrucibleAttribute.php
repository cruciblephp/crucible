<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Metadata;

/**
 * Marker for every Crucible attribute. The parser collects only
 * attributes implementing this, so foreign attributes on test code
 * are ignored, and dialect frontends can construct metadata
 * programmatically from anything that implements it.
 */
interface CrucibleAttribute {}
