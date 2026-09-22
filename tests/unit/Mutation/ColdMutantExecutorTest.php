<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\ColdMutantExecutor;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutationAutoloader;
use LucianoPereira\Crucible\Mutation\MutationOutcome;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The cold path end to end, spawning the real `crucible --worker` against a
 * throwaway project — and deliberately NOT guarded by pcntl/posix,
 * because this is the path that must work where the warm one cannot.
 */
#[CoversClass(ColdMutantExecutor::class)]
#[CoversClass(MutationAutoloader::class)]
#[CoversClass(Mutant::class)]
final class ColdMutantExecutorTest extends TestCase
{
    private const string COVERING_ID = 'tests/CalculatorTest.php::testAddsToFour';

    /** @var non-empty-string */
    private string $dir;

    protected function setUp(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $dir      = sys_get_temp_dir() . '/crucible-cold-' . uniqid();

        mkdir($dir . '/src', 0o777, true);
        mkdir($dir . '/tests', 0o777, true);

        $this->write($dir . '/bootstrap.php', <<<PHP
            <?php
            require '{$repoRoot}/vendor/autoload.php';
            spl_autoload_register(static function (string \$class): void {
                \$prefix = 'ColdFixture\\\\';
                if (str_starts_with(\$class, \$prefix)) {
                    \$file = __DIR__ . '/src/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';
                    if (is_file(\$file)) {
                        require \$file;
                    }
                }
            });
            PHP);

        $this->write($dir . '/src/Calculator.php', <<<'PHP'
            <?php
            namespace ColdFixture;
            final class Calculator
            {
                public static function add(int $a, int $b): int
                {
                    return $a + $b;
                }
            }
            PHP);

        $this->write($dir . '/tests/CalculatorTest.php', <<<'PHP'
            <?php
            namespace ColdFixture;
            use LucianoPereira\Crucible\Framework\TestCase;
            final class CalculatorTest extends TestCase
            {
                public function testAddsToFour(): void
                {
                    self::assertSame(4, Calculator::add(2, 2));
                }
            }
            PHP);

        $this->write($dir . '/crucible.php', <<<'PHP'
            <?php
            use LucianoPereira\Crucible\Configuration\Crucible;
            return Crucible::configure()->bootstrap('bootstrap.php')->testSuite('cold', 'tests');
            PHP);

        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->dir);
    }

    private function executor(): ColdMutantExecutor
    {
        return new ColdMutantExecutor(dirname(__DIR__, 3) . '/crucible', $this->dir . '/crucible.php', new WorkingDirectory($this->dir));
    }

    private function mutant(string $body): Mutant
    {
        $source = "<?php\nnamespace ColdFixture;\n"
            . "final class Calculator { public static function add(int \$a, int \$b): int { {$body} } }\n";

        return new Mutant($this->dir . '/src/Calculator.php', 'ColdFixture\\Calculator', 1, 'test', $source);
    }

    public function testAMutantACoveringTestCatchesIsKilledColdly(): void
    {
        $verdict = $this->executor()->execute($this->mutant('return $a - $b;'), [self::COVERING_ID]);

        self::assertSame(MutationOutcome::Killed, $verdict->outcome);
        self::assertSame(self::COVERING_ID, $verdict->killedBy);
    }

    public function testAMutantTheTestsCannotSeeIsEscapedColdly(): void
    {
        // Still returns 4, so the covering test passes — the mutation the
        // suite is blind to.
        $verdict = $this->executor()->execute($this->mutant('return $b + $a;'), [self::COVERING_ID]);

        self::assertSame(MutationOutcome::Escaped, $verdict->outcome);
    }

    public function testAMutantNoTestCoversIsNotCovered(): void
    {
        $verdict = $this->executor()->execute($this->mutant('return $a - $b;'), []);

        self::assertSame(MutationOutcome::NotCovered, $verdict->outcome);
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($path, $contents . "\n");
    }

    private function deleteTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->deleteTree($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
