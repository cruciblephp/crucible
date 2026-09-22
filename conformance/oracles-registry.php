<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The oracles, in one place (ORACLES.md explains what they are for).
 *
 * This exists because the install commands used to live in four —
 * ORACLES.md, the conformance skip message, the analysis skip message
 * and the CI workflow — and correcting mockery's command in two of them
 * left the other two telling people to run a form that downloads 2.5 GB
 * or produces an install that cannot start. Every consumer reads this
 * now, so a command can only be wrong everywhere at once, which is the
 * kind of wrong that gets noticed.
 *
 * `probe` is the path whose presence means "installed": a file or
 * directory the oracle cannot work without, not merely its root, so a
 * half-finished install reports absent rather than broken.
 *
 * @return array<string, array{probe: non-empty-string, install: non-empty-string, unlocks: non-empty-string}>
 */

return [
    'phpunit-main' => [
        'probe'   => 'phpunit-main/src',
        'install' => 'composer create-project phpunit/phpunit phpunit-main',
        'unlocks' => 'the conformance lanes and the compatibility analysis tier',
    ],
    'mockery-main' => [
        // Cloned, not create-project'd: mockery export-ignores /tests
        // while its autoload-dev requires tests/Bootstrap.php, so a dist
        // install fatals in its own autoloader. --prefer-source also
        // fixes that but clones full history for every dependency —
        // 2.5 GB against 147 MB for this.
        'probe'   => 'mockery-main/vendor/autoload.php',
        'install' => 'git clone --depth 1 https://github.com/mockery/mockery.git mockery-main && (cd mockery-main && composer install)',
        'unlocks' => 'the mockery conformance lane and the compatibility analysis tier',
    ],
    'phpcpd-main' => [
        // Cloned at a tag rather than required: the duplication check is
        // an optional extension, so the tool must stay out of Crucible's
        // dependencies and be installed only to prove the check works.
        //
        // phpcpd-next 2.0 requires PHP >= 8.4 while Crucible supports
        // 8.3, so on a supported-floor machine this oracle cannot
        // install and the duplication tests skip by name. Same shape as
        // pest-oracle below, and the same differentiator.
        'probe'   => 'phpcpd-main/vendor/autoload.php',
        'install' => 'git clone --depth 1 --branch v2.0 https://github.com/phpcpd-next/phpcpd phpcpd-main && (cd phpcpd-main && composer install --no-dev)',
        'unlocks' => 'the duplication check, against the real phpcpd-next',
    ],
    'pest-oracle' => [
        // The Pest 5 matcher parity probe (D-103) executes this; it is
        // never autoloaded into Crucible's process, which D-019 refuses
        // — two owners of test()/expect() in one process. Its own
        // vendor/ keeps it a black box reached through its binary.
        //
        // Pest 5 requires PHP >= 8.4 while Crucible supports 8.3, so on
        // a supported-floor machine this oracle cannot install at all.
        // That is the probe skipping, not a failure — and it is itself
        // the differentiator the probe exists beside.
        'probe'   => 'pest-oracle/vendor/bin/pest',
        'install' => 'mkdir -p pest-oracle && (cd pest-oracle'
            . ' && composer init --no-interaction --name=crucible/pest-oracle --require-dev="pestphp/pest:^5.0"'
            . ' && composer config allow-plugins.pestphp/pest-plugin true'
            . ' && composer install'
            . ' && mkdir -p tests'
            . ' && printf \'<?xml version="1.0"?><phpunit cacheDirectory=".phpunit.cache" colors="false"><testsuites><testsuite name="probe"><directory>tests</directory></testsuite></testsuites></phpunit>\' > phpunit.xml)',
        'unlocks' => 'the Pest 5 value-matcher parity probe, re-proved against the real Pest',
    ],
    'sarif-schema' => [
        // A single normative file, fetched rather than committed: it is
        // OASIS's, not this project's, and an oracle is where borrowed
        // truth belongs. The URL is the errata01 canonical one — the
        // schemastore address the renderer declares is a redirect, and
        // a redirect is not a schema.
        'probe'   => 'sarif-schema/sarif-schema-2.1.0.json',
        'install' => 'mkdir -p sarif-schema && curl -sSL -o sarif-schema/sarif-schema-2.1.0.json'
            . ' https://docs.oasis-open.org/sarif/sarif/v2.1.0/errata01/os/schemas/sarif-schema-2.1.0.json',
        'unlocks' => 'the SARIF conformance check — the report is validated, not merely well-formed',
    ],
    'browser-oracle' => [
        // npm install writes node_modules; the browser binaries land in
        // a cache outside the tree, so the probe is the package rather
        // than chromium itself.
        'probe'   => 'browser-oracle/node_modules/playwright',
        'install' => 'mkdir -p browser-oracle && (cd browser-oracle && npm init -y >/dev/null && npm install playwright axe-core && npx playwright install chromium)',
        'unlocks' => 'the browser tier: visit(), the page assertions, network idle, the axe accessibility check',
    ],
    'inertia-oracle' => [
        // The only oracle whose sources are tracked: the built bundle
        // and node_modules are not.
        'probe'   => 'inertia-oracle/inertia.js',
        'install' => 'cd inertia-oracle && npm install && npm run build',
        'unlocks' => 'the Inertia assertions, against the real @inertiajs/core',
    ],
    'livewire-oracle' => [
        // The probe is the rendered page, not vendor/: an app with the
        // packages but without the component and routes starts a server
        // that answers 404 to everything, and the Livewire tests then
        // wait on a round trip that never comes. Installed means usable.
        'probe'   => 'livewire-oracle/resources/views/counter-page.blade.php',
        'install' => 'composer create-project laravel/laravel livewire-oracle'
            . ' && (cd livewire-oracle && composer require livewire/livewire && composer require --dev spatie/phpunit-snapshot-assertions'
            . ' && php artisan make:livewire Counter --no-interaction'
            . ' && cp ../.github/fixtures/counter.blade.php resources/views/components/*counter.blade.php'
            . ' && cp ../.github/fixtures/counter-page.blade.php resources/views/counter-page.blade.php'
            . ' && cp ../.github/fixtures/web.php routes/web.php)',
        'unlocks' => 'the Livewire browser tests and the bridge analysis tier',
    ],
];
