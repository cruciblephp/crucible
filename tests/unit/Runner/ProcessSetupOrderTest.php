<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Php;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\ProcessSetup;

use function file_get_contents;
use function file_put_contents;
use function get_include_path;
use function getenv;
use function ini_get;
use function ini_set;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function mkdir;
use function putenv;
use function realpath;
use function rmdir;
use function scandir;
use function set_include_path;
use function strtr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * PHPUnit applies `<ini>`, `<const>` and `<env>` before the bootstrap
 * (PhpHandler, then BootstrapLoader), so a bootstrap that boots an
 * application reads the configured values. Crucible ran the bootstrap
 * first, and Symfony's Dotenv booted dev from a missing APP_ENV (F-02).
 */
#[CoversClass(ProcessSetup::class)]
final class ProcessSetupOrderTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    /** @var non-empty-string */
    private string $env = 'CRUCIBLE_ORDER_ENV';

    /** @var non-empty-string */
    private string $constant = 'CRUCIBLE_ORDER_CONST';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-order-' . uniqid();

        mkdir($root, 0o777, true);

        $real           = realpath($root);
        $this->project  = $real === false ? $root : $real;
        $this->env      = 'CRUCIBLE_ORDER_ENV_' . uniqid();
        $this->constant = 'CRUCIBLE_ORDER_CONST_' . uniqid();
    }

    protected function tearDown(): void
    {
        putenv($this->env);
        unset($_ENV[$this->env]);

        $this->remove($this->project);
    }

    public function testABootstrapSeesTheIniEnvAndConstantsItWasConfiguredWith(): void
    {
        $ini        = 'default_mimetype';
        $beforeIni  = (string) ini_get($ini);
        $beforePath = get_include_path();

        $source = <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/observed.json', json_encode([
                'env'          => getenv('__ENV__'),
                'env_array'    => $_ENV['__ENV__'] ?? null,
                'ini'          => ini_get('default_mimetype'),
                'constant'     => defined('__CONST__') ? constant('__CONST__') : null,
                'include_path' => get_include_path(),
            ]));
            PHP;

        file_put_contents(
            $this->project . '/boot.php',
            strtr($source, ['__ENV__' => $this->env, '__CONST__' => $this->constant]),
        );

        try {
            ProcessSetup::phpSettings(
                new Configuration([], new Source(), new Php(
                    ini: [$ini => 'text/x-crucible-order'],
                    env: [$this->env => 'from-config'],
                    constants: [$this->constant => 'from-config'],
                ), bootstrap: 'boot.php'),
                new WorkingDirectory($this->project),
                includePaths: [$this->project],
            );

            $observed = $this->observed();

            self::assertSame('from-config', $observed['env'] ?? null, 'The bootstrap did not see the configured env.');
            self::assertSame('from-config', $observed['env_array'] ?? null, '$_ENV was not set before the bootstrap.');
            self::assertSame('text/x-crucible-order', $observed['ini'] ?? null, 'The bootstrap did not see the configured ini.');
            self::assertSame('from-config', $observed['constant'] ?? null, 'The bootstrap did not see the configured constant.');
            self::assertStringContainsString(
                $this->project,
                is_string($observed['include_path'] ?? null) ? $observed['include_path'] : '',
                'The bootstrap did not see the configured include path.',
            );
        } finally {
            ini_set($ini, $beforeIni);
            set_include_path($beforePath);
        }
    }

    public function testABootstrapThatWritesBackKeepsWhatItWrote(): void
    {
        $ini       = 'default_mimetype';
        $beforeIni = (string) ini_get($ini);

        $source = <<<'PHP'
            <?php

            putenv('__ENV__=from-bootstrap');
            $_ENV['__ENV__'] = 'from-bootstrap';
            ini_set('default_mimetype', 'text/x-bootstrap');
            PHP;

        file_put_contents(
            $this->project . '/boot.php',
            strtr($source, ['__ENV__' => $this->env]),
        );

        try {
            ProcessSetup::phpSettings(
                new Configuration([], new Source(), new Php(
                    ini: [$ini => 'text/x-config'],
                    env: [$this->env => 'from-config'],
                ), bootstrap: 'boot.php'),
                new WorkingDirectory($this->project),
            );

            self::assertSame('from-bootstrap', getenv($this->env));
            self::assertSame('from-bootstrap', $_ENV[$this->env] ?? null);
            self::assertSame('text/x-bootstrap', ini_get($ini));
        } finally {
            ini_set($ini, $beforeIni);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function observed(): array
    {
        $decoded = json_decode((string) file_get_contents($this->project . '/observed.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
