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

    public function testAFileWithoutUsesResolvesToNothing(): void
    {
        self::assertSame([], UsesResolver::classRefs("<?php\n\ntest('x', function (): void {});\n"));
    }
}
