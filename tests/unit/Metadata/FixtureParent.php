<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Metadata;

use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\RequiresPhp;

#[Group('parent')]
#[RequiresPhp('8.5')]
abstract class FixtureParent {}
