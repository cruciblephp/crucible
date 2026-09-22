<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\InertiaRecorder;
use LucianoPereira\Crucible\Browser\LivewireSnapshot;
use LucianoPereira\Crucible\Browser\Playwright\PageAssertions;
use LucianoPereira\Crucible\Browser\Selector;
use LucianoPereira\Crucible\Framework\TestCase;

/**
 * The assertion surface over a known page state (see StandInPage).
 *
 * Every check here is one the oracle-backed test cannot afford to make
 * — it would need a page built for each case — and every one of them
 * is about this layer rather than about a browser. Both directions are
 * asserted wherever the surface ships a pair: a negation that passes
 * when its positive passes is the failure mode a one-sided test
 * cannot see.
 */
#[CoversClass(PageAssertions::class)]
final class PageAssertionsSurfaceTest extends TestCase
{
    public function testTheUrlDecomposesIntoEveryComponentTheSurfaceExposes(): void
    {
        $page = new StandInPage(url: 'https://shop.example.com:8443/orders/42?tab=items&q=a%20b#totals');

        $page->assertSchemeIs('https')
            ->assertHostIs('shop.example.com')
            ->assertPortIs('8443')
            ->assertPathIs('/orders/42')
            ->assertFragmentIs('totals');

        // parse_url hands the port back as an int; the surface takes a
        // string, so the conversion is the assertion's own.
        $page->assertPortIs('8443');
    }

    public function testEveryUrlNegationDisagreesWithItsPositive(): void
    {
        $page = new StandInPage(url: 'https://example.com:8443/orders#totals');

        $page->assertSchemeIsNot('http')
            ->assertHostIsNot('other.example.com')
            ->assertPortIsNot('80')
            ->assertPathIsNot('/invoices')
            ->assertFragmentIsNot('lines');

        $this->refutes(static fn(): mixed => $page->assertSchemeIsNot('https'));
        $this->refutes(static fn(): mixed => $page->assertHostIsNot('example.com'));
        $this->refutes(static fn(): mixed => $page->assertPortIsNot('8443'));
        $this->refutes(static fn(): mixed => $page->assertPathIsNot('/orders'));
        $this->refutes(static fn(): mixed => $page->assertFragmentIsNot('totals'));
    }

    public function testAnAbsentUrlComponentReadsAsEmptyRatherThanNull(): void
    {
        // A bare path has no scheme, host, port or fragment. urlPart()
        // flattens all of that to '', which is what the string
        // assertions below can compare against at all.
        $page = new StandInPage(url: '/orders');

        $page->assertSchemeIs('')
            ->assertHostIs('')
            ->assertPortIs('')
            ->assertFragmentIs('')
            ->assertPathIs('/orders');
    }

    public function testUrlIsComparesTheWholeUrlOrJustThePath(): void
    {
        $page = new StandInPage(url: 'https://example.com/orders/42');

        // The documented fork: a bare path compares the path only, a
        // full URL compares the whole thing.
        $page->assertUrlIs('/orders/42');
        $page->assertUrlIs('https://example.com/orders/42');

        $this->refutes(static fn(): mixed => $page->assertUrlIs('/orders'));
        $this->refutes(static fn(): mixed => $page->assertUrlIs('https://example.com/orders'));
    }

    public function testPathPrefixSuffixAndFragmentPrefixReadTheDecomposedPart(): void
    {
        $page = new StandInPage(url: 'https://example.com/admin/orders/42?x=1#section-totals');

        $page->assertPathBeginsWith('/admin')
            ->assertPathEndsWith('/42')
            ->assertPathContains('orders')
            ->assertFragmentBeginsWith('section-');

        // The query and fragment are not part of the path, so a suffix
        // test cannot be fooled by them.
        $this->refutes(static fn(): mixed => $page->assertPathEndsWith('#section-totals'));
        $this->refutes(static fn(): mixed => $page->assertPathContains('x=1'));
    }

    public function testQueryParametersAreSplitAndPercentDecoded(): void
    {
        $page = new StandInPage(url: 'https://example.com/s?q=a%20b&empty&tag=x%26y&flag=');

        $page->assertQueryStringHas('q', 'a b')
            ->assertQueryStringHas('tag', 'x&y')
            ->assertQueryStringHas('flag', '')
            ->assertQueryStringMissing('missing');

        // A parameter with no '=' at all is present, with an empty
        // value -- distinct from being absent.
        $page->assertQueryStringHas('empty');
        $page->assertQueryStringHas('empty', '');

        $this->refutes(static fn(): mixed => $page->assertQueryStringHas('q', 'a%20b'));
        $this->refutes(static fn(): mixed => $page->assertQueryStringMissing('q'));
    }

