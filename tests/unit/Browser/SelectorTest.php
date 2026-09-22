<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\Selector;
use LucianoPereira\Crucible\Framework\TestCase;

#[CoversClass(Selector::class)]
final class SelectorTest extends TestCase
{
    public function testDataTestShorthand(): void
    {
        $this->assertSame('[data-test="login"]', Selector::resolve('@login'));
        $this->assertSame('[data-test="main-nav"]', Selector::resolve('@main-nav'));
    }

    public function testCssPassesThrough(): void
    {
        $this->assertSame('.btn-primary', Selector::resolve('.btn-primary'));
        $this->assertSame('#submit-button', Selector::resolve('#submit-button'));
        $this->assertSame('[role=button]', Selector::resolve('[role=button]'));
        $this->assertSame('nav > a', Selector::resolve('nav > a'));
        $this->assertSame('li:first-child', Selector::resolve('li:first-child'));
    }

    public function testBareWordsMeanVisibleText(): void
    {
        $this->assertSame('text=Login', Selector::resolve('Login'));
        $this->assertSame('text=Sign In to Your Account', Selector::resolve('Sign In to Your Account'));
    }

    public function testBareHtmlElementNamesStayElements(): void
    {
        $this->assertSame('li', Selector::resolve('li'));
        $this->assertSame('body', Selector::resolve('body'));
        $this->assertSame('button', Selector::resolve('button'));
    }

    public function testFieldsTryNameIdAndDataTest(): void
    {
        $this->assertSame('[name="email"], #email, [data-test="email"]', Selector::field('email'));
    }

    public function testExplicitFieldSelectorsPassThrough(): void
    {
        $this->assertSame('input[name=email]', Selector::field('input[name=email]'));
        $this->assertSame('#email', Selector::field('#email'));
        $this->assertSame('[data-test="mail"]', Selector::field('@mail'));
    }
}
