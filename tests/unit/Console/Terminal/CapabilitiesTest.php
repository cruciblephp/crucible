<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Console\Terminal;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Framework\TestCase;

use function getenv;
use function putenv;

/**
 * The two questions, and why they are two.
 *
 * ⚠ Colour and redraw are not the same capability, and every test here
 * exists because conflating them produces one of two failures: a build log
 * full of carriage returns, or a terminal that shows `[38;2;232;12;8m` as
 * text. The environment decides, so these tests own the environment.
 */
#[CoversClass(Capabilities::class)]
final class CapabilitiesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['NO_COLOR', 'FORCE_COLOR', 'CI', 'TERM'] as $name) {
            $this->saved[$name] = getenv($name);

            putenv($name);
        }

        Capabilities::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }

        Capabilities::reset();
    }

    public function testATerminalGetsBoth(): void
    {
        $terminal = new FakeTerminal();

        self::assertTrue(Capabilities::color($terminal));
        self::assertTrue(Capabilities::animation($terminal));
    }

    public function testAPipeGetsNeither(): void
    {
        $terminal = new FakeTerminal(interactive: false);

        self::assertFalse(Capabilities::color($terminal));
        self::assertFalse(Capabilities::animation($terminal));
    }

    /**
     * The case the split exists for.
     *
     * A CI runner that allocates a PTY and sets `FORCE_COLOR` is asking
     * for colour, and its log viewer renders it. It is not asking to have
     * every intermediate frame of a spinner recorded forever, and nobody
     * is watching one get overwritten.
     */
    public function testContinuousIntegrationKeepsColourAndLosesAnimation(): void
    {
        putenv('CI=true');
        putenv('FORCE_COLOR=1');

        $terminal = new FakeTerminal();

        self::assertTrue(Capabilities::color($terminal), 'the log viewer renders SGR');
        self::assertFalse(Capabilities::animation($terminal), 'but nothing may be redrawn into it');
    }

    /** Travis says "not CI" by setting the variable to false rather than clearing it. */
    public function testAFalseCiMarkerIsNotContinuousIntegration(): void
    {
        $terminal = new FakeTerminal();

        foreach (['false', '0', ''] as $value) {
            putenv('CI=' . $value);

            self::assertTrue(Capabilities::animation($terminal), 'CI=' . $value . ' is not a CI run');
        }
    }

    /**
     * A TTY that understands neither, which detection by TTY alone misses.
     *
     * `TERM=dumb` is a real terminal by every test `stream_isatty` can
     * make, and it renders an SGR sequence as its own characters.
     */
    public function testADumbTerminalGetsNeitherDespiteBeingATty(): void
    {
        putenv('TERM=dumb');

        $terminal = new FakeTerminal();

        self::assertFalse(Capabilities::color($terminal));
        self::assertFalse(Capabilities::animation($terminal));
    }

    public function testAnEmptyTermIsTreatedAsDumb(): void
    {
        putenv('TERM=');

        self::assertFalse(Capabilities::color(new FakeTerminal()));
    }

    public function testAKnownTermIsFine(): void
    {
        putenv('TERM=xterm-256color');

        self::assertTrue(Capabilities::color(new FakeTerminal()));
    }

    /** NO_COLOR wins over FORCE_COLOR, which is the order the convention specifies. */
    public function testNoColorBeatsForceColor(): void
    {
        putenv('NO_COLOR=1');
        putenv('FORCE_COLOR=1');

        self::assertFalse(Capabilities::color(new FakeTerminal(interactive: false)));
    }

    /** FORCE_COLOR paints a pipe, which is what --colors=always means through it. */
    public function testForceColorPaintsAPipe(): void
    {
        putenv('FORCE_COLOR=1');

        $terminal = new FakeTerminal(interactive: false);

        self::assertTrue(Capabilities::color($terminal));
        self::assertFalse(Capabilities::animation($terminal), 'a pipe still cannot be redrawn');
    }

    /** The overrides beat every environment variable, which is what makes tests possible. */
    public function testTheOverridesWinAndResetTogether(): void
    {
        putenv('NO_COLOR=1');
        putenv('CI=true');

        Capabilities::forceColor(true);
        Capabilities::forceAnimation(true);

        $terminal = new FakeTerminal(interactive: false);

        self::assertTrue(Capabilities::color($terminal));
        self::assertTrue(Capabilities::animation($terminal));

        Capabilities::reset();

        self::assertFalse(Capabilities::color($terminal), 'NO_COLOR is back in charge');
        self::assertFalse(Capabilities::animation($terminal));
    }
}
