<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * The pest dialect's self-hosted proof: this file runs as part of
 * Crucible's own suite, mixed with the phpunit-dialect tests, through
 * the same engine (D-008).
 */

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Dialect\Pest\InvalidExpectationValue;

$log = new ArrayObject();

\beforeEach(function (): void {
    $this->counter = 10;
});

\afterEach(function () use ($log): void {
    $log->append('after');
});

\test('a plain test with bound state', function (): void {
    \expect($this->counter)->toBe(10);
});

\it('prefixes descriptions', function (): void {
    \expect(true)->toBeTrue();
});

\test('chained expectations on one value', function (): void {
    \expect([3, 1, 2])
        ->toBeArray()
        ->toHaveCount(3)
        ->toContain(2)
        ->and('crucible')
        ->toBeString()
        ->toStartWith('cru')
        ->toEndWith('ble')
        ->toMatch('/^cr.cible$/');
});

\test('negation applies to exactly one matcher', function (): void {
    \expect(5)
        ->not->toBe(6)
        ->toBeInt()
        ->not->toBeString();
});

\test('common matchers', function (): void {
    \expect(null)->toBeNull();
    \expect([])->toBeEmpty();
    \expect(1)->toBeTruthy();
    \expect('')->toBeFalsy();
    \expect(3.5)->toBeFloat()->toBeGreaterThan(3)->toBeLessThanOrEqual(3.5);
    \expect('b')->toBeIn(['a', 'b']);
    \expect(['k' => 'v'])->toHaveKey('k');
    \expect(new ArrayObject())->toBeInstanceOf(ArrayObject::class);
    \expect('{"ok":true}')->toEqual('{"ok":true}');
});

/*
 * The parity grid sweeps only the matchers that take NO arguments, so
 * these three were swept by nothing and called by nothing — executed
 * for the first time here. Both directions, because an alias that
 * delegates to the wrong matcher passes the positive form.
 */
\test('the argument matchers the parity grid has no axis for', function (): void {
    \expect([1, 2, 3])->toBeEqualCanonicalizing([3, 2, 1]);
    \expect([1, 2, 3])->not->toBeEqualCanonicalizing([1, 2]);
    \expect(1.0)->toBeEqualWithDelta(1.05, 0.1);
    \expect(1.0)->not->toBeEqualWithDelta(1.5, 0.1);
    \expect(2)->toBeLessThan(3);
    \expect(3)->not->toBeLessThan(3);
});

\test('toThrow catches and verifies', function (): void {
    \expect(fn() => throw new RuntimeException('kaboom happened'))
        ->toThrow(RuntimeException::class, 'kaboom');

    \expect(fn() => 'calm')->not->toThrow(RuntimeException::class);
});

\test('datasets spread list rows as arguments', function (int $left, int $right, int $sum): void {
    \expect($left + $right)->toBe($sum);
})->with([
    'small'    => [1, 2, 3],
    'zero'     => [0, 0, 0],
    'negative' => [-1, 1, 0],
]);

\test('single-value dataset rows arrive as one argument', function (string $word): void {
    \expect($word)->toBeString()->not->toBeEmpty();
})->with(['espresso', 'ristretto']);

\describe('inside a describe block', function (): void {
    \test('names carry the describe path', function (): void {
        \expect(1)->toBe(1);
    });

    \describe('nested deeper', function (): void {
        \it('still works', function (): void {
            \expect('deep')->toContain('ee');
        });
    });
});

\test('a producer', function (): int {
    \expect(2)->toBeInt();

    return 42;
});

\test('a consumer receives the producer value', function (int $value): void {
    \expect($value)->toBe(42);
})->depends('a producer');

\test('a skip is the expected outcome', function (): void {
    \expect(false)->toBeTrue(); // never runs
})->skip('exercises the skip path')->expectsSkip('exercises the skip path');

\test('expected exceptions via throws', function (): void {
    throw new DomainException('deliberate');
})->throws(DomainException::class, 'deliberate');

\test('grouped for selection', function (): void {
    \expect('crucible')->not->toBe('pest');
})->group('pest-dialect');

