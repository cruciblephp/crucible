<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

use LucianoPereira\Crucible\Tests\Fixtures\PHPStan\Scoped\ScopedCase;

\pest()->extend(ScopedCase::class)->in('Feature');
