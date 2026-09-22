<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * Run in a subprocess by CrucibleServiceProviderTest against the
 * Laravel oracle (ORACLES.md). Boots the oracle application for real,
 * because runningInConsole() and commands() are Application behaviour,
 * not container behaviour, and a stub of them would prove nothing.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use LucianoPereira\Crucible\Bridge\Laravel\CrucibleServiceProvider;

$oracle   = $argv[1] ?? '';
$crucible = $argv[2] ?? '';
$token    = $argv[3] ?? '';

// Set before boot: the provider reads it during boot(), which is the
// moment a parallel worker would already have it exported.
if ($token !== '') {
    $_SERVER['TEST_TOKEN'] = $token;
}

require $oracle . '/vendor/autoload.php';
require $crucible . '/vendor/autoload.php';

$app = require $oracle . '/bootstrap/app.php';

$app->register(CrucibleServiceProvider::class);
$app->make(ConsoleKernel::class)->bootstrap();

$emit = static function (string $case, mixed $value): void {
    echo $case, ' ', json_encode($value, JSON_UNESCAPED_SLASHES), "\n";
};

$commands = array_keys($app->make(ConsoleKernel::class)->all());

$emit('registersTestCommand', in_array('test', $commands, true));
$emit('parallelFlag', $_SERVER['LARAVEL_PARALLEL_TESTING'] ?? null);
$emit('parallelEnv', getenv('LARAVEL_PARALLEL_TESTING') === false ? null : getenv('LARAVEL_PARALLEL_TESTING'));
$emit('token', $_SERVER['TEST_TOKEN'] ?? null);
