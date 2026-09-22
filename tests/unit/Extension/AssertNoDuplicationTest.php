<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Unit\Extension;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Extension\Duplication\AssertNoDuplication;
use LucianoPereira\Crucible\Extension\Duplication\DuplicationConstraint;
use LucianoPereira\Crucible\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

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
 * The in-test half of the duplication surface, against the real
 * phpcpd-next (`phpcpd-main/`, ORACLES.md).
 */
#[CoversClass(DuplicationConstraint::class)]
#[CoversClass(AssertNoDuplication::class)]
final class AssertNoDuplicationTest extends TestCase
{
    use AssertNoDuplication;

    /** @var non-empty-string */
    private string $scratch = '/tmp';

    protected function setUp(): void
    {
        $autoload = dirname(__DIR__, 3) . '/phpcpd-main/vendor/autoload.php';

        if (!class_exists(\LucianoPereira\PhpcpdNext\Phpcpd::class) && is_file($autoload)) {
            require_once $autoload;
        }

        $this->scratch = sys_get_temp_dir() . '/crucible-assert-duplication-' . getmypid();

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

    public function testCleanSourcePasses(): void
    {
        $this->requireOracle();

        $this->write('Only.php', 'Only');

        $this->assertNoDuplication([$this->scratch], minTokens: 40);
    }

    /**
     * The control. Without it the test above proves only that detection
     * ran, not that it can say no.
     */
    public function testAClonedBodyFailsAndIsNamed(): void
    {
        $this->requireOracle();

        $this->write('Alpha.php', 'Alpha');
        $this->write('Beta.php', 'Beta');

        try {
            $this->assertNoDuplication([$this->scratch], minTokens: 40);
        } catch (AssertionFailedError $failure) {
            $message = $failure->getMessage();

            self::assertStringContainsString('contains no duplicated code', $message);
            self::assertStringContainsString('clone', $message);
            self::assertStringContainsString('Alpha.php', $message);
            self::assertStringContainsString('Beta.php', $message);
            self::assertStringContainsString('↔', $message);

            return;
        }

        self::fail('two identical bodies must fail the assertion');
    }

    /** The caller's own message survives in front of the detail. */
    public function testTheCallersMessageIsKept(): void
    {
        $this->requireOracle();

        $this->write('Alpha.php', 'Alpha');
        $this->write('Beta.php', 'Beta');

        try {
            $this->assertNoDuplication([$this->scratch], minTokens: 40, message: 'the app must stay dry');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('the app must stay dry', $failure->getMessage());

            return;
        }

        self::fail('two identical bodies must fail the assertion');
    }

    public function testTheAbsentToolSaysWhichPackageIsMissing(): void
    {
        if (class_exists(\LucianoPereira\PhpcpdNext\Phpcpd::class)) {
            $this->markTestSkipped('phpcpd-next is installed, so its absence cannot be observed.');
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('phpcpd-next/phpcpd');

        $this->assertNoDuplication([$this->scratch]);
    }

    public function testTheConstraintRefusesWhatIsNotACloneMap(): void
    {
        $constraint = new DuplicationConstraint();

        self::assertFalse($constraint->evaluate('not a clone map', '', true));
        self::assertSame('contains no duplicated code', $constraint->toString());
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
            namespace CrucibleAssertDuplicationFixture;
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
