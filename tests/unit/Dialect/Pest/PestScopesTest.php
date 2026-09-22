<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use Closure;
use Generator;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\PestScopes;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use ReflectionProperty;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(PestScopes::class)]
final class PestScopesTest extends TestCase
{
    /**
     * PestScopes' state is process-global and is filled during
     * DISCOVERY, before any test runs — this suite's own
     * tests/unit/Pest.php is in there. So it is snapshotted and put
     * back rather than cleared: a test that emptied it would be
     * rewriting the collection the rest of the run was built from.
     *
     * @var array<string, mixed>
     */
    private array $saved = [];

    /** @var list<string> */
    private array $rubbish = [];

    protected function setUp(): void
    {
        foreach (['registrations', 'datasets', 'loaded', 'configDir', 'printer'] as $name) {
            $this->saved[$name] = (new ReflectionProperty(PestScopes::class, $name))->getValue();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            (new ReflectionProperty(PestScopes::class, $name))->setValue(null, $value);
        }

        foreach ($this->rubbish as $dir) {
            $this->remove($dir);
        }

        $this->rubbish = [];
    }

    private function remove(string $dir): void
    {
        $entries = is_dir($dir) ? scandir($dir) : false;

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($dir . '/' . $entry) ? $this->remove($dir . '/' . $entry) : unlink($dir . '/' . $entry);
        }

        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    /**
     * @return non-empty-string
     */
    private function tree(): string
    {
        $root = sys_get_temp_dir() . '/crucible-scopes-' . uniqid();

        mkdir($root . '/Feature', 0o777, true);
        $this->rubbish[] = $root;

        return $root;
    }

    private function write(string $file, string $body): void
    {
        file_put_contents($file, "<?php\n\nuse LucianoPereira\\Crucible\\Dialect\\Pest\\PestScopes;\n\n" . $body . "\n");
    }

    /**
     * Real-world datasets commonly compose many sub-generators via
     * `yield from` (confirmed against spatie/laravel-data's own
     * tests/Datasets/RulesDataset.php — ~35 of them, ~400 total
     * rows), each restarting its own implicit 0-based key numbering.
     * Preserving those keys collides: the second generator's row at
     * key 0 overwrites the first's, and so on. Real Pest's own
     * DatasetsRepository::processDatasets() avoids exactly this via
     * `iterator_to_array($generator, preserveKeys: is_string($generator->key()))`
     * — verified against its real source, not guessed. Found via a
     * real benchmark: this materialized 129 rows for
     * spatie/laravel-data's own ~35-generator composed dataset where
     * real Pest — and the real project's own test suite — produces
     * 413.
     */
    public function testKeepsEveryRowFromMultipleComposedGenerators(): void
    {
        $generator = (static function (): Generator {
            yield from (static function (): Generator {
                yield ['espresso', 1];
                yield ['espresso', 2];
            })();

            yield from (static function (): Generator {
                yield ['filter', 1];
                yield ['filter', 2];
                yield ['filter', 3];
            })();
        })();

        $materialized = PestScopes::materialize($generator);

        self::assertCount(5, $materialized);
        self::assertContains(['espresso', 1], $materialized);
        self::assertContains(['espresso', 2], $materialized);
        self::assertContains(['filter', 1], $materialized);
        self::assertContains(['filter', 2], $materialized);
        self::assertContains(['filter', 3], $materialized);
    }

    /**
     * The exact behavior this preserves: a single generator's own
     * deliberate string keys (an author-chosen, readable case label)
     * still survive, matching real Pest's own preserveKeys: true
     * branch — collision avoidance for implicit int keys must not
     * regress this.
     */
    public function testPreservesStringKeysFromASingleGenerator(): void
    {
        $generator = (static function (): Generator {
            yield 'short' => 'espresso';
            yield 'longer' => 'filter';
        })();

        self::assertSame([
            'short'  => 'espresso',
            'longer' => 'filter',
        ], PestScopes::materialize($generator));
    }

    /**
     * A plain array's own keys are never in question — materialize()
     * returns it untouched, the same as before this fix.
     */
    public function testReturnsAPlainArrayUnchanged(): void
    {
        $rows = ['a' => 1, 'b' => 2];

        self::assertSame($rows, PestScopes::materialize($rows));
    }