\describe('the matcher long tail', function (): void {
    \test('equality variants', function (): void {
        \expect([3, 1, 2])->toEqualCanonicalizing([1, 2, 3]);
        \expect(0.1 + 0.2)->toEqualWithDelta(0.3, 0.0001);
        \expect('crucible')->toBeEqual('crucible');
        \expect(7)->toMatchConstraint(new \LucianoPereira\Crucible\Assert\Constraint\GreaterThan(6));
    });

    \test('comparison and ranges', function (): void {
        \expect(5)->toBeBetween(1, 10)->not->toBeBetween(6, 10);
        \expect('m')->toBeBetween('a', 'z');
        \expect(new DateTimeImmutable('2026-06-15'))
            ->toBeBetween(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
    });

    \test('numeric specials and resources', function (): void {
        \expect(NAN)->toBeNan();
        \expect(INF)->toBeInfinite();
        \expect(1.0)->not->toBeNan()->not->toBeInfinite();

        $stream = \fopen('php://memory', 'r+');
        \expect($stream)->toBeResource();

        if (\is_resource($stream)) {
            \fclose($stream);
        }
    });

    \test('string shapes', function (): void {
        \expect('crucible')->toBeAlpha()->toBeLowercase()->toBeCamelCase();
        \expect('crucible2026')->toBeAlphaNumeric()->not->toBeAlpha();
        \expect('2026')->toBeDigits();
        \expect('CRUCIBLE')->toBeUppercase()->not->toBeLowercase();
        \expect('kebab-case-words')->toBeKebabCase()->not->toBeSnakeCase();
        \expect('snake_case_words')->toBeSnakeCase()->not->toBeKebabCase();
        \expect('StudlyCaseWords')->toBeStudlyCase()->not->toBeCamelCase();
        \expect('camelCaseWords')->toBeCamelCase()->not->toBeStudlyCase();
    });

    \test('the compact printer declaration registered from Pest.php', function (): void {
        \expect(\LucianoPereira\Crucible\Dialect\Pest\PestScopes::printerPreference())->toBe('compact');
    });

    \test('architecture presets over class strings', function (): void {
        \expect(\LucianoPereira\Crucible\Dialect\Pest\Expectation::class)->toBeFinal();
        \expect(\LucianoPereira\Crucible\Assert\Constraint\Constraint::class)->toBeAbstract()->not->toBeFinal();
        \expect(\LucianoPereira\Crucible\Coverage\CoversTargets::class)->toBeReadonly();
        \expect(\LucianoPereira\Crucible\Event\Listener::class)->toBeInterface();
        \expect(\LucianoPereira\Crucible\Event\Outcome::class)->toBeEnum();
        \expect(\LucianoPereira\Crucible\Browser\Playwright\PageAssertions::class)->toBeTrait();
        \expect(\LucianoPereira\Crucible\Assert\Constraint\IsTrue::class)->toExtend(\LucianoPereira\Crucible\Assert\Constraint\Constraint::class);
        \expect(\LucianoPereira\Crucible\Event\NdjsonWriter::class)->toImplement(\LucianoPereira\Crucible\Event\Listener::class);
        \expect(\LucianoPereira\Crucible\Browser\Playwright\Page::class)->toUseTrait(\LucianoPereira\Crucible\Browser\Playwright\PageAssertions::class);
        \expect(\LucianoPereira\Crucible\Assert\Constraint\Constraint::class)->toHaveMethod('evaluate')->not->toHaveMethod('imaginary');
        \expect(\LucianoPereira\Crucible\Assert\Assert::class)->not->toBeFinal();
    });

    \test('urls, uuids, json strings', function (): void {
        \expect('https://crucible.example/run?parallel=4')->toBeUrl();
        \expect('not a url')->not->toBeUrl();
        \expect('123e4567-e89b-12d3-a456-426614174000')->toBeUuid();
        \expect('123e4567')->not->toBeUuid();
        \expect('{"tool":"crucible"}')->toBeJson();
        \expect('{oops')->not->toBeJson();
    });

    \test('identifiers and network formats', function (): void {
        \expect('01ARZ3NDEKTSV4RRFFQ69G5FAV')->toBeUlid();
        // A UUID is 36 characters with hyphens; a ULID is 26 without.
        \expect('123e4567-e89b-12d3-a456-426614174000')->not->toBeUlid();
        // I, L, O and U are absent from Crockford base32.
        \expect('0123456789ABCDEFGHIJKMNPQR')->not->toBeUlid();
        // Uppercase only, and exactly 26: the incumbent refuses both.
        \expect('01arz3ndektsv4rrffq69g5fav')->not->toBeUlid();
        \expect('0123456789ABCDEFGHJKMNPQR')->not->toBeUlid();

        \expect('lucho@crucible.example')->toBeEmail();
        \expect('lucho@@crucible.example')->not->toBeEmail();

        \expect('192.168.1.1')->toBeIpAddress();
        \expect('::1')->toBeIpAddress();
        \expect('192.168.1.256')->not->toBeIpAddress();

        \expect('3D:F2:C9:A6:B3:4F')->toBeMacAddress();
        \expect('3D:F2:C9:A6:B3')->not->toBeMacAddress();
    });

    \test('a hostname is not always a domain', function (): void {
        // The one input the two disagree on: `localhost` addresses a
        // host and carries no second label. Everywhere else they agree,
        // which is why the dot is the whole rule and not a guess at
        // what a plausible top-level domain looks like.
        \expect('localhost')->toBeHostname()->not->toBeDomain();
        \expect('crucible.example')->toBeHostname()->toBeDomain();
        \expect('127.0.0.1')->toBeHostname()->toBeDomain();
        \expect('not a host name')->not->toBeHostname()->not->toBeDomain();
        \expect('a..b')->not->toBeHostname()->not->toBeDomain();
    });

    \test('sluggable, not already a slug', function (): void {
        // toBeSlug asks whether a slug can be made, not whether one is
        // already there — the strict reading is toBeKebabCase.
        \expect('run-tests-in-parallel')->toBeSlug()->toBeKebabCase();
        \expect('Run Tests!')->toBeSlug()->not->toBeKebabCase();
        \expect('---a---')->toBeSlug();
        // Nothing is transliterated, so these reduce to nothing.
        \expect('ß')->not->toBeSlug();
        \expect('日本')->not->toBeSlug();
        \expect('-_-')->not->toBeSlug();
        \expect('')->not->toBeSlug();
        // '0' fails and '9' passes, in both engines. That is not a
        // special case: the question is whether the REDUCED string is
        // empty, and empty('0') is true in PHP -- the same rule that
        // rejects '-_-' and '' two lines up. '00' is not empty, so it
        // passes, which is what shows the rule is emptiness and not
        // zero-ness.
        \expect('0')->not->toBeSlug();
        \expect('00')->toBeSlug();
        \expect('9')->toBeSlug();
    });

    \test('hexadecimal without a prefix', function (): void {
        \expect('deadBEEF')->toBeHexadecimal();
        \expect('0')->toBeHexadecimal();
        // The incumbent refuses the 0x spelling, so this does too.
        \expect('0xDEADBEEF')->not->toBeHexadecimal();
        \expect('deadbeefg')->not->toBeHexadecimal();
        \expect('')->not->toBeHexadecimal();
    });

    \test('class-shape matchers that reflection alone can answer', function (): void {
        \expect(\LucianoPereira\Crucible\Impact\DependencyIndex::class)->toBeClass();
        // class_exists() is true for an enum, so without the extra
        // guard an enum would satisfy toBeClass() and toBeEnum() both.
        \expect(\LucianoPereira\Crucible\Event\Outcome::class)->toBeEnum();
        \expect(\LucianoPereira\Crucible\Event\Listener::class)->not->toBeClass();

        // ✓ pest 5.1.1: an enum fails BOTH forms — the positive predicate
        // excludes enums, the negated one is a bare `! class_exists()`.
        // toBeFinal and toBeReadonly carry the same asymmetry.
        \expect(fn() => \expect(\LucianoPereira\Crucible\Event\Outcome::class)->not->toBeClass())
            ->toThrow(AssertionFailedError::class);
        \expect(fn() => \expect(\LucianoPereira\Crucible\Event\Outcome::class)->not->toBeFinal())
            ->toThrow(AssertionFailedError::class);

        \expect(\LucianoPereira\Crucible\Console\Style\Attribute::class)->toBeIntBackedEnum()->not->toBeStringBackedEnum();
        \expect(\LucianoPereira\Crucible\Assert\ValueType::class)->toBeStringBackedEnum()->not->toBeIntBackedEnum();
        // A pure enum is backed by nothing, so it is neither.
        \expect(\LucianoPereira\Crucible\Browser\Device::class)->toBeEnum()->not->toBeIntBackedEnum()->not->toBeStringBackedEnum();

        // toBeInvokable is an arch matcher, so its subject must be in
        // the configured source: Closure is not, and would resolve to
        // nothing and pass vacuously. No class under src/ declares
        // __invoke, so the passing direction is proven by the arch grid
        // (arch-fixture's Kind\Invokable) rather than here.
        \expect(\LucianoPereira\Crucible\Event\Outcome::class)->not->toBeInvokable();

        \expect(\LucianoPereira\Crucible\Attributes\CoversClass::class)->toHaveAttribute(Attribute::class);
        \expect(\LucianoPereira\Crucible\Impact\DependencyIndex::class)->not->toHaveAttribute(Attribute::class);

        \expect(\LucianoPereira\Crucible\Impact\DependencyIndex::class)->toHaveConstructor();
        \expect(\LucianoPereira\Crucible\Browser\Playwright\DriverTransport::class)->toHaveDestructor();
        \expect(\LucianoPereira\Crucible\Impact\DependencyIndex::class)->not->toHaveDestructor();

        \expect(\LucianoPereira\Crucible\Impact\DependencyIndex::class)
            ->toHaveMethods(['load', 'reuse', 'record', 'save'])
            ->not->toHaveMethods(['load', 'imaginary']);
    });

    \test('collection membership and shape', function (): void {
        \expect([1, 2, 3])->toBeList()->toContainEqual('2')->not->toContain('2');
        \expect([new ArrayObject(), new ArrayObject()])->toContainOnlyInstancesOf(ArrayObject::class);
        \expect(['a', 'b'])->toHaveSameSize([1, 2])->toHaveLength(2);
        \expect('crucible')->toHaveLength(8);
    });

    \test('keys, dot notation, key cases', function (): void {
        $payload = ['user' => ['name' => 'Luciano', 'roles' => ['dev']], 'active' => true];

        \expect($payload)
            ->toHaveKey('active')
            ->toHaveKey('user.name')
            ->toHaveKey('user.name', 'Luciano')
            ->not->toHaveKey('user.email')
            ->toHaveKeys(['active', 'user.name' => 'Luciano']);

        \expect(['snake_key' => ['another_one' => 1]])->toHaveSnakeCaseKeys();
        \expect(['camelKey' => 1])->toHaveCamelCaseKeys()->not->toHaveKebabCaseKeys();
        \expect(['kebab-key' => 1])->toHaveKebabCaseKeys();
        \expect(['StudlyKey' => 1])->toHaveStudlyCaseKeys();
    });

    \test('array and object subsets', function (): void {
        \expect(['name' => 'crucible', 'lang' => 'php', 'tests' => 147])
            ->toMatchArray(['name' => 'crucible', 'lang' => 'php'])
            ->not->toMatchArray(['name' => 'pest']);

        $object       = new stdClass();
        $object->name = 'crucible';
        $object->fast = true;

        \expect($object)
            ->toHaveProperty('name')
            ->toHaveProperty('name', 'crucible')
            ->toHaveProperties(['name', 'fast'])
            ->toHaveProperties(['name' => 'crucible', 'fast' => true])
            ->toMatchObject(['fast' => true])
            ->not->toHaveProperty('slow');
    });
});

\describe('the wrong-typed-subject refusal', function (): void {
    // Eleven matchers decline a subject of the wrong type rather than
    // answering about it. Positive, declining and failing are the same
    // outcome, which is why answering `false` looked harmless for as
    // long as only the positive form was measured. Negated they are
    // opposites: a failure inverts into a pass and a refusal does not.
    // Every test here asserts BOTH forms for that reason.
    \test('the string matchers refuse a non-string in both forms', function (): void {
        $subjects = [
            [], 1, 1.5, true, false, null, new stdClass(),
            // Refused too: the guard is is_string(), not "castable to
            // one" — measured, a Stringable returning 'localhost' is
            // declined rather than accepted as a hostname.
            new class {
                public function __toString(): string
                {
                    return 'localhost';
                }
            },
        ];

        foreach (['toBeHostname', 'toBeUuid', 'toBeDomain', 'toBeIpAddress', 'toBeMacAddress', 'toBeUlid', 'toBeHexadecimal'] as $matcher) {
            foreach ($subjects as $subject) {
                \expect(fn() => (new Expectation($subject))->{$matcher}())
                    ->toThrow(InvalidExpectationValue::class, 'value of type [string]');
                \expect(fn() => (new Expectation($subject))->not->{$matcher}())
                    ->toThrow(InvalidExpectationValue::class, 'value of type [string]');
            }
        }
    });

    \test('the key-case matchers refuse a non-iterable in both forms', function (): void {
        foreach (['toHaveCamelCaseKeys', 'toHaveKebabCaseKeys', 'toHaveSnakeCaseKeys', 'toHaveStudlyCaseKeys'] as $matcher) {
            foreach (['x', 1, 1.5, true, false, null, new stdClass()] as $subject) {
                \expect(fn() => (new Expectation($subject))->{$matcher}())
                    ->toThrow(InvalidExpectationValue::class, 'value of type [iterable]');
                \expect(fn() => (new Expectation($subject))->not->{$matcher}())
                    ->toThrow(InvalidExpectationValue::class, 'value of type [iterable]');
            }
        }
    });

    \test('the four calls that reported green where the incumbent reports red', function (): void {
        // Verbatim, the regression this refusal exists for: each of
        // these passed here and errored in the incumbent, so a migrated
        // suite went green exactly where it had been red.
        \expect(fn() => (new Expectation([]))->not->toBeHostname())->toThrow(InvalidExpectationValue::class);
        \expect(fn() => (new Expectation([]))->not->toBeUuid())->toThrow(InvalidExpectationValue::class);
        \expect(fn() => (new Expectation(1))->not->toBeIpAddress())->toThrow(InvalidExpectationValue::class);
        \expect(fn() => (new Expectation('x'))->not->toHaveCamelCaseKeys())->toThrow(InvalidExpectationValue::class);
    });

    \test('each refuses the item rather than the container', function (): void {
        // The container is an array and these matchers want strings, so
        // guarding the container would refuse every ->each call and
        // guarding neither would refuse none. Measured against the
        // incumbent: the item is the subject.
        \expect(['localhost', 'crucible.example'])->each->toBeHostname();
        \expect(fn() => (new Expectation([[]]))->each->toBeHostname())
            ->toThrow(InvalidExpectationValue::class);
        \expect(fn() => (new Expectation([[]]))->each->not->toBeHostname())
            ->toThrow(InvalidExpectationValue::class);
    });

    \test('a key-case matcher answers about any iterable, not only an array', function (): void {
        // The incumbent's guard is on `iterable` and it answers about
        // everything it admits, so refusing an ArrayObject as a
        // non-array would fail a suite the incumbent passes. No corpus
        // value could have caught this: every one is an array or a
        // scalar.
        \expect(new ArrayObject(['camelKey' => 1]))->toHaveCamelCaseKeys();
        \expect(new ArrayObject(['not-camel' => 1]))->not->toHaveCamelCaseKeys();
        \expect(new ArrayObject([0 => 1, 1 => 2]))->toHaveCamelCaseKeys();
    });

    \test('the cast family refuses a subject PHP cannot render as a string', function (): void {
        // These twelve read the subject THROUGH a cast rather than
        // requiring a string, which is why [] reaches toBeAlpha as
        // 'Array'. The one subject a cast cannot handle is an object
        // with no __toString, and there the incumbent dies with a raw
        // PHP Error -- measured, all twelve, both forms. Crucible
        // declines instead of crashing: the same answer, which is no
        // answer, so ->not cannot turn it into a pass in either engine.
        $matchers = ['toBeAlpha', 'toBeAlphaNumeric', 'toBeDigits', 'toBeLowercase', 'toBeUppercase',
            'toBeSlug', 'toBeCamelCase', 'toBeKebabCase', 'toBeSnakeCase', 'toBeStudlyCase',
            'toBeEmail', 'toBeUrl'];

        foreach ($matchers as $matcher) {
            foreach ([new stdClass(), static fn(): int => 1, new ArrayObject(['a' => 1])] as $subject) {
                \expect(fn() => (new Expectation($subject))->{$matcher}())
                    ->toThrow(InvalidExpectationValue::class, 'can be read as a string');
                \expect(fn() => (new Expectation($subject))->not->{$matcher}())
                    ->toThrow(InvalidExpectationValue::class, 'can be read as a string');
            }
        }
    });

    \test('a subject PHP can render is not refused', function (): void {
        // The boundary is renderability, not "is not an object". A
        // Stringable and a resource both cast, and the incumbent answers
        // about both, so refusing either would fail a suite it passes.
        // Each call below would throw if the guard were drawn at
        // is_object() instead.
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'abc';
            }
        };

        // Answered, not refused -- and answered about the string the
        // object declares. PHP accepts a Stringable wherever a string
        // parameter is declared, so reading it is a contract, not a
        // liberty, and no quirk gates it.
        \expect($stringable)->toBeAlpha();
        \expect(new SplFileInfo('abc'))->toBeAlpha();

        // A resource renders too, as 'Resource id #N'. That reduces to
        // something truthy, so toBeSlug PASSES on it -- the one matcher
        // in the family where the cast changes the answer rather than
        // coinciding with it, and the incumbent passes there as well.
        // toBeAlpha still fails: the rendering carries digits and spaces.
        $handle = \fopen('php://memory', 'r+');
        \expect($handle)->toBeResource()->not->toBeAlpha()->toBeSlug();

        if (\is_resource($handle)) {
            \fclose($handle);
        }
    });

    \test('a rendering PHP endorses is read by default, with no quirk', function (): void {
        // An int, a float and a Stringable render the way var_export and
        // json_encode render them, and the engine raises no warning, so
        // discarding them was a gap in Crucible rather than a defect of
        // the incumbent's it was declining to copy. Quirking these would
        // have offered to restore a bug that was Crucible's own.
        \expect(98)->toBeAlphaNumeric()->toBeDigits();
        \expect(1.5)->toBeSlug();
        \expect(-1)->toBeSlug();
        \expect(new SplFileInfo('abc'))->toBeAlpha();

        // The cast is what answers, not ctype_*: those read an int in
        // this range as a codepoint, which would make 98 the letter 'b'
        // and pass toBeAlpha. Measured in both engines -- it fails.
        \expect(98)->not->toBeAlpha();

        // 0 and 0.0 render as '0', which reduces to something empty --
        // so they fail toBeSlug in both engines, while 1.5 and -1 pass.
        // The cast is doing its job here; it is IsEmpty that answers.
        \expect(0)->not->toBeSlug();
        \expect(0.0)->not->toBeSlug();
    });

    \test('the matchers the incumbent does not guard stay unguarded', function (): void {
        // Fidelity, not tidiness: toBeEmail and toBeUrl take a
        // non-string and answer `false` in the incumbent — no refusal —
        // so neither may grow one here. toBeAlpha casts instead and
        // therefore PASSES on an array, which reaches it as 'Array'.
        \expect([])->not->toBeEmail()->not->toBeUrl();
        \expect(1)->not->toBeEmail()->not->toBeUrl();
        \expect([])->toBeAlpha();
    });
});