    public function testAUrlWithNoQueryHasNoParameters(): void
    {
        $page = new StandInPage(url: 'https://example.com/s');

        $page->assertQueryStringMissing('q');
        $this->refutes(static fn(): mixed => $page->assertQueryStringHas('q'));
    }

    public function testTitleAndTextComparisonsBothDirections(): void
    {
        $page = new StandInPage(
            title: 'Orders · Example',
            texts: ['body' => 'Thank you for your order', '#empty' => "  \n ", '.total' => '42.00'],
        );

        $page->assertTitle('Orders · Example')
            ->assertTitleContains('Orders')
            ->assertSee('Thank you')
            ->assertDontSee('Declined')
            ->assertSeeIn('.total', '42.00')
            ->assertDontSeeIn('.total', '43.00')
            ->assertSeeAnythingIn('.total')
            ->assertSeeNothingIn('#empty');

        // Whitespace-only counts as nothing, which is the only reason
        // trim() is in there.
        $this->refutes(static fn(): mixed => $page->assertSeeAnythingIn('#empty'));
        $this->refutes(static fn(): mixed => $page->assertSeeNothingIn('.total'));
    }

    public function testTextReadsFromBodyForTheUnscopedForm(): void
    {
        // assertSee is assertSeeIn('body'), and a page whose body text
        // is empty must not pass because some other node has the text.
        $page = new StandInPage(texts: ['.total' => 'Thank you']);

        $this->refutes(static fn(): mixed => $page->assertSee('Thank you'));
    }

    public function testAttributeComparisonsIncludingAbsence(): void
    {
        $page = new StandInPage(attributes: [
            "#save\nclass"      => 'btn btn-primary',
            "#save\naria-label" => 'Save order',
            "#save\ndata-test"  => 'save-order',
        ]);

        $page->assertAttribute('#save', 'class', 'btn btn-primary')
            ->assertAttributeContains('#save', 'class', 'btn-primary')
            ->assertAttributeDoesntContain('#save', 'class', 'btn-danger')
            ->assertAttributeMissing('#save', 'disabled')
            ->assertAriaAttribute('#save', 'label', 'Save order')
            ->assertDataAttribute('#save', 'test', 'save-order');

        // An absent attribute reads as '' for the containment checks,
        // so "does not contain" holds and "contains" cannot.
        $page->assertAttributeDoesntContain('#save', 'disabled', 'anything');
        $this->refutes(static fn(): mixed => $page->assertAttributeContains('#save', 'disabled', 'anything'));
        $this->refutes(static fn(): mixed => $page->assertAttributeMissing('#save', 'class'));
    }

    public function testFormStateReadsThroughTheFieldGrammar(): void
    {
        $email = Selector::field('email');

        $page = new StandInPage(
            values: ['email' => 'ada@example.com'],
            checked: [Selector::field('terms') => true, Selector::radio('plan', 'pro') => true],
            enabled: [$email => true, Selector::field('locked') => false],
        );

        $page->assertValue('email', 'ada@example.com')
            ->assertValueIsNot('email', 'grace@example.com')
            ->assertChecked('terms')
            ->assertNotChecked('newsletter')
            ->assertRadioSelected('plan', 'pro')
            ->assertRadioNotSelected('plan', 'free')
            ->assertEnabled('email')
            ->assertDisabled('locked');

        $this->refutes(static fn(): mixed => $page->assertNotChecked('terms'));
        $this->refutes(static fn(): mixed => $page->assertRadioNotSelected('plan', 'pro'));
        $this->refutes(static fn(): mixed => $page->assertDisabled('email'));
    }

    public function testSelectAndIndeterminateStateGoThroughTheMatchedElements(): void
    {
        $plan       = Selector::field('plan');
        $selected   = '(els, v) => [...els[0].options].some(o => o.selected && o.value === v)';
        $partial    = Selector::field('all');
        $indefinite = 'els => els[0].indeterminate';

        $page = new StandInPage(matches: [
            $plan . "\n" . $selected . "\n" . 'pro'  => true,
            $plan . "\n" . $selected . "\n" . 'free' => false,
            $partial . "\n" . $indefinite . "\n"     => true,
        ]);

        $page->assertSelected('plan', 'pro')
            ->assertNotSelected('plan', 'free')
            ->assertIndeterminate('all');

        $this->refutes(static fn(): mixed => $page->assertNotSelected('plan', 'pro'));
        $this->refutes(static fn(): mixed => $page->assertSelected('plan', 'free'));

        // Strictly true, so a driver answering anything else -- null
        // for an element that is not there, say -- is not indeterminate.
        $absent = new StandInPage();
        $this->refutes(static fn(): mixed => $absent->assertIndeterminate('all'));
    }

