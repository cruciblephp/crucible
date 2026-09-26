<?php

declare(strict_types=1);

use LucianoPereira\Crucible\Configuration\Crucible;

// The harness passes Crucible's own checkout, where phpstan is installed.
return Crucible::configure()
    ->testSuite('unit', 'tests/Unit')
    ->typeTests('tests/Types', phpstan: getenv('CRUCIBLE_ROOT') . '/vendor/bin/phpstan')
    ->build();
