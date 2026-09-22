<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace CrucibleProbe\Unloadable;

/*
 * A covers target whose optional package is absent: declaring it throws
 * out of the autoloader. Crucible's own laravel bridge is this class on
 * a machine without illuminate, and one #[CoversClass] naming it used to
 * end every coverage run over the suite.
 *
 * Deliberately reached only through the autoloader the test registers —
 * no namespace here is mapped by composer, so nothing else can trip on
 * it.
 */
final class Target extends \Absent\Vendor\BaseClass {}
