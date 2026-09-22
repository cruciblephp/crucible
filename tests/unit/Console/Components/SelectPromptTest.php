<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Console\Components;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Components\SelectPrompt;
use LucianoPereira\Crucible\Console\Exceptions\NonInteractiveException;
use LucianoPereira\Crucible\Console\Input\Key;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Framework\TestCase;

use function substr_count;

/**
 * The one prompt that refuses to answer for a script.
 *
 * ⚠ Every test here installs a {@see FakeTerminal} first. Built against
 * the real one, a prompt draws its list into the middle of the running
 * suite's own output and then blocks waiting for a keypress — which is
 * exactly how this component reached production untested.
 */
#[CoversClass(SelectPrompt::class)]
final class SelectPromptTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::reset();
    }

    /** The question, every option, and the way out of it. */
    public function testTheListIsDrawnWithEveryOptionAndTheHint(): void
    {
        $terminal = $this->terminal([Key::ENTER]);

        Runtime::run($this->prompt());

        $drawn = Str::stripAnsi($terminal->output());

        self::assertStringContainsString('Which plugin should be previewed?', $drawn);
        self::assertStringContainsString('pdf', $drawn);
        self::assertStringContainsString('junit', $drawn);
        self::assertStringContainsString('teamcity', $drawn);
        self::assertStringContainsString('Or pass --key=<name>.', $drawn);
    }

    /**
     * Exactly one option is marked at a time.
     *
     * ✓ The control for the highlight: a frame that marked every row,
     * or none, would still contain the marker this looks for.
     */
    public function testOneOptionAtATimeCarriesTheMarker(): void
    {
        $terminal = $this->terminal([Key::ENTER]);

        Runtime::run($this->prompt());

        $frames = Str::stripAnsi($terminal->output());

        self::assertSame(1, substr_count($frames, '❯'), 'one marker, on the highlighted row');
    }

    /** Arrows move the highlight, and enter takes what is under it. */
    public function testTheArrowKeysChooseAndEnterAnswers(): void
    {
        $this->terminal([Key::DOWN, Key::DOWN, Key::ENTER]);

        $prompt = $this->prompt();

        Runtime::run($prompt);

        self::assertSame('junit', $prompt->value(), 'two rows down from pdf');
        self::assertSame('junit', $prompt->highlightedLabel());
    }

    /**
     * The list wraps rather than stopping at its ends.
     *
     * Both directions, because a list that wraps one way and clamps the
     * other is the shape nobody can predict from having used it once.
     */
    public function testTheListWrapsAtBothEnds(): void
    {
        $this->terminal([Key::UP, Key::ENTER]);

        $up = $this->prompt();

        Runtime::run($up);

        self::assertSame('teamcity', $up->value(), 'up from the first lands on the last');

        $this->terminal([Key::DOWN, Key::DOWN, Key::DOWN, Key::DOWN, Key::ENTER]);

        $down = $this->prompt();

        Runtime::run($down);

        self::assertSame('pdf', $down->value(), 'and past the last, back to the first');
    }

    /**
     * Off a terminal it refuses, naming the flag that would have answered.
     *
     * ⚠ The claim the class exists to make. Every other prompt here
     * carries a default and so runs unattended; a list of plugins has
     * no obvious first choice, and previewing whichever one happened to
     * register first is the silent wrong answer.
     */
    public function testWithoutADefaultItRefusesToAnswerForAScript(): void
    {
        Runtime::setTerminal(new FakeTerminal(interactive: false));

        $prompt = $this->prompt();

        $this->expectException(NonInteractiveException::class);
        $this->expectExceptionMessage('Which plugin should be previewed?');

        Runtime::run($prompt);
    }

    /** Given a default, the same prompt answers unattended. */
    public function testADefaultIsAnAnswerAScriptCanUse(): void
    {
        $terminal = new FakeTerminal(interactive: false);

        Runtime::setTerminal($terminal);

        $prompt = new SelectPrompt(
            label: 'Which plugin should be previewed?',
            options: ['pdf' => 'pdf', 'markdown' => 'markdown', 'junit' => 'junit'],
            default: 'markdown',
        );

        self::assertNull($prompt->unanswerableWithoutTerminal());
        self::assertSame('markdown', Runtime::run($prompt));
        self::assertSame('', $terminal->output(), 'and nothing was drawn to ask it');
    }

    private function prompt(): SelectPrompt
    {
        return new SelectPrompt(
            label: 'Which plugin should be previewed?',
            options: ['pdf' => 'pdf', 'markdown' => 'markdown', 'junit' => 'junit', 'teamcity' => 'teamcity'],
            hint: 'Or pass --key=<name>.',
        );
    }

    /** @param list<string> $keys */
    private function terminal(array $keys): FakeTerminal
    {
        $terminal = new FakeTerminal($keys);

        Runtime::setTerminal($terminal);

        return $terminal;
    }
}
