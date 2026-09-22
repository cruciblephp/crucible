<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Configuration;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Crucible;
use LucianoPereira\Crucible\Configuration\Quirk;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Dialect\Pest\InvalidExpectationValue;
use LucianoPereira\Crucible\Dialect\Pest\SubjectRule;
use LucianoPereira\Crucible\Framework\TestCase;
use SplFileInfo;
use stdClass;

use function fclose;
use function fopen;
use function is_resource;

/**
 * A quirk is an incumbent bug Crucible declines to reproduce until asked
 * for that one bug by name. There are currently none, and these tests
 * pin why: every behaviour that once needed one is either the default
 * now or was never a bug at all.
 *
 * What they must keep proving is that the ABSENCE is measured rather
 * than assumed — the matchers really do answer as the incumbent does,
 * and the removed names really are gone.
 */
#[CoversClass(Quirk::class)]
#[CoversClass(Expectation::class)]
#[CoversClass(SubjectRule::class)]
final class QuirkTest extends TestCase
{
    protected function tearDown(): void
    {
        Expectation::configure([]);
    }

    public function testNoQuirkIsDeclared(): void
    {
        // The parity claim, not an omission: over 3,344 grid cells there
        // is no incumbent answer Crucible declines to reproduce, so there
        // is nothing to opt back into. The mechanism stays because the
        // bar it enforces does — any future divergence must name the
        // quirk that restores the incumbent, and the probe checks it.
        self::assertSame([], Quirk::cases());
    }

    public function testARemovedQuirkIsNotAcceptedAsAName(): void
    {
        // Removed rather than deprecated: a quirk that bridges nothing is
        // a crucible.php value that silently does nothing, which is worse
        // than one that is gone.
        foreach (['quirk_falsy_slug', 'quirk_falsy_hostname', 'quirk_stringified_subject'] as $gone) {
            self::assertNull(Quirk::tryFrom($gone), $gone . ' must not resolve to a quirk');
        }
    }

    public function testTheConfigurationStillCarriesAnEmptyList(): void
    {
        // The seam is intact even with nothing in it, so the first real
        // incumbent bug needs a case rather than a mechanism.
        self::assertSame([], Crucible::configure()->build()->quirks);
        self::assertSame([], Crucible::configure()->quirks()->build()->quirks);
    }

    public function testTheFalsyZeroIsNotASpecialCaseButIsEmpty(): void
    {
        Expectation::configure([]);

        // toBeSlug reduces its subject and asks whether the result is
        // EMPTY, and empty('0') is true in PHP. So '0' fails for exactly
        // the reason every other value reducing to nothing fails —
        // measured in both engines, same verdict, same message.
        foreach (['0', '!!!', '---', '   ', ''] as $reducesToNothing) {
            (new Expectation($reducesToNothing))->not()->toBeSlug();
        }

        // The neighbour that proves the rule is emptiness and not
        // zero-ness: '00' is not empty, so it passes.
        (new Expectation('00'))->toBeSlug();
        (new Expectation('9'))->toBeSlug();

        $this->addToAssertionCount(1);
    }

    public function testTheHostnameTrapIsTheSameEmptinessOneLayerDown(): void
    {
        Expectation::configure([]);

        // filter_var() VALIDATES '0' as a hostname and hands it back, and
        // the incumbent tests that return for truth — the same IsEmpty
        // rule, not a second defect. Crucible composes the same
        // primitive, so it agrees without being told to.
        (new Expectation('0'))->not()->toBeHostname();
        (new Expectation('9'))->toBeHostname();
        (new Expectation('localhost'))->toBeHostname();

        $this->addToAssertionCount(1);
    }

    public function testTheCastIsTheDefaultAndNotAnOptIn(): void
    {
        Expectation::configure([]);

        // The cast family reads its subject THROUGH a string cast rather
        // than requiring a string, which is the whole mechanism — an
        // array reaches toBeAlpha as 'Array', which is alphabetic, and a
        // bool as '1'. This used to be quirk-gated, which left a migrated
        // Pest suite FAILING here until someone opted in: the D-004
        // violation the rule exists to prevent.
        (new Expectation([]))->toBeAlpha();
        (new Expectation([1, 2]))->toBeAlpha();
        (new Expectation(true))->toBeDigits();

        // 'Array' is spelled out rather than cast, so the answer is
        // reproduced without the engine's "Array to string conversion"
        // warning — fidelity at the level of the answer.
        (new Expectation([]))->not()->toBeDigits();

        // false casts to '' and so fails where true passes; the asymmetry
        // is PHP's, and reproducing it is what fidelity means here.
        (new Expectation(false))->not()->toBeDigits();

        $this->addToAssertionCount(1);
    }

    public function testEverythingTheIncumbentCastsIsCast(): void
    {
        Expectation::configure([]);

        // A resource casts to 'Resource id #N', and toBeSlug is the one
        // matcher in the family where that changes the answer rather than
        // coinciding with it: the reduced string is truthy, where the
        // rest reject the digits and the spaces either way. Measured
        // against the incumbent, which passes toBeSlug on a resource.
        $handle = fopen('php://memory', 'r+');

        // A failed fopen would assert about `false` instead of a
        // resource, so it is refused up front rather than quietly
        // measuring the wrong subject.
        self::assertTrue(is_resource($handle));

        (new Expectation($handle))->toBeSlug();

        fclose($handle);

        // A Stringable is read through its own declared string: PHP
        // accepts one wherever a string parameter is declared, so
        // discarding it would be Crucible's gap rather than fidelity.
        (new Expectation(new SplFileInfo('abc')))->toBeAlpha();

        // An int and a float likewise — they render the way var_export
        // and json_encode render them, with no warning.
        (new Expectation(98))->toBeAlphaNumeric()->toBeDigits();
        (new Expectation(1.5))->toBeSlug();
    }

    public function testTheRefusalIsNotAVerdictAndNeverWasQuirkGated(): void
    {
        Expectation::configure([]);

        // An object PHP cannot render as a string is refused, not
        // answered. The incumbent dies there with a raw PHP Error, so
        // both engines produce NO verdict and `->not` cannot launder
        // either into a pass — which is the whole defect this guards.
        // There was never a verdict here for a quirk to restore.
        try {
            (new Expectation(new stdClass()))->not()->toBeAlpha();
            self::fail('an unrenderable object must be refused, not answered');
        } catch (InvalidExpectationValue) {
            $this->addToAssertionCount(1);
        }
    }
}
