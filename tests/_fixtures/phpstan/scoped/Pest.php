<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

use LucianoPereira\Crucible\Tests\Fixtures\PHPStan\Scoped\ScopedCase;

\pest()->extend(ScopedCase::class)->in('Feature');