    public function testButtonsAskTheTargetGrammarRatherThanTheFieldGrammar(): void
    {
        // assertEnabled resolves a FIELD, assertButtonEnabled resolves a
        // TARGET: 'Save' means name="Save" for one and the visible text
        // for the other, and the pair would collapse if either changed.
        $page = new StandInPage(enabled: [
            Selector::resolve('Save')   => true,
            Selector::resolve('Cancel') => false,
        ]);

        $page->assertButtonEnabled('Save')->assertButtonDisabled('Cancel');

        $this->refutes(static fn(): mixed => $page->assertButtonDisabled('Save'));
        $this->refutes(static fn(): mixed => $page->assertButtonEnabled('Cancel'));

        // The two grammars really do differ here.
        self::assertNotSame(Selector::resolve('Save'), Selector::field('Save'));
    }

    public function testPresenceAndVisibilityAreDifferentQuestions(): void
    {
        $hidden = Selector::resolve('#hidden');

        $page = new StandInPage(
            visible: [Selector::resolve('#shown') => true, $hidden => false],
            matches: [$hidden . "\n" . 'els => els.length > 0' . "\n" => true],
        );

        // In the DOM but not visible: assertMissing passes on the same
        // element assertPresent passes on, and that is the point.
        $page->assertVisible('#shown')
            ->assertMissing('#hidden')
            ->assertPresent('#hidden')
            ->assertNotPresent('#gone');

        $this->refutes(static fn(): mixed => $page->assertVisible('#hidden'));
        $this->refutes(static fn(): mixed => $page->assertNotPresent('#hidden'));
    }

    public function testCountReadsTheMatchedElementsAndRefusesANonInteger(): void
    {
        $items = Selector::resolve('li');

        $page = new StandInPage(matches: [$items . "\n" . 'els => els.length' . "\n" => 3]);

        $page->assertCount('li', 3);
        $this->refutes(static fn(): mixed => $page->assertCount('li', 4));

        // Anything that is not an int becomes -1 rather than being
        // coerced, so a driver that answered oddly cannot accidentally
        // satisfy a count.
        $odd = new StandInPage(matches: [$items . "\n" . 'els => els.length' . "\n" => '3']);
        $this->refutes(static fn(): mixed => $odd->assertCount('li', 3));
    }

    public function testLinksAreMatchedByVisibleTextWithQuotesEscaped(): void
    {
        $page = new StandInPage(visible: [
            'a:has-text("Sign in")'      => true,
            'a:has-text("Say \\"hi\\"")' => true,
        ]);

        $page->assertSeeLink('Sign in')
            ->assertSeeLink('Say "hi"')
            ->assertDontSeeLink('Sign out');

        $this->refutes(static fn(): mixed => $page->assertDontSeeLink('Sign in'));
    }

    public function testSourceAssertionsReadTheRawContent(): void
    {
        $page = new StandInPage(content: '<html><body><p class="ok">Fine</p></body></html>');

        $page->assertSourceHas('class="ok"')->assertSourceMissing('<script');

        $this->refutes(static fn(): mixed => $page->assertSourceMissing('class="ok"'));
    }

    public function testScriptComparesStrictlyAndDefaultsToTrue(): void
    {
        $page = new StandInPage(scripts: ['ready' => true, 'count' => 2, 'zero' => 0]);

        $page->assertScript('ready')->assertScript('count', 2)->assertScript('zero', 0);

        // Strict: 0 is not false, and 2 is not '2'.
        $this->refutes(static fn(): mixed => $page->assertScript('zero', false));
        $this->refutes(static fn(): mixed => $page->assertScript('count', '2'));
        $this->refutes(static fn(): mixed => $page->assertScript('missing'));
    }

    public function testInertiaPropsResolveThroughDotPathsIncludingListIndexes(): void
    {
        $page = new StandInPage(scripts: [
            InertiaRecorder::readExpression() => [
                'component' => 'Orders/Show',
                'props'     => [
                    'user'  => ['name' => 'Ada', 'roles' => ['admin', 'billing']],
                    'total' => 42,
                ],
            ],
        ]);

        $page->assertInertiaComponent('Orders/Show')
            ->assertInertiaProp('user.name', 'Ada')
            ->assertInertiaProp('total', 42)
            ->assertInertiaProp('user.roles.1', 'billing');

        // Off the end of the map: an absent prop resolves to null
        // rather than throwing, and the assertion is what reports it.
        $page->assertInertiaProp('user.missing', null);
        $page->assertInertiaProp('total.deeper', null);

        $this->refutes(static fn(): mixed => $page->assertInertiaComponent('Orders/Index'));
        $this->refutes(static fn(): mixed => $page->assertInertiaProp('user.name', 'Grace'));
    }

