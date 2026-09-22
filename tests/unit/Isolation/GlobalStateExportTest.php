<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Isolation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Isolation\GlobalStateExport;

use function base64_encode;

/**
 * What #[PreserveGlobalState(true)] carries into an isolated worker.
 */
#[CoversClass(GlobalStateExport::class)]
final class GlobalStateExportTest extends TestCase
{
    public function testAGlobalSurvivesTheRoundTripThroughTheManifest(): void
    {
        $GLOBALS['crucibleExportFixture'] = ['nested' => ['deep' => 1]];

        $restored = GlobalStateExport::fromJson(GlobalStateExport::capture()->toJson());

        self::assertInstanceOf(GlobalStateExport::class, $restored);

        unset($GLOBALS['crucibleExportFixture']);

        $restored->restore();

        $this->assertSame(['nested' => ['deep' => 1]], $GLOBALS['crucibleExportFixture'] ?? null);

        unset($GLOBALS['crucibleExportFixture']);
    }

    public function testANullGlobalRoundTripsAsAValueRatherThanAsAbsent(): void
    {
        $GLOBALS['crucibleNullFixture'] = null;

        $restored = GlobalStateExport::fromJson(GlobalStateExport::capture()->toJson());

        self::assertInstanceOf(GlobalStateExport::class, $restored);

        unset($GLOBALS['crucibleNullFixture']);
        $restored->restore();

        // Present and null, not missing: the value wrapper exists so
        // these two cannot be confused on the way back.
        $this->assertArrayHasKey('crucibleNullFixture', $GLOBALS);
        $this->assertNull($GLOBALS['crucibleNullFixture']);

        unset($GLOBALS['crucibleNullFixture']);
    }

    public function testWhatNoSerializerCanCarryIsSkippedByNameNotFatal(): void
    {
        $GLOBALS['crucibleClosureFixture'] = static fn(): int => 1;

        $export = GlobalStateExport::capture();

        $this->assertContains('$crucibleClosureFixture', $export->skipped);
        $this->assertArrayNotHasKey('crucibleClosureFixture', $export->globals);

        unset($GLOBALS['crucibleClosureFixture']);
    }

    public function testSuperglobalsAreLeftToTheProcessTheyDescribe(): void
    {
        $export = GlobalStateExport::capture();

        foreach (['GLOBALS', '_ENV', '_SERVER', '_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST'] as $name) {
            $this->assertArrayNotHasKey($name, $export->globals, $name . ' describes the worker, not the parent');
        }
    }

    public function testOnlyUserConstantsTravelAndRestoringNeverRedefines(): void
    {
        $export = GlobalStateExport::capture();

        $this->assertArrayNotHasKey('PHP_EOL', $export->constants, 'an internal constant belongs to the runtime');

        $restored = GlobalStateExport::fromJson($export->toJson());

        self::assertInstanceOf(GlobalStateExport::class, $restored);

        // Twice, because a constant that already exists in the worker
        // must be left alone rather than redefined (which would fatal).
        $restored->restore();
        $restored->restore();

        $this->assertTrue(true);
    }

    public function testGarbageFromTheManifestIsIgnoredRatherThanTrusted(): void
    {
        $this->assertNull(GlobalStateExport::fromJson(null));
        $this->assertNull(GlobalStateExport::fromJson(''));
        $this->assertNull(GlobalStateExport::fromJson('not base64 !!!'));
        $this->assertNull(GlobalStateExport::fromJson(base64_encode('plain string')));
    }
}
