<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\PHPStan;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\PHPStan\UsesResolver;

use function array_fill;

#[CoversClass(UsesResolver::class)]
final class UsesResolverTest extends TestCase
{
    public function testResolvesImportsAliasesAndBothCallSpellings(): void
    {
        $source = <<<'PHP'
            <?php

            use App\Testing\SuiteCase;
            use App\Testing\Brews as Coffee;

            \uses(SuiteCase::class, Coffee::class);
            uses(\Vendor\Direct::class);
            PHP;

        self::assertSame(
            ['App\Testing\SuiteCase', 'App\Testing\Brews', 'Vendor\Direct'],
            UsesResolver::classRefs($source),
        );
    }

    public function testGroupAndCommaListImportsResolveAsPhpReadsThem(): void
    {
        // Read by hand, these fell through to the namespace prefix:
        // Tests\Feature\TestCase, a class that does not exist, typing
        // every closure's $this wrong.
        $source = <<<'PHP'
            <?php

            namespace Tests\Feature;

            use Tests\{TestCase, Support\Brews as Coffee, function helper, const LIMIT,};
            use Other\Base, Vendor\Traits\Milk as Dairy;
            use function Tests\format;

            uses(TestCase::class, Coffee::class, Base::class, Dairy::class);
            PHP;

        self::assertSame(
            ['Tests\TestCase', 'Tests\Support\Brews', 'Other\Base', 'Vendor\Traits\Milk'],
            UsesResolver::classRefs($source),
        );
    }

    public function testAScopedRegistrationReadsGroupImportsToo(): void
    {
        $source = <<<'PHP'
            <?php

            use Tests\{TestCase, Concerns\RefreshesDatabase};

            pest()->extend(TestCase::class)->use(RefreshesDatabase::class)->in('Feature');
            PHP;

        self::assertSame(
            [['names' => ['Tests\TestCase', 'Tests\Concerns\RefreshesDatabase'], 'globs' => ['Feature']]],
            UsesResolver::scopedRegistrations($source),
        );
    }

    public function testNamespaceRelativeAndStringLiteralArguments(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App\Tests;

            uses(Support\LocalCase::class, 'App\Other\StringCase');
            PHP;

        self::assertSame(
            ['App\Tests\Support\LocalCase', 'App\Other\StringCase'],
            UsesResolver::classRefs($source),
        );
    }

    public function testForeignUsesSpellingsAreIgnored(): void
    {
        $source = <<<'PHP'
            <?php

            use App\Thing;

            $registry->uses(Thing::class);
            Registry::uses(Thing::class);

            function uses(string $name): void {}
            PHP;

        self::assertSame([], UsesResolver::classRefs($source));
    }

    public function testAScopedRegistrationIsTheWholeChainAndOnlyAChainWithIn(): void
    {
        $source = <<<'PHP'
            <?php

            namespace Tests;

            use Tests\Support\Brews;

            pest()->extend(TestCase::class)->use(Brews::class)->group('slow')->in('Feature', "Unit");
            uses(Legacy\BaseCase::class)->in('Legacy');
            \pest()->extend(\Other\SuiteCase::class)->in('Quoted');
            pest()->extend(Bare::class);
            pest()->beforeEach(function (): void { strlen(trim(' x ')); })->in('Hooks');
            $registry->pest()->extend(Nope::class)->in('Nope');
            uses(Api::class)
                ->in('Api');
            PHP;

        self::assertSame([
            // extend() and use() carry names; group() does not.
            ['names' => ['Tests\TestCase', 'Tests\Support\Brews'], 'globs' => ['Feature', 'Unit']],
            ['names' => ['Tests\Legacy\BaseCase'], 'globs' => ['Legacy']],
            ['names' => ['Other\SuiteCase'], 'globs' => ['Quoted']],
            // A chain without in() is bare (D-033) and not returned; the
            // hook's own parentheses do not end its chain early.
            ['names' => [], 'globs' => ['Hooks']],
            // A method named pest() is somebody else's; a chain may span lines.
            ['names' => ['Tests\Api'], 'globs' => ['Api']],
        ], UsesResolver::scopedRegistrations($source));
    }

    public function testOnlyClassReferencesCountAndEachNamespaceResolvesItsOwn(): void
    {
        $source = <<<'PHP'
            <?php

            namespace First;

            use function Other\helper;
            use const Other\LIMIT;

            uses('NoBackslash', Base::class, helper(...), LIMIT);

            namespace Second;

            uses(Base::class);
            PHP;

        self::assertSame(['First\Base', 'Second\Base'], UsesResolver::classRefs($source));
    }

    public function testASourceCutOffMidStatementReadsWhatItCanAndNeverFails(): void
    {
        // The extension reads files while they are being typed: every
        // statement can end anywhere, and none of it may throw.
        $read = [];

        foreach ([
            'namespace', 'use', 'use function', 'use Tests\\{', 'use Tests\\{TestCase', 'use Tests\\TestCase as', 'use A,',
            'uses(', 'uses(A::', 'pest()->extend(A::class)->', 'pest()->extend(A::class)->in(',
        ] as $cut) {
            $source = "<?php\nnamespace App;\n" . $cut;
            $read[] = [...UsesResolver::classRefs($source), ...UsesResolver::scopedRegistrations($source)];
        }

        self::assertSame(array_fill(0, 11, []), $read);

        // What is complete up to the cut still reads.
        self::assertSame(['App\\A'], UsesResolver::classRefs("<?php\nnamespace App;\nuses(A::class"));
        self::assertSame(
            [['names' => [], 'globs' => ['x']]],
            UsesResolver::scopedRegistrations("<?php\npest()->in('x')->extend"),
        );
    }

    public function testAFileWithoutUsesResolvesToNothing(): void
    {
        self::assertSame([], UsesResolver::classRefs("<?php\n\ntest('x', function (): void {});\n"));
    }
}
