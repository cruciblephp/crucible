<?php

declare(strict_types=1);

use LucianoPereira\Crucible\Configuration\Crucible;

return Crucible::configure()
    ->bootstrap('vendor/autoload.php')
    ->testSuite('unit', 'tests/Unit')
    ->source(include: ['src'])
    ->strict();
