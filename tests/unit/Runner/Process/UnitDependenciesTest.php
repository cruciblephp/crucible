<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\Process\UnitDependencies;

/**
 * The declaration-only rule (D-180): a test file whose top level only
 * declares can be loaded alone, so a worker may discover just its own
 * units. Anything that runs at load time may be what another file relies
 * on, and sends the workers to full discovery.
 */
#[CoversClass(UnitDependencies::class)]
final class UnitDependenciesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function declarationsOnly(): array
    {
        return [
            'a PHPUnit class'          => ["<?php\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse PHPUnit\\Framework\\TestCase;\n\n#[\\PHPUnit\\Framework\\Attributes\\CoversNothing]\nfinal class FooTest extends TestCase\n{\n    public function testIt(): void {}\n}\n"],
            'a named function'         => ["<?php\nfunction helper(): int { return 1; }\nabstract class Base {}\n"],
            'an enum and an interface' => ["<?php\nenum Kind: string { case A = 'a'; }\ninterface Shape {}\ntrait Shared {}\n"],
            'Pest registration calls'  => ["<?php\nuses(Tests\\TestCase::class);\n\nbeforeEach(function () { \$this->x = 1; });\n\n\\it('works', function () {\n    expect(true)->toBeTrue();\n})->with([1, 2]);\n\ndescribe('group', function () {\n    test('inner', fn () => null);\n});\n"],
            'Crucible checks'          => ["<?php\n\\check(fn(): string => 'x')->toBe('x');\n"],
            'a closing tag'            => ["<?php\nfinal class A {}\n?>\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loadTimeEffects(): array
    {
        return [
            'a define()'                 => ["<?php\ndefine('SHARED', 1);\nfinal class A {}\n"],
            'a global'                   => ["<?php\n\$GLOBALS['shared'] = 1;\n"],
            'a top-level variable'       => ["<?php\n\$shared = 1;\n"],
            'a require'                  => ["<?php\nrequire_once __DIR__ . '/helpers.php';\n"],
            'an ini_set()'               => ["<?php\nini_set('memory_limit', '1G');\n"],
            'a top-level const'          => ["<?php\nconst SHARED = 1;\n"],
            'a braced namespace'         => ["<?php\nnamespace App {\n    final class A {}\n}\n"],
            'inline output'              => ["<?php final class A {} ?>\nhello\n"],
            'a call that is not Pest'    => ["<?php\nclass_alias(A::class, 'B');\n"],
            'a registration-named const' => ["<?php\ntest;\n"],
        ];
    }

    #[DataProvider('declarationsOnly')]
    public function testADeclaringFileCanBeLoadedAlone(string $source): void
    {
        self::assertTrue(UnitDependencies::isDeclarationOnly($source));
    }

    #[DataProvider('loadTimeEffects')]
    public function testALoadTimeEffectSendsTheWorkersToFullDiscovery(string $source): void
    {
        self::assertFalse(UnitDependencies::isDeclarationOnly($source));
    }
}
