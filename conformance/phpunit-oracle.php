<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Runs the PHPUnit oracle (the black-box reference the conformance
 * suite compares against) with ITS OWN autoloader pinned — the
 * oracle's autoload probe would otherwise find Crucible's vendor first.
 */

$GLOBALS['_composer_autoload_path'] = __DIR__ . '/../phpunit-main/vendor/autoload.php';

require __DIR__ . '/../phpunit-main/phpunit';
