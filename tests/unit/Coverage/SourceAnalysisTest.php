<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Coverage;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Coverage\SourceAnalysis;
use LucianoPereira\Crucible\Coverage\SourceAnalysisCache;
use LucianoPereira\Crucible\Coverage\SourceClass;
use LucianoPereira\Crucible\Coverage\SourceMethod;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_keys;
use function clearstatcache;
use function file_put_contents;
use function getmypid;
use function sys_get_temp_dir;
use function time;
use function touch;
use function unlink;

/**
 * Method boundaries and cyclomatic complexity read from tokens. The
 * complexity is the spec's own definition (sebastian/complexity), so
 * these cases pin the places the two could disagree — reserved words
 * used as member names, `default:` as a named argument, an arrow
 * function inside a match, and the tokens that open a brace or bracket
 * without closing one.
 */
#[CoversClass(SourceAnalysis::class)]
#[CoversClass(SourceAnalysisCache::class)]
#[CoversClass(SourceClass::class)]
#[CoversClass(SourceMethod::class)]
final class SourceAnalysisTest extends TestCase
{
    private function complexityOf(string $body): int
    {
        $analysis = SourceAnalysis::parse("<?php\nnamespace N;\nclass C {\n    function m(\$a) {\n" . $body . "\n    }\n}\n");

        return $analysis->classes['N\\C']->methods['m']->complexity;
    }

    public function testAStraightLineMethodIsOne(): void
    {
        $this->assertSame(1, $this->complexityOf('return 1;'));
    }

    public function testEachBranchAddsOne(): void
    {
        $this->assertSame(2, $this->complexityOf('if ($a) { return 1; } return 2;'));
        $this->assertSame(3, $this->complexityOf('if ($a && $a) { return 1; } return 2;'));
        $this->assertSame(2, $this->complexityOf('foreach ($a as $x) { echo $x; }'));
        $this->assertSame(2, $this->complexityOf('try { return 1; } catch (\Throwable $e) { return 2; }'));
    }

    public function testTernariesCountButNullableTypesAndCoalesceDoNot(): void
    {
        $this->assertSame(2, $this->complexityOf('return $a ? 1 : 2;'));
        $this->assertSame(2, $this->complexityOf('return $a ?: 2;'));

        // ?? is not a branch in the spec's set, and `?int` is a type.
        $this->assertSame(1, $this->complexityOf('return $a ?? 2;'));
        $this->assertSame(1, $this->complexityOf('$b = function (?int $c): ?string { return null; }; return $b;'));
    }

    public function testMatchCountsItsArmsAndSwitchCountsItsDefault(): void
    {
        $this->assertSame(4, $this->complexityOf('return match ($a) { 1 => "a", 2 => "b", default => "c" };'));

        // The => of an array literal inside an arm is not another arm,
        // and neither is an arrow function's.
        $this->assertSame(3, $this->complexityOf('return match ($a) { 1 => ["k" => "v"], default => [] };'));
        $this->assertSame(3, $this->complexityOf('return match ($a) { 1 => fn (): int => 2, default => 3 };'));

        // A switch's default is a case; a do-while's `while` is the
        // loop `do` already opened.
        $this->assertSame(4, $this->complexityOf('switch ($a) { case 1: return 1; case 2: return 2; default: return 3; }'));
        $this->assertSame(1, $this->complexityOf('do { $a++; } while ($a < 3); return $a;'));
    }

    public function testReservedWordsUsedAsNamesOpenNoBranch(): void
    {
        // PHP allows these as member names; none of them is a branch.
        $this->assertSame(1, $this->complexityOf('return \\N\\Other::for($a);'));
        $this->assertSame(1, $this->complexityOf('return \\N\\Other::default();'));
        $this->assertSame(1, $this->complexityOf('return $this->run(default: 1);'));
    }

    public function testBoundariesSurviveInterpolationAndAttributes(): void
    {
        // "{$x}" and #[Attr] open a brace and a bracket with a token but
        // close with a bare one, which drifts the depth if unhandled —
        // and a drifting depth loses every later method.
        $analysis = SourceAnalysis::parse(<<<'PHP'
            <?php
            namespace N;
            #[Attr(1)]
            class C {
                #[Attr]
                public function first(string $x): string { return "a{$x}b"; }
                public function second(): int { return 1; }
            }
            PHP);

        $this->assertSame(['N\\C'], array_keys($analysis->classes));
        $this->assertSame(['first', 'second'], array_keys($analysis->classes['N\\C']->methods));
    }

    public function testAMethodReportsItsCoverageAndCrapOverTheLineMap(): void
    {
        $method = new SourceMethod('m', 10, 13, 4);

        // -2 is dead code: not executable, so not in the denominator.
        $lines = [10 => 1, 11 => 0, 12 => -2, 13 => 1];

        $this->assertEqualsWithDelta(66.67, $method->coverage($lines), 0.01);
        $this->assertEqualsWithDelta(4 ** 2 * (1 - 0.6667) ** 3 + 4, $method->crap($lines), 0.01);

        // The two ends of the spec's curve: nothing covered squares the
        // complexity, essentially-covered leaves it alone.
        $this->assertSame(20.0, $method->crap([10 => 0, 11 => 0]));
        $this->assertSame(4.0, $method->crap([10 => 1, 11 => 1, 13 => 1]));
    }

    public function testTheAnalysisCacheAnswersTwiceAndNoticesAnEdit(): void
    {
        $file = sys_get_temp_dir() . '/crucible-analysis-cache-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace N;\nclass C { function one() { return 1; } }\n");

        SourceAnalysisCache::clear();

        $first  = SourceAnalysis::of($file);
        $second = SourceAnalysis::of($file);

        // The same parse, not a second one.
        $this->assertSame($first, $second);
        $this->assertSame(1, SourceAnalysisCache::count());

        // An edited file is a different entry, never a stale answer.
        // touch() with a later mtime, since a rewrite inside the same
        // second would otherwise key identically.
        file_put_contents($file, "<?php\nnamespace N;\nclass C { function one() { return 1; } function two() { return 2; } }\n");
        touch($file, time() + 5);
        clearstatcache(true, $file);

        $third = SourceAnalysis::of($file);

        $this->assertNotSame($first, $third);
        $this->assertSame(['one', 'two'], array_keys($third->classes['N\\C']->methods));

        unlink($file);
        SourceAnalysisCache::clear();
    }

    public function testWarmingParsesEveryFileItIsGivenAndSkipsWhatIsNotThere(): void
    {
        $file = sys_get_temp_dir() . '/crucible-analysis-warm-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace N;\nclass C { function one() { return 1; } }\n");

        SourceAnalysisCache::clear();

        $this->assertSame(1, SourceAnalysis::warm([$file, $file . '.missing']));
        $this->assertSame(1, SourceAnalysisCache::count());

        unlink($file);
        SourceAnalysisCache::clear();
    }

    public function testAMethodWithNoExecutableLineIsNotDividedByZero(): void
    {
        $method = new SourceMethod('m', 10, 13, 1);

        $this->assertSame(0.0, $method->coverage([]));
        $this->assertSame(2.0, $method->crap([]));
    }
}