\describe('modifiers', function (): void {
    \test('each as a property fans matchers over items', function (): void {
        \expect([1, 2, 3])->each->toBeInt()->toBeGreaterThan(0);
        \expect(['a', 'b'])->each->not->toBeInt();
    });

    \test('each with a callback', function (): void {
        \expect([10, 20])->each(fn($number) => $number->toBeInt()->toBeGreaterThanOrEqual(10));
    });

    \test('sequence pairs expectations with items in order', function (): void {
        \expect(['first' => 'a', 'second' => 'bb'])->sequence(
            function (Expectation $item, string $key): void {
                $item->toBe('a');
                \expect($key)->toBe('first');
            },
            fn(Expectation $item) => $item->toHaveLength(2),
        );

        \expect([1, 2])->sequence(1, 2); // bare values mean toEqual
    });

    \test('when and unless gate parts of a chain', function (): void {
        \expect(10)
            ->when(true, fn($number) => $number->toBeInt())
            ->when(false, fn($number) => $number->toBeString()) // never runs
            ->unless(true, fn($number) => $number->toBeString()) // never runs
            ->unless(fn(): bool => false, fn($number) => $number->toBe(10));
    });

    \test('json decodes and continues the chain', function (): void {
        \expect('{"name":"crucible","versions":[1,2]}')
            ->json()
            ->toHaveKey('name', 'crucible')
            ->toHaveKey('versions.0', 1);
    });
});

\describe('higher-order expectations', function (): void {
    \test('property access descends into the value', function (): void {
        $user       = new stdClass();
        $user->name = 'Luciano';
        $user->tags = ['admin' => true];

        \expect($user)->name->toBe('Luciano');
        \expect($user)->tags->toHaveKey('admin');
        \expect(['deep' => ['key' => 'value']])->deep->toHaveKey('key');
    });

    \test('method calls forward to the value', function (): void {
        \expect(new ArrayObject([1, 2, 3]))->count()->toBe(3);
        \expect(new ArrayObject(['k' => 'v']))->getArrayCopy()->toHaveKey('k');
    });

    \test('scoped asserts mid-chain', function (): void {
        \expect(['a' => 1])->scoped(fn($e) => $e->toHaveKey('a'))->toHaveCount(1);
    });

    \test('extend registers a custom matcher', function (): void {
        \expect(null)->extend('toBeEspresso', function (): void {
            $this->toBe('espresso');
        });

        \expect('espresso')->toBeEspresso();
    });
});