    /**
     * Outer configuration registers before inner, because the outer
     * one is meant to apply first — and each directory is required
     * once per process however many files sit under it.
     */
    public function testConfigurationLoadsOutermostFirstAndOncePerDirectory(): void
    {
        $root = $this->tree();

        $this->write($root . '/Pest.php', "PestScopes::pest()->group('outer')->in('.');");
        $this->write($root . '/Feature/Pest.php', "PestScopes::pest()->group('inner')->in('.');");

        (new ReflectionProperty(PestScopes::class, 'registrations'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'loaded'))->setValue(null, []);

        PestScopes::loadConfiguration($root, $root . '/Feature');

        $matching = PestScopes::matching($root . '/Feature/ExampleTest.pest.php');

        self::assertSame([['outer'], ['inner']], [$matching[0]->groups, $matching[1]->groups]);

        // Loading again must not register the same file twice: a
        // duplicated registration would apply every hook twice.
        PestScopes::loadConfiguration($root, $root . '/Feature');

        self::assertCount(2, PestScopes::matching($root . '/Feature/ExampleTest.pest.php'));
    }

    public function testPestBelongsToAConfigurationFile(): void
    {
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, null);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Pest.php configuration file');

        PestScopes::pest();
    }

    /**
     * Per-folder scoping: several directories may declare the same
     * dataset name, and the nearest one to the test file wins.
     */
    public function testTheNearestDeclarationOfADatasetNameWins(): void
    {
        $root = $this->tree();

        (new ReflectionProperty(PestScopes::class, 'datasets'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root);
        PestScopes::dataset('brews', [['outer']]);

        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root . '/Feature');
        PestScopes::dataset('brews', [['inner']]);

        self::assertSame([['inner']], PestScopes::resolveDataset('brews', $root . '/Feature'));
        self::assertSame([['outer']], PestScopes::resolveDataset('brews', $root));
    }

    public function testAnUnknownDatasetNamesItselfAndWhereToDeclareIt(): void
    {
        (new ReflectionProperty(PestScopes::class, 'datasets'))->setValue(null, []);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Unknown dataset 'nowhere'");

        PestScopes::resolveDataset('nowhere', $this->tree());
    }

    /**
     * A closure-valued dataset is re-invoked per use, which is what
     * lets a generator be read more than once — read directly, the
     * second use would find it exhausted.
     */
    public function testAClosureDatasetReplaysOnEveryUse(): void
    {
        $root = $this->tree();

        (new ReflectionProperty(PestScopes::class, 'datasets'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root);

        PestScopes::dataset('rows', static fn(): Generator => yield from [['a'], ['b']]);

        self::assertSame([['a'], ['b']], PestScopes::resolveDataset('rows', $root));
        self::assertSame([['a'], ['b']], PestScopes::resolveDataset('rows', $root));
    }

    public function testAClosureDatasetMustReturnSomethingIterable(): void
    {
        $root = $this->tree();

        (new ReflectionProperty(PestScopes::class, 'datasets'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root);

        PestScopes::dataset('rows', static fn(): string => 'not iterable');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must return an iterable');

        PestScopes::resolveDataset('rows', $root);
    }

    /**
     * A bare registration — no ->in() — contributes its HOOKS to the
     * whole tree and nothing else. Without hooks it contributes
     * nothing, so it must not be returned at all.
     */
    public function testABareRegistrationIsSelectedOnlyWhenItCarriesHooks(): void
    {
        $root = $this->tree();

        (new ReflectionProperty(PestScopes::class, 'registrations'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root);

        $silent = PestScopes::pest()->group('ignored');

        self::assertSame([], PestScopes::matching($root . '/Feature/ExampleTest.pest.php'));

        $silent->beforeEach(static function (): void {});

        self::assertCount(1, PestScopes::matching($root . '/Feature/ExampleTest.pest.php'));
    }

    public function testAGlobSelectsOnlyBeneathTheDeclaringDirectory(): void
    {
        $root = $this->tree();

        (new ReflectionProperty(PestScopes::class, 'registrations'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root);

        PestScopes::pest()->group('featureOnly')->in('Feature');

        self::assertCount(1, PestScopes::matching($root . '/Feature/ExampleTest.pest.php'));
        self::assertSame([], PestScopes::matching($root . '/Unit/ExampleTest.pest.php'));

        // A file outside the declaring directory entirely is never a
        // candidate, whatever the glob says.
        self::assertSame([], PestScopes::matching('/elsewhere/Feature/ExampleTest.pest.php'));
    }

    public function testTheSuiteDeclaredPrinterIsWhateverAConfigurationChose(): void
    {
        (new ReflectionProperty(PestScopes::class, 'printer'))->setValue(null, null);

        self::assertNull(PestScopes::printerPreference());

        PestScopes::selectPrinter('compact');

        self::assertSame('compact', PestScopes::printerPreference());
    }

    public function testConfiguringSaysWhetherAConfigurationFileIsLoading(): void
    {
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, null);

        self::assertFalse(PestScopes::configuring());

        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $this->tree());

        self::assertTrue(PestScopes::configuring());
    }

    public function testDatasetFilesBesideAPestFileAreLoadedToo(): void
    {
        $root = $this->tree();

        mkdir($root . '/Datasets', 0o777, true);
        $this->write($root . '/Datasets/Brews.php', "PestScopes::dataset('brews', [['espresso']]);");

        (new ReflectionProperty(PestScopes::class, 'datasets'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'loaded'))->setValue(null, []);

        PestScopes::loadConfiguration($root, $root);

        self::assertSame([['espresso']], PestScopes::resolveDataset('brews', $root));
    }

    /** The legacy spelling: uses() is pest()->assign() with the same scope. */
    public function testUsesIsTheLegacySpellingOfTheSameChain(): void
    {
        require_once __DIR__ . '/../../../_fixtures/trait-shapes/shapes.php';

        $root = $this->tree();

        (new ReflectionProperty(PestScopes::class, 'registrations'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root);

        $registration = PestScopes::uses('LucianoPereira\\Crucible\\Tests\\Fixtures\\TraitShapes\\Greets');

        self::assertSame(['LucianoPereira\\Crucible\\Tests\\Fixtures\\TraitShapes\\Greets'], $registration->traits);
    }

    public function testADatasetNeedsAFileThatOwnsIt(): void
    {
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, null);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('can only be called from a Pest.php');

        PestScopes::dataset('orphaned', [['row']]);
    }

    public function testADatasetKeyMustBeAnIntOrAString(): void
    {
        // Generators may key on anything at all; an array key cannot be
        // a float, so this is refused where it is readable rather than
        // silently truncated by PHP's own coercion.
        $generator = (static function (): Generator {
            yield 1.5 => ['row'];
        })();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Dataset keys must be ints or strings');

        PestScopes::materialize($generator);
    }

    /**
     * A plain name selects a subtree; anything with a wildcard is an
     * fnmatch pattern where `*` does not cross a directory separator.
     */
    public function testAWildcardGlobDoesNotCrossADirectorySeparator(): void
    {
        $root = $this->tree();

        (new ReflectionProperty(PestScopes::class, 'registrations'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'configDir'))->setValue(null, $root);

        PestScopes::pest()->group('shallow')->in('*.pest.php');

        self::assertCount(1, PestScopes::matching($root . '/ExampleTest.pest.php'));
        self::assertSame([], PestScopes::matching($root . '/Feature/ExampleTest.pest.php'));
    }

    /**
     * A directory that is not under the root has no chain to walk, so
     * only that directory's own configuration is considered.
     */
    public function testADirectoryOutsideTheRootIsItsOwnChain(): void
    {
        $root  = $this->tree();
        $other = $this->tree();

        $this->write($other . '/Pest.php', "PestScopes::pest()->group('elsewhere')->in('.');");

        (new ReflectionProperty(PestScopes::class, 'registrations'))->setValue(null, []);
        (new ReflectionProperty(PestScopes::class, 'loaded'))->setValue(null, []);

        PestScopes::loadConfiguration($root, $other);

        self::assertCount(1, PestScopes::matching($other . '/ExampleTest.pest.php'));
    }
}
