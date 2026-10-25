<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\DialectClosureThisExtension;
use LucianoPereira\Crucible\PHPStan\DialectThisResolver;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The Pest.php files that decide `$this` are dependencies of the files they
 * configure (D-157): one created, changed or deleted reaches the next
 * analysis through PHPStan's own result cache, which is never cleared here.
 */
#[CoversClass(DialectThisResolver::class)]
#[CoversClass(DialectClosureThisExtension::class)]
#[Group('phpstan-blackbox')]
final class PestConfigurationCacheTest extends TestCase
{
    private const string DEFAULT = 'phpstan.dumpType: Dumped type: LucianoPereira\Crucible\Dialect\Pest\PestTestCase';

    private const string SCOPED = 'phpstan.dumpType: Dumped type: CrucibleCacheFixture\CacheCase';

    private const string CONFIGURATION = "<?php\n\n\\pest()->extend(\\CrucibleCacheFixture\\CacheCase::class)->in('Feature');\n";

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crucible-pest-cache-' . uniqid();

        mkdir($this->root . '/tests/Feature', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/tests/CacheCase.php', <<<'PHP'
            <?php

            namespace CrucibleCacheFixture;

            class CacheCase extends \LucianoPereira\Crucible\Framework\TestCase {}
            PHP);
        file_put_contents($this->root . '/tests/Feature/CacheTest.php', <<<'CODE'
            <?php

            test('reads $this', function (): void {
                \PHPStan\dumpType($this);
            });
            CODE);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAPestPhpCreatedChangedOrDeletedReachesTheCachedAnalysis(): void
    {
        self::assertSame([self::DEFAULT], $this->analyse(), 'no Pest.php: the default class');

        file_put_contents($this->root . '/tests/Pest.php', self::CONFIGURATION);
        self::assertSame([self::SCOPED], $this->analyse(), 'a created Pest.php scopes its class in');

        file_put_contents($this->root . '/tests/Pest.php', "<?php\n");
        self::assertSame([self::DEFAULT], $this->analyse(), 'a changed Pest.php takes it back');

        file_put_contents($this->root . '/tests/Pest.php', self::CONFIGURATION);
        self::assertSame([self::SCOPED], $this->analyse(), 'and gives it again');

        unlink($this->root . '/tests/Pest.php');
        self::assertSame([self::DEFAULT], $this->analyse(), 'a deleted Pest.php leaves the default');
    }

    /**
     * @return list<string>
     */
    private function analyse(): array
    {
        return Analysis::rendered(Analysis::run([$this->root . '/tests/Feature'], Analysis::extension(), parameters: [
            'bootstrapFiles' => [$this->root . '/tests/CacheCase.php'],
        ], directory: $this->root . '/phpstan'));
    }

    private function remove(string $path): void
    {
        $entries = is_dir($path) ? scandir($path) : false;

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($path . '/' . $entry) ? $this->remove($path . '/' . $entry) : unlink($path . '/' . $entry);
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
