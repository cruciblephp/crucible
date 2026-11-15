<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Coverage;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\PostRunReport;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\CoverageTarget;
use LucianoPereira\Crucible\Coverage\CoverageTargetSet;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function is_string;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * Item 04 of the coverage handoff: named targets carry their own floor,
 * while bare paths keep the pooled `--min` rule.
 */
#[CoversClass(CoverageTargetSet::class)]
#[CoversClass(CoverageTarget::class)]
final class CoverageTargetSetTest extends TestCase
{
    private const string ROOT = '/project';

    public function testEachNamedTargetIsJudgedOnItsOwnSlice(): void
    {
        $data = new CoverageData([
            '/project/modules/Auth/A.php'      => [1 => 1, 2 => 1],
            '/project/modules/AuthExtra/B.php' => [1 => -1],
            '/project/modules/Billing/C.php'   => [1 => -1, 2 => -1],
        ]);

        $targets = $this->resolve(
            [],
            ['Auth=modules/Auth', 'Billing=modules/Billing'],
            ['Auth:100'],
        );

        $breaches = (new PostRunReport())->coverageTargetFloors($data, $targets, 50.0);

        self::assertCount(1, $breaches, 'Auth holds 100, Billing falls under the fallback 50');
        self::assertStringContainsString('Coverage target "Billing"', $breaches[0]);
        self::assertStringContainsString('0.00% of 2 executable lines', $breaches[0]);
        self::assertFalse($targets->judgesPooledTotal(), 'a named run judges each target, not the aggregate');
    }

    public function testTheTargetsOwnFloorWinsOverTheOption(): void
    {
        $data = new CoverageData(['/project/modules/Auth/A.php' => [1 => 1, 2 => 1, 3 => 1, 4 => -1]]);

        $targets = $this->resolve(
            [new CoverageTarget('Auth', 'modules/Auth', 100.0)],
            [],
            ['Auth:25'],
        );

        $breaches = (new PostRunReport())->coverageTargetFloors($data, $targets, 10.0);

        self::assertCount(1, $breaches, '75% passes 25 and 10, but not the configured 100');
        self::assertStringContainsString('at least 100.00% required', $breaches[0]);
    }

    public function testAnUnknownFloorIsRefusedBeforeTheRun(): void
    {
        $refusal = CoverageTargetSet::resolve([], [], ['Missing:80'], new WorkingDirectory(self::ROOT));

        self::assertIsString($refusal);
        self::assertStringContainsString('unknown target "Missing"', $refusal);
    }

    public function testMalformedFloorsAreRefused(): void
    {
        $targets = ['Auth=modules/Auth'];
        $root    = new WorkingDirectory(self::ROOT);

        foreach (['Auth:', ':80', 'Auth:abc', 'Auth:101', 'modules/Auth:100'] as $bad) {
            self::assertIsString(CoverageTargetSet::resolve([], $targets, [$bad], $root), $bad . ' must be refused');
        }
    }

    public function testAFloorsFileIsTheSameAsTheCommandLine(): void
    {
        $path = sys_get_temp_dir() . '/crucible-floors-' . bin2hex(random_bytes(4)) . '.txt';

        file_put_contents($path, "# module floors\n\nAuth:100\nBilling,Activity:80\n");

        try {
            $fromFile = $this->resolve([], ['Auth=modules/Auth', 'Billing=modules/Billing', 'Activity=modules/Activity'], ['@' . $path]);
            $inline   = $this->resolve([], ['Auth=modules/Auth', 'Billing=modules/Billing', 'Activity=modules/Activity'], ['Auth:100', 'Billing,Activity:80']);

            $same = static fn(CoverageTargetSet $set): array => array_map(
                static fn(CoverageTarget $target): array => [$target->name, $target->minimum],
                $set->targets(),
            );

            self::assertSame($same($inline), $same($fromFile));
        } finally {
            @unlink($path);
        }
    }

    public function testAMissingFloorsFileIsRefused(): void
    {
        $refusal = CoverageTargetSet::resolve([], ['Auth=modules/Auth'], ['@no/such/floors.txt'], new WorkingDirectory(self::ROOT));

        self::assertIsString($refusal);
        self::assertStringContainsString('cannot be read', $refusal);
    }

    public function testATargetWithNothingMeasuredFailsItsFloorButNotAZeroFloor(): void
    {
        $data    = new CoverageData(['/project/modules/Auth/A.php' => [1 => 1]]);
        $targets = $this->resolve([], ['Empty=modules/Empty'], ['Empty:50']);

        $breaches = (new PostRunReport())->coverageTargetFloors($data, $targets, null);

        self::assertCount(1, $breaches);
        self::assertStringContainsString('cannot be judged', $breaches[0]);

        $zero = $this->resolve([], ['Empty=modules/Empty'], ['Empty:0']);

        self::assertSame([], (new PostRunReport())->coverageTargetFloors($data, $zero, null));
    }

    public function testTwoTargetsUnderTheirFloorsAreBothReported(): void
    {
        $data = new CoverageData([
            '/project/modules/Auth/A.php'    => [1 => -1],
            '/project/modules/Billing/B.php' => [1 => -1],
        ]);

        $targets  = $this->resolve([], ['Auth=modules/Auth', 'Billing=modules/Billing'], ['Auth:100', 'Billing:100']);
        $breaches = (new PostRunReport())->coverageTargetFloors($data, $targets, null);

        self::assertCount(2, $breaches);
        self::assertStringContainsString('"Auth"', $breaches[0]);
        self::assertStringContainsString('"Billing"', $breaches[1]);
        self::assertSame(['/project/modules/Auth/', '/project/modules/Billing/'], $targets->paths());
    }

    public function testNamesCompareCaseInsensitively(): void
    {
        $data    = new CoverageData(['/project/modules/Auth/A.php' => [1 => -1]]);
        $targets = $this->resolve([], ['auth=modules/Auth'], ['AUTH:100']);

        self::assertSame('auth', $targets->targets()[0]->name);
        self::assertCount(1, (new PostRunReport())->coverageTargetFloors($data, $targets, null));
    }

    public function testABarePathKeepsThePooledRule(): void
    {
        $targets = $this->resolve([], ['modules/Auth'], []);

        self::assertTrue($targets->judgesPooledTotal());
        self::assertSame(['/project/modules/Auth/'], $targets->paths());
        self::assertNull($targets->targets()[0]->name);
    }

    public function testAConfigTargetBecomesANamedSlice(): void
    {
        $targets = $this->resolve([new CoverageTarget('Auth', 'modules/Auth', 100.0)], [], []);

        self::assertSame(['/project/modules/Auth/'], $targets->paths());
        self::assertSame(100.0, $targets->targets()[0]->minimum);
    }

    /**
     * @param list<CoverageTarget>   $configured
     * @param list<non-empty-string> $cliTargets
     * @param list<non-empty-string> $floors
     */
    private function resolve(array $configured, array $cliTargets, array $floors): CoverageTargetSet
    {
        $resolved = CoverageTargetSet::resolve($configured, $cliTargets, $floors, new WorkingDirectory(self::ROOT));

        if (is_string($resolved)) {
            self::fail($resolved);
        }

        return $resolved;
    }
}
