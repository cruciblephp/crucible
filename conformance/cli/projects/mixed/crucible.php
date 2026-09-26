<?php

declare(strict_types=1);

use LucianoPereira\Crucible\Configuration\Crucible;

return Crucible::configure()
    ->testSuite('unit', 'tests')
    ->vitest('js', binary: __DIR__ . '/js/vitest')
    ->build();
