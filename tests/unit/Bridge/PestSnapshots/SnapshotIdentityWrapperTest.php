<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Bridge\PestSnapshots;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Bridge\PestSnapshots\SnapshotFileContext;
use LucianoPereira\Crucible\Bridge\PestSnapshots\SnapshotIdentityWrapper;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Fixtures\SnapshotShapes\ComposedSnapshotting;
use LucianoPereira\Crucible\Tests\Fixtures\SnapshotShapes\FinalSnapshotting;
use LucianoPereira\Crucible\Tests\Fixtures\SnapshotShapes\InheritedSnapshotting;
use LucianoPereira\Crucible\Tests\Fixtures\SnapshotShapes\NotSnapshotting;
use LucianoPereira\Crucible\Tests\Fixtures\SnapshotShapes\Snapshotting;
use ReflectionMethod;

use function class_uses;
use function is_subclass_of;

/**
 * The fifth generated-code site, and the last one without a corpus.
 *
 * It went unexercised for a structural reason worth naming: it returns
 * early unless spatie/phpunit-snapshot-assertions is installed, and the
 * engine deliberately does not depend on it — so every path past that
 * guard was unreachable in this suite. The fixture declares the trait
 * when the package is absent, which is what makes the site testable at
 * all.
 */
#[CoversClass(SnapshotIdentityWrapper::class)]
final class SnapshotIdentityWrapperTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../_fixtures/snapshot-shapes/shapes.php';
    }

    protected function tearDown(): void
    {
        // Static, and PestBuilder scopes it per test — an id resolved
        // here must not leak into whatever runs next.
        SnapshotFileContext::set(null, null);
    }

    public function testAClassUsingTheTraitIsWrappedInASubclassOfIt(): void
    {
        $wrapped = SnapshotIdentityWrapper::wrap(Snapshotting::class);

        self::assertNotSame(Snapshotting::class, $wrapped);
        self::assertTrue(is_subclass_of($wrapped, Snapshotting::class));

        // Generated once per class: a second wrapper for the same class
        // would be a second identity for the same snapshots.
        self::assertSame($wrapped, SnapshotIdentityWrapper::wrap(Snapshotting::class));
    }

    public function testAClassWithoutTheTraitIsLeftAlone(): void
    {
        self::assertSame(NotSnapshotting::class, SnapshotIdentityWrapper::wrap(NotSnapshotting::class));
    }

    /**
     * ✓ class_uses(Leaf extends Base) is [] — it answers "declared on
     * this class", not "has". A class inheriting MatchesSnapshots was
     * therefore left unwrapped, kept spatie's own directory and id, and
     * wrote its snapshots to the wrong files with nothing raised.
     */
    public function testTheTraitCountsWhereverItIsInherited(): void
    {
        self::assertSame([], class_uses(InheritedSnapshotting::class));

        $wrapped = SnapshotIdentityWrapper::wrap(InheritedSnapshotting::class);

        self::assertNotSame(InheritedSnapshotting::class, $wrapped);
        self::assertTrue(is_subclass_of($wrapped, InheritedSnapshotting::class));
    }

    public function testTheTraitCountsWhenAnotherTraitBringsIt(): void
    {
        $wrapped = SnapshotIdentityWrapper::wrap(ComposedSnapshotting::class);

        self::assertNotSame(ComposedSnapshotting::class, $wrapped);
        self::assertTrue(is_subclass_of($wrapped, ComposedSnapshotting::class));
    }

    public function testEachClassGetsItsOwnWrapperEvenSharingAParent(): void
    {
        // Two identities for the same snapshots would be worse than
        // none: the id is keyed on the wrapped class name.
        self::assertNotSame(
            SnapshotIdentityWrapper::wrap(Snapshotting::class),
            SnapshotIdentityWrapper::wrap(InheritedSnapshotting::class),
        );
    }

    public function testAFinalClassIsRefusedRatherThanCompiledIntoAFatal(): void
    {
        // A final test class is ordinary style. Extending one is an
        // uncatchable compile fatal, which would have died naming
        // SnapshotIdentity_<md5> for a decision made in the user's own
        // file — the shape D-119 refuses before eval().
        try {
            SnapshotIdentityWrapper::wrap(FinalSnapshotting::class);
            self::fail('a final class cannot be extended, so wrapping must be refused');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString(FinalSnapshotting::class, $refusal->getMessage());
            self::assertStringContainsString('final', $refusal->getMessage());
        }
    }

    /**
     * The generated getSnapshotId() had never been CALLED by anything:
     * every test above asserts the wrapper's shape, and the shape was
     * right. It reached nameWithDataSet(), which exists on real
     * PHPUnit's TestCase and did not exist on Crucible's at all — so
     * the first Pest suite to assert a snapshot would have died on
     * "Call to undefined method". Found by the generated-code type
     * tier, which reads the artifact rather than the shape.
     */
    public function testTheGeneratedIdentityResolvesForATestWithNoDataset(): void
    {
        SnapshotFileContext::set(__DIR__, 'ExampleTest');

        self::assertSame('ExampleTest__it_sums__0', $this->identityOf('it sums', null));
    }

    public function testEachDatasetRowGetsItsOwnSnapshotIdentity(): void
    {
        SnapshotFileContext::set(__DIR__, 'ExampleTest');

        // Same test, two rows: sharing one id would make the second row
        // assert against the first row's snapshot.
        $first  = $this->identityOf('it sums', '0');
        $second = $this->identityOf('it sums', '1');

        self::assertSame('ExampleTest__it_sums_with_data_set_#0__0', $first);
        self::assertSame('ExampleTest__it_sums_with_data_set_#1__0', $second);
        self::assertNotSame($first, $second);
    }

    public function testANamedDatasetRowCarriesItsLabel(): void
    {
        SnapshotFileContext::set(__DIR__, 'ExampleTest');

        self::assertSame('ExampleTest__it_sums_with_data_set_"whole_numbers"__0', $this->identityOf('it sums', 'whole numbers'));
    }

    /**
     * @param ?non-empty-string $dataset
     */
    private function identityOf(string $name, ?string $dataset): string
    {
        $wrapped = SnapshotIdentityWrapper::wrap(Snapshotting::class);

        $instance = new $wrapped();
        self::assertInstanceOf(TestCase::class, $instance);
        $instance->nameTest($name, $dataset);

        $id = (new ReflectionMethod($instance, 'getSnapshotId'))->invoke($instance);
        self::assertIsString($id);

        return $id;
    }
}
