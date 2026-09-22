<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Extension;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Extension\Artifact\Claim;
use LucianoPereira\Crucible\Extension\Duplication\DuplicationCheck;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function class_exists;
use function dirname;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * The duplication check against the real phpcpd-next (`phpcpd-main/`,
 * ORACLES.md), because a duplication detector stubbed out proves only
 * that the stub was called.
 */
#[CoversClass(DuplicationCheck::class)]
final class DuplicationCheckTest extends TestCase
{
    /** @var non-empty-string */
    private string $scratch = '/tmp';

    protected function setUp(): void
    {
        $autoload = dirname(__DIR__, 3) . '/phpcpd-main/vendor/autoload.php';

        if (!class_exists(\LucianoPereira\PhpcpdNext\Phpcpd::class) && is_file($autoload)) {
            require_once $autoload;
        }

        $this->scratch = sys_get_temp_dir() . '/crucible-duplication-' . getmypid();

        if (!is_dir($this->scratch)) {
            mkdir($this->scratch, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (['Alpha.php', 'Beta.php', 'Only.php'] as $file) {
            if (is_file($this->scratch . '/' . $file)) {
                unlink($this->scratch . '/' . $file);
            }
        }
    }

    public function testItNamesItselfWithoutNeedingTheTool(): void
    {
        self::assertSame('Duplication', (new DuplicationCheck())->label());
    }

    public function testAskingForTheCheckWithoutTheToolSaysWhichPackageIsMissing(): void
    {
        if (class_exists(\LucianoPereira\PhpcpdNext\Phpcpd::class)) {
            $this->markTestSkipped('phpcpd-next is installed, so its absence cannot be observed.');
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('phpcpd-next/phpcpd');

        (new DuplicationCheck(paths: [$this->scratch]))->inspect(new WorkingDirectory($this->scratch));
    }

    public function testCleanSourcePresentsNothingToJudge(): void
    {
        $this->requireOracle();

        $this->write('Only.php', 'Only');

        $claim = $this->claim(new DuplicationCheck(paths: [$this->scratch], minTokens: 40));

        self::assertSame(0, $claim->actual);
        self::assertSame(0, $claim->expected);
        self::assertNull($claim->detail, 'a clean project has no detail to print');
    }

    public function testDuplicationIsPresentedAsFactsRatherThanAVerdict(): void
    {
        $this->requireOracle();

        $this->write('Alpha.php', 'Alpha');
        $this->write('Beta.php', 'Beta');

        $claim = $this->claim(new DuplicationCheck(paths: [$this->scratch], minTokens: 40));

        self::assertGreaterThan(0, $claim->actual, 'two identical bodies are a clone');
        self::assertSame(0, $claim->expected);

        // The detail is the only part that tells anyone what to do.
        $detail = $claim->detail;

        if ($detail === null) {
            self::fail('duplication was found but nowhere named');
        }

        self::assertStringContainsString('Alpha.php', $detail);
        self::assertStringContainsString('Beta.php', $detail);
        self::assertStringContainsString('lines duplicated across', $detail);
    }

    public function testATolerantThresholdIsStillTheCallersToSet(): void
    {
        $this->requireOracle();

        $this->write('Alpha.php', 'Alpha');
        $this->write('Beta.php', 'Beta');

        $claim = $this->claim(new DuplicationCheck(paths: [$this->scratch], maxClones: 99, minTokens: 40));

        self::assertSame(99, $claim->expected, 'the plugin reports the threshold it was given');
        self::assertNull($claim->detail, 'within tolerance there is nothing to act on');
    }

    /** The artifact, narrowed: a check that presents anything else is a defect in itself. */
    private function claim(DuplicationCheck $check): Claim
    {
        $artifact = $check->inspect(new WorkingDirectory($this->scratch));

        if (!$artifact instanceof Claim) {
            self::fail('the duplication check must present a Claim');
        }

        return $artifact;
    }

    private function requireOracle(): void
    {
        if (!class_exists(\LucianoPereira\PhpcpdNext\Phpcpd::class)) {
            $this->markTestSkipped('No phpcpd-next install available — see ORACLES.md.');
        }
    }

    private function write(string $file, string $class): void
    {
        file_put_contents($this->scratch . '/' . $file, sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace CrucibleDuplicationFixture;
            final class %s
            {
                public function compute(array $rows, int $factor, string $label): array
                {
                    $out = [];
                    foreach ($rows as $key => $row) {
                        $value = (int) ($row['amount'] ?? 0);
                        $value = $value * $factor;
                        if ($value > 100) { $value = $value - 10; }
                        if ($value < 0) { $value = 0; }
                        $out[$key] = ['label' => $label, 'value' => $value, 'raw' => $row];
                    }
                    $total = 0;
                    foreach ($out as $entry) { $total += $entry['value']; }
                    $out['total'] = $total;
                    return $out;
                }
            }
            PHP, $class));
    }
}
