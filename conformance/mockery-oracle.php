<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Runs the MOCKERY-side oracle (D-066): the real mockery/mockery and
 * its own PHPUnit, from the gitignored mockery-main reference install
 * — the same black-box the M1–M3 probe batteries ran against, now as
 * a conformance lane. Autoloader pinned like the phpunit oracle.
 */

$GLOBALS['_composer_autoload_path'] = __DIR__ . '/../mockery-main/vendor/autoload.php';

require __DIR__ . '/../mockery-main/vendor/phpunit/phpunit/phpunit';
