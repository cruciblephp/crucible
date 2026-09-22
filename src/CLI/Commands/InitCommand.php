<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Console\Components\ConfirmPrompt;
use LucianoPereira\Crucible\Console\Components\TextPrompt;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function basename;
use function file_put_contents;
use function is_file;
use function preg_match;
use function printf;
use function rtrim;
use function strtolower;

use const PHP_EOL;

/**
 * `crucible --init`: create a starter crucible.php in the current
 * directory. At a real terminal this asks for the common choices
 * (test/source directories, strict mode) and confirms before
 * overwriting; a non-interactive run (CI, piped input) keeps every
 * prompt's default, so scripted use writes the exact same file as
 * before this command had prompts at all.
 */
final class InitCommand
{
    private const string PATH_HINT = 'Letters, digits, dots, dashes, underscores, and slashes only.';

    public function execute(WorkingDirectory $workingDirectory): int
    {
        $loader = new Loader();

        if ($loader->exists($workingDirectory)) {
            $overwrite = new ConfirmPrompt(
                'A crucible.php (or crucible.dist.php) already exists in this directory. Overwrite it?',
                confirmed: false,
            );
            Runtime::run($overwrite);

            if (! $overwrite->value()) {
                print 'A crucible.php (or crucible.dist.php) already exists in this directory.' . PHP_EOL;

                return 1;
            }
        } else {
            // A project with a phpunit.xml (a Laravel app, most likely)
            // wants its existing configuration, not a blank template —
            // only checked on a first run, not when the user just chose
            // to overwrite a crucible.php they already had.
            foreach (['phpunit.xml', 'phpunit.xml.dist'] as $existing) {
                if (is_file($workingDirectory->path . '/' . $existing)) {
                    printf('Found %s — converting it instead of writing the blank template.' . PHP_EOL, $existing);

                    return (new MigrateConfigCommand())->execute($workingDirectory);
                }
            }
        }

        $testDirectory = new TextPrompt(
            label: 'Where do your tests live?',
            default: 'tests/Unit',
            hint: self::PATH_HINT,
            required: true,
            validate: $this->pathValidator(...),
        );
        Runtime::run($testDirectory);

        $sourceDirectory = new TextPrompt(
            label: 'Where does your source code live?',
            default: 'src',
            hint: self::PATH_HINT,
            required: true,
            validate: $this->pathValidator(...),
        );
        Runtime::run($sourceDirectory);

        $strict = new ConfirmPrompt('Fail the run on deprecations, warnings, and notices (--strict)?', confirmed: true);
        Runtime::run($strict);

        $path = $workingDirectory->path . '/crucible.php';

        $written = file_put_contents(
            $path,
            $this->template($testDirectory->value(), $sourceDirectory->value(), $strict->value()),
        );

        if ($written === false) {
            printf('Cannot write %s.' . PHP_EOL, $path);

            return 1;
        }

        printf('Created %s.' . PHP_EOL, $path);

        return 0;
    }

    /** Restricts typed directories to safe path characters — this text is interpolated straight into generated PHP source. */
    private function pathValidator(string $value): ?string
    {
        return preg_match('#^[A-Za-z0-9_./-]+$#', $value) === 1
            ? null
            : 'Use only letters, digits, dots, dashes, underscores, and slashes.';
    }

    /**
     * The suite is named after the directory it holds, not always
     * `unit`. Answering `tests/Feature` used to produce
     * `testSuite('unit', 'tests/Feature')` — a name that then meant
     * something else on the command line, since `--testsuite unit`
     * would run the feature tests. The default answer still yields
     * `unit`, so only the misleading cases change.
     */
    private function suiteName(string $testDirectory): string
    {
        $name = strtolower(basename(rtrim($testDirectory, '/')));

        return $name === '' ? 'unit' : $name;
    }

    private function template(string $testDirectory, string $sourceDirectory, bool $strict): string
    {
        $strictCall = $strict ? "\n    ->strict()" : '';
        $suite      = $this->suiteName($testDirectory);

        // One comment, on the one line that fails quietly. Coverage, the
        // impact graph and architecture rules all read source(), so a
        // wrong path degrades three features without an error — while
        // everything else here is either obvious or discoverable from
        // the editor, which completes this file.
        return <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->bootstrap('vendor/autoload.php')
                ->testSuite('{$suite}', '{$testDirectory}')
                // Read by coverage, impact selection and architecture rules.
                ->source(include: ['{$sourceDirectory}']){$strictCall};

            PHP;
    }
}
