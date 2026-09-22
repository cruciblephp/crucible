<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension;

/**
 * The marker every PHP-native extension implements (D-078). An
 * extension plays one or more ROLES — each a small interface it also
 * implements ({@see Check} today; subscriber and reporter roles land
 * with their own consumers) — and Crucible dispatches by `instanceof`, so
 * a plugin carries only the roles it actually fills. Registered typed,
 * as an instance, via `->extension(...)` in `crucible.php`.
 */
interface Extension {}