    public function testAPageThatIsNotInertiaSaysSoRatherThanNotMatching(): void
    {
        $page = new StandInPage();

        self::assertNull($page->inertiaPage());

        // It asked the page rather than assuming: the recorder's own
        // expression is what went across, so a page with no answer is
        // an absent Inertia payload and not an unasked question.
        self::assertSame([InertiaRecorder::readExpression()], $page->evaluated);

        // The distinction the surface documents: "not an Inertia page"
        // is a different failure from "the component did not match".
        $this->refutes(static fn(): mixed => $page->assertInertiaComponent('Orders/Show'));
    }

    public function testLivewireStateIsReadFromTheSnapshotTheComponentCarries(): void
    {
        $page = new StandInPage(scripts: [
            LivewireSnapshot::readExpression()          => ['data' => ['count' => 1, 'form' => ['email' => 'ada@example.com']]],
            LivewireSnapshot::readExpression('counter') => ['data' => ['count' => 7]],
        ]);

        $page->assertWireSet('count', 1)
            ->assertWireSet('form.email', 'ada@example.com')
            ->assertWireSet('count', 7, 'counter');

        $this->refutes(static fn(): mixed => $page->assertWireSet('count', 2));
        $this->refutes(static fn(): mixed => $page->assertWireSet('count', 1, 'counter'));
    }

    public function testAPageWithNoLivewireComponentIsNullRatherThanEmpty(): void
    {
        $page = new StandInPage();

        self::assertNull($page->wire());
        $this->refutes(static fn(): mixed => $page->assertWireSet('count', 1));

        // A snapshot whose data is not a map is no state either.
        $shapeless = new StandInPage(scripts: [LivewireSnapshot::readExpression() => ['data' => 'nope']]);
        self::assertNull($shapeless->wire());
    }

    public function testWireClickWaitsForTheCycleItStarted(): void
    {
        $page = new StandInPage();

        $page->wireClick('@save', 0.5, 9.0);

        // The whole point of the helper: the click, then the wait, in
        // that order and with the caller's own timings.
        self::assertSame(['@save'], $page->clicked);
        self::assertSame([[0.5, 9.0]], $page->waited);
    }

    public function testOnlyLogTypeConsoleEntriesTripTheConsoleCheck(): void
    {
        // Oracle-pinned: warn/info/debug/error do NOT trip it.
        $quiet = new StandInPage(consoleLogs: [
            ['type' => 'warn', 'text' => 'deprecated'],
            ['type' => 'error', 'text' => 'boom'],
            ['type' => 'info', 'text' => 'hello'],
            ['type' => 'debug', 'text' => 'x'],
        ]);

        $quiet->assertNoConsoleLogs();

        $noisy = new StandInPage(consoleLogs: [
            ['type' => 'warn', 'text' => 'deprecated'],
            ['type' => 'log', 'text' => 'left in by mistake'],
        ]);

        $this->refutes(static fn(): mixed => $noisy->assertNoConsoleLogs());
    }

    public function testJavaScriptErrorsAndSmokeAreTheSameCheck(): void
    {
        $clean = new StandInPage();
        $clean->assertNoJavaScriptErrors()->assertNoSmoke();

        $broken = new StandInPage(javaScriptErrors: ['TypeError: x is not a function']);

        $this->refutes(static fn(): mixed => $broken->assertNoJavaScriptErrors());
        $this->refutes(static fn(): mixed => $broken->assertNoSmoke());
    }

    public function testEveryAssertionReturnsThePageSoTheyChain(): void
    {
        $page = new StandInPage(title: 'x', url: 'https://example.com/');

        self::assertSame($page, $page->assertTitle('x'));
        self::assertSame($page, $page->assertPathIs('/'));
    }

    /**
     * Runs a check that must fail, and fails THIS test with the
     * assertion's own message when it does not — a negation that
     * quietly passes is the defect these pairs exist to catch.
     *
     * @param callable(): mixed $check
     */
    private function refutes(callable $check): void
    {
        try {
            $check();
        } catch (AssertionFailedError) {
            return;
        }

        self::fail('Expected the assertion to fail, and it passed.');
    }
}
