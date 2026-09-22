<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\PrinterSelection;
use LucianoPereira\Crucible\Dialect\Pest\ScopeRegistration;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\Counts;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\Greets;
use LucianoPereira\Crucible\Tests\Fixtures\TraitShapes\PlainBase;

/**
 * One pest()/uses() chain, tested directly.
 *
 * It read 0% covered while being exercised on every run: the builder
 * constructs it during DISCOVERY, and the per-test coverage window
 * (D-041) opens after that. So "executed" and "asserted" had come
 * apart — the file ran constantly and nothing checked its answers.
 */
#[CoversClass(ScopeRegistration::class)]
final class ScopeRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../_fixtures/trait-shapes/shapes.php';
    }

    private function config(): ScopeRegistration
    {
        return new ScopeRegistration('/project/tests', fromConfigFile: true);
    }

    public function testExtendTakesTheClassAndRefusesASecondOne(): void
    {
        $registration = $this->config();

        self::assertSame($registration, $registration->extend(PlainBase::class));
        self::assertSame(PlainBase::class, $registration->class);

        // Two base classes is not a merge, it is a contradiction, and
        // the message names both so the author can see which won.
        try {
            $registration->extend(TestCase::class);
            self::fail('a chain that already extends must refuse a second class');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString(PlainBase::class, $refusal->getMessage());
            self::assertStringContainsString(TestCase::class, $refusal->getMessage());
        }
    }

    public function testUseAccumulatesTraitsAndRefusesWhatIsNotOne(): void
    {
        $registration = $this->config()->use(Greets::class, Counts::class);

        self::assertSame([Greets::class, Counts::class], $registration->traits);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('the trait does not exist');

        $registration->use(PlainBase::class);
    }

    /**
     * The legacy spelling takes both kinds in one call, so it has to
     * decide what each name IS rather than what it was meant to be.
     */
    public function testAssignSplitsNamesByWhatTheyActuallyAre(): void
    {
        $registration = $this->config()->assign(Greets::class, PlainBase::class);

        self::assertSame([Greets::class], $registration->traits);
        self::assertSame(PlainBase::class, $registration->class);
    }

    public function testAssignRefusesANameThatIsNeither(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('no such class or trait');

        $this->config()->assign('LucianoPereira\Crucible\Tests\Fixtures\NoSuchThing');
    }

    /**
     * Spec §3: inside a test file uses() is always file-local, so ->in()
     * there is not a narrower scope — it is a misunderstanding, and
     * silently accepting it would scope the registration to paths the
     * author never gets told were ignored.
     */
    public function testInBelongsToAConfigurationFileOnly(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Pest.php configuration file');

        (new ScopeRegistration('/project/tests', fromConfigFile: false))->in('Feature');
    }

    public function testInStartsNullAndMergesAcrossCalls(): void
    {
        $registration = $this->config();

        // null is not the same as []: bare means "no ->in() yet", which
        // is what decides whether a config registration applies its
        // hooks suite-wide.
        self::assertNull($registration->globs);

        $registration->in('Feature')->in('Unit', 'Integration');

        self::assertSame(['Feature', 'Unit', 'Integration'], $registration->globs);
    }

    public function testGroupsAccumulateInDeclarationOrder(): void
    {
        self::assertSame(['slow', 'db'], $this->config()->group('slow')->group('db')->groups);
    }

    public function testHooksAccumulatePerKindAndAnswerHasHooks(): void
    {
        $registration = $this->config();

        self::assertFalse($registration->hasHooks());

        $noop = static function (): void {};

        $registration->beforeEach($noop)->afterEach($noop)->beforeAll($noop)->afterAll($noop);

        self::assertCount(1, $registration->beforeEach);
        self::assertCount(1, $registration->afterEach);
        self::assertCount(1, $registration->beforeAll);
        self::assertCount(1, $registration->afterAll);
        self::assertTrue($registration->hasHooks());
    }

    public function testEachHookKindIsSeparate(): void
    {
        // hasHooks() is what makes a bare config registration apply
        // suite-wide, so a hook landing in the wrong list would change
        // which files a scope reaches.
        $registration = $this->config()->afterAll(static function (): void {});

        self::assertSame([], $registration->beforeEach);
        self::assertCount(1, $registration->afterAll);
        self::assertTrue($registration->hasHooks());
    }

    public function testThePrinterChainStarts(): void
    {
        self::assertInstanceOf(PrinterSelection::class, $this->config()->printer());
    }
}
