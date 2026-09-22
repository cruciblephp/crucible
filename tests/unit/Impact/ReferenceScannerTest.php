<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ReferenceScanner;

#[CoversClass(ReferenceScanner::class)]
final class ReferenceScannerTest extends TestCase
{
    /**
     * @param non-empty-string $name
     */
    private function assertReferences(string $name, string $source): void
    {
        self::assertContains($name, (new ReferenceScanner())->referencesIn($source));
    }

    /**
     * @param non-empty-string $name
     */
    private function assertNoReference(string $name, string $source): void
    {
        self::assertNotContains($name, (new ReferenceScanner())->referencesIn($source));
    }

    public function testUseImportsResolveFully(): void
    {
        $this->assertReferences('App\Service\Mailer', '<?php use App\Service\Mailer; new Mailer();');
    }

    public function testAliasesResolveToTheImportedName(): void
    {
        $this->assertReferences(
            'App\Service\Mailer',
            '<?php use App\Service\Mailer as Post; new Post();',
        );
    }

    public function testGroupUseExpandsThePrefix(): void
    {
        $source = '<?php use App\{Alpha, Beta as B, Sub\Gamma};';

        $this->assertReferences('App\Alpha', $source);
        $this->assertReferences('App\Beta', $source);
        $this->assertReferences('App\Sub\Gamma', $source);
    }

    public function testUnimportedNamesResolveAgainstTheNamespace(): void
    {
        $this->assertReferences(
            'App\Domain\Money',
            '<?php namespace App\Domain; function f(): Money { return new Money(); }',
        );
    }

    public function testFullyQualifiedNamesPassThrough(): void
    {
        $this->assertReferences('App\Kernel', '<?php namespace X; \App\Kernel::boot();');
    }

    public function testQualifiedNamesJoinTheNamespace(): void
    {
        $this->assertReferences(
            'App\Support\Str\Builder',
            '<?php namespace App\Support; Str\Builder::make();',
        );
    }

    public function testExtendsImplementsAndAttributesAreReferences(): void
    {
        $source = '<?php namespace App; #[Route] final class C extends Base implements Contract {}';

        $this->assertReferences('App\Route', $source);
        $this->assertReferences('App\Base', $source);
        $this->assertReferences('App\Contract', $source);
    }

    public function testDeclarationsAreNotReferences(): void
    {
        $this->assertNoReference('App\C', '<?php namespace App; final class C {}');
    }

    public function testMemberAccessIsNotAReference(): void
    {
        $source = '<?php namespace App; $x->Save(); Repo::Fetch();';

        $this->assertNoReference('App\Save', $source);
        $this->assertNoReference('App\Fetch', $source);
        $this->assertReferences('App\Repo', $source);
    }

    public function testLowercaseIdentifiersAreNotCandidates(): void
    {
        $this->assertNoReference('App\strlen', '<?php namespace App; strlen("x");');
    }

    public function testGlobalFallbackOnlyWithoutANamespace(): void
    {
        $this->assertReferences('Mailer', '<?php new Mailer();');
        $this->assertNoReference('Mailer', '<?php namespace App; new Mailer();');
    }

    public function testUseFunctionImportsAreSkipped(): void
    {
        $this->assertNoReference('App\Support\render', '<?php use function App\Support\render;');
    }

    public function testClosureCaptureListsAreNotImports(): void
    {
        $source = '<?php namespace App; $f = function () use ($x) { return new Widget(); };';

        $this->assertReferences('App\Widget', $source);
    }

    public function testTraitUseInsideAClassIsAReference(): void
    {
        $this->assertReferences(
            'App\Concerns\Sortable',
            '<?php namespace App; use App\Concerns\Sortable; final class C { use Sortable; }',
        );
    }
}
