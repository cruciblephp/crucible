<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use ArrayAccess;
use Closure;
use LucianoPereira\Crucible\Architecture\Architecture;
use LucianoPereira\Crucible\Architecture\ArchPredicates;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Constraint\ArrayHasKey;
use LucianoPereira\Crucible\Assert\Constraint\Callback;
use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\Crucible\Assert\Constraint\DirectoryExists;
use LucianoPereira\Crucible\Assert\Constraint\FileExists;
use LucianoPereira\Crucible\Assert\Constraint\GreaterThan;
use LucianoPereira\Crucible\Assert\Constraint\HasCount;
use LucianoPereira\Crucible\Assert\Constraint\IsEmpty;
use LucianoPereira\Crucible\Assert\Constraint\IsEqual;
use LucianoPereira\Crucible\Assert\Constraint\IsFalse;
use LucianoPereira\Crucible\Assert\Constraint\IsIdentical;
use LucianoPereira\Crucible\Assert\Constraint\IsInfinite;
use LucianoPereira\Crucible\Assert\Constraint\IsInstanceOf;
use LucianoPereira\Crucible\Assert\Constraint\IsJson;
use LucianoPereira\Crucible\Assert\Constraint\IsList;
use LucianoPereira\Crucible\Assert\Constraint\IsNan;
use LucianoPereira\Crucible\Assert\Constraint\IsNull;
use LucianoPereira\Crucible\Assert\Constraint\IsTrue;
use LucianoPereira\Crucible\Assert\Constraint\IsType;
use LucianoPereira\Crucible\Assert\Constraint\LessThan;
use LucianoPereira\Crucible\Assert\Constraint\LogicalNot;
use LucianoPereira\Crucible\Assert\Constraint\MatchesRegularExpression;
use LucianoPereira\Crucible\Assert\Constraint\MatchesShape;
use LucianoPereira\Crucible\Assert\Constraint\ObjectHasProperty;
use LucianoPereira\Crucible\Assert\Constraint\StringContains;
use LucianoPereira\Crucible\Assert\Constraint\StringEndsWith;
use LucianoPereira\Crucible\Assert\Constraint\StringStartsWith;
use LucianoPereira\Crucible\Assert\Constraint\TraversableContains;
use LucianoPereira\Crucible\Assert\Constraint\TraversableContainsOnlyInstancesOf;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Assert\ValueType;
use LucianoPereira\Crucible\Configuration\Quirk;
use LucianoPereira\Crucible\Snapshot\Snapshots;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use Stringable;
use Throwable;

use function array_all;
use function array_any;
use function array_first;
use function array_key_exists;
use function array_map;
use function array_values;
use function class_exists;
use function class_implements;
use function class_parents;
use function class_uses;
use function count;
use function ctype_alnum;
use function ctype_alpha;
use function ctype_digit;
use function ctype_lower;
use function ctype_upper;
use function ctype_xdigit;
use function debug_backtrace;
use function enum_exists;
use function explode;
use function filter_var;
use function func_num_args;
use function implode;
use function in_array;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_countable;
use function is_float;
use function is_int;
use function is_iterable;
use function is_object;
use function is_readable;
use function is_resource;
use function is_string;
use function is_subclass_of;
use function is_writable;
use function iterator_to_array;
use function json_decode;
use function mb_strlen;
use function method_exists;
use function preg_match;
use function preg_replace;
use function property_exists;
use function sprintf;
use function str_contains;
use function trait_exists;
use function trigger_error;
use function trim;

use const FILTER_FLAG_HOSTNAME;
use const FILTER_VALIDATE_DOMAIN;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_IP;
use const FILTER_VALIDATE_MAC;
use const FILTER_VALIDATE_URL;

/**
 * The pest spec's expect() chain, compiled onto Crucible's constraint
 * engine (D-008: every dialect's assertions funnel through the same
 * constraints, so failures look identical everywhere and risky
 * detection counts them for free).
 *
 * `->not` negates exactly the next matcher; `->and($value)` starts a
 * fresh chain on a new value; `->each` fans the following matchers
 * out over every item of an iterable value. Property access that is
 * not a modifier is a higher-order expectation: `expect($user)->name`
 * is `expect($user->name)`.
 *
 * The value's type rides the chain for the analyser (D-128): see
 * `ExpectationChainReturnTypeExtension`.
 *
 * @template-covariant TValue = mixed
 *
 * @property-read Expectation<TValue> $each
 * @property-read Expectation<TValue> $not
 */
final class Expectation
{
    private const string UUID_PATTERN
        = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    /**
     * 26 characters of Crockford base32 — the alphabet without I, L, O
     * and U. Uppercase only, and no range check on the first character:
     * the spec restricts it to 0-7 while the timestamp fits 48 bits, but
     * the incumbent does not enforce that and neither does this.
     */
    private const string ULID_PATTERN = '/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D';

    /** Case-style vocabulary, shared by the toBe*Case and toHave*CaseKeys families. */
    /**
     * The case conventions exactly as Pest 5.1.1 writes them.
     *
     * They are character classes rather than shapes, so a separator is
     * legal anywhere (`'-a'`, `'a--b'` and `'---'` are all kebab-case),
     * digits are not legal at all, and camelCase/StudlyCase carry
     * length minimums. A stricter reading is defensible and Crucible
     * used to apply one — but D-004 makes the dialect's defaults the
     * incumbent's defaults, and D-008 gives each dialect an external
     * spec to implement rather than improve. The stricter set belongs
     * to the crucible dialect, which is where a surface has to earn its
     * existence beyond the Pest one.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const array CASE_PATTERNS = [
        'camelCase'  => '/^\p{Ll}\p{L}+$/uD',
        'kebab-case' => '/^[\p{Ll}\-]+$/uD',
        'snake_case' => '/^[\p{Ll}_]+$/uD',
        'StudlyCase' => '/^\p{Lu}\p{Ll}\p{L}+$/uD',
    ];

    /** @var array<non-empty-string, Closure> */
    private static array $extensions = [];

    /**
     * The incumbent bugs this run was asked to reproduce (`->quirks()`).
     * Empty by default: Crucible answers correctly and records the
     * divergence, because copying a defect down makes it the spec.
     *
     * @var list<Quirk>
     */
    private static array $quirks = [];

    private bool $negated = false;

    /** When true, matchers apply to every item of the iterable value. */
    private bool $spread = false;

    /**
     * Set on a higher-order child (property/key access): the
     * expectation this one's member access started from — a chain
     * always re-derives the next member from there, never from an
     * intermediate leaf. Null on a root expectation.
     */
    private ?self $root = null;

    /**
     * True once a matcher has run since the last member access: the
     * next ->member reads from $root, not from this leaf's value —
     * `->isOptional->toBeFalse()->isNullable` reads $root->isNullable,
     * not (bool)->isNullable. False right after a member access lets
     * a bare nested chain (`->a->b`) keep drilling into the leaf.
     * Mirrors real Pest's HigherOrderExpectation::$shouldReset.
     */
    private bool $shouldReset = false;

    /**
     * @param TValue $value
     */
    public function __construct(
        public readonly mixed $value,
    ) {}

    // ── Modifiers ────────────────────────────────────────────────

    public function and(mixed $value): self
    {
        return new self($value);
    }

    /**
     * With a callback, runs it against an expectation of every item;
     * without one, returns an expectation whose following matchers
     * apply to every item (the `->each->toBeInt()` form).
     *
     * @param ?Closure(self): mixed $callback
     */
    public function each(?Closure $callback = null): self
    {
        if (!$callback instanceof Closure) {
            $each         = new self($this->value);
            $each->spread = true;

            return $each;
        }

        foreach ($this->items('each()') as $item) {
            $callback(new self($item));
        }

        return $this;
    }

    /**
     * One expectation per item, in iteration order: a closure receives
     * (expectation, key); any other value means toEqual. The counts
     * must agree — a silent partial match would hide missing items.
     */
    public function sequence(mixed ...$expectations): self
    {
        $items = $this->items('sequence()');

        if (count($items) !== count($expectations)) {
            Assert::fail(sprintf(
                'sequence() received %d expectations for %d items.',
                count($expectations),
                count($items),
            ));
        }

        $position = 0;

        foreach ($items as $key => $item) {
            $expected = $expectations[$position++];

            if ($expected instanceof Closure) {
                $expected(new self($item), $key);
            } else {
                (new self($item))->toEqual($expected);
            }
        }

        return $this;
    }

    /**
     * @param bool|Closure(): bool  $condition
     * @param Closure(self): mixed $callback
     */
    public function when(bool|Closure $condition, Closure $callback): self
    {
        $condition = $condition instanceof Closure ? $condition() : $condition;

        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * @param bool|Closure(): bool  $condition
     * @param Closure(self): mixed $callback
     */
    public function unless(bool|Closure $condition, Closure $callback): self
    {
        $condition = $condition instanceof Closure ? $condition() : $condition;

        return $this->when(!$condition, $callback);
    }

    /**
     * Asserts the value is a JSON string and continues the chain on
     * the decoded document.
     */
    public function json(): self
    {
        $this->assert(new IsJson());

        // IsJson has just refused anything but a JSON string.
        $json = $this->value;

        return new self(is_string($json) ? json_decode($json, true) : null);
    }

    /**
     * Runs the callback with this expectation — the spec's escape
     * hatch for asserting on a nested object mid-chain.
     *
     * @param Closure(self): mixed $callback
     */
    public function scoped(Closure $callback): self
    {
        $callback($this);

        return $this;
    }

    /**
     * Registers a custom matcher; inside the closure `$this` is the
     * expectation, so `$this->value` and the built-in matchers are
     * available. Global for the process, per the spec.
     *
     * @param non-empty-string $name
     * @param-closure-this self $extend
     */
    public function extend(string $name, Closure $extend): void
    {
        self::$extensions[$name] = $extend;
    }

    /**
     * The callable-method spelling of ->not (real Pest supports both:
     * `expect($x)->not->toBe($y)` and `expect($x)->not()->toBe($y)`)
     * — without this, `__call('not', [])` mistook it for an unknown
     * method forwarded to the underlying value, confirmed against a
     * real spatie/laravel-data test (`expect($array)->not()
     * ->toHaveKey(...)`).
     */
    public function not(): self
    {
        return $this->negate();
    }

    // ── Higher-order expectations ────────────────────────────────

    /**
     * `expect($user)->name` is `expect($user->name)`; on arrays the
     * name is a key. `->each` is the spread modifier; `->not` negates
     * exactly the next matcher.
     */
    public function __get(string $name): self
    {
        if ($name === 'each') {
            return $this->each();
        }

        if ($name === 'not') {
            return $this->negate();
        }

        $root  = $this->root ?? $this;
        $child = new self($this->member($this->shouldReset ? $root->value : $this->value, $name));

        $child->root = $root;

        return $child;
    }

    /**
     * Custom matchers registered via extend() win; any other unknown
     * method call is forwarded to the underlying value and the chain
     * continues on its return value.
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        $extension = self::$extensions[$name] ?? null;

        if ($extension !== null) {
            $bound = Closure::bind($extension, $this, self::class) ?? $extension;
            $bound(...$arguments);

            return $this;
        }

        $root    = $this->root ?? $this;
        $subject = $this->shouldReset ? $root->value : $this->value;

        if (!is_object($subject)) {
            Assert::fail(sprintf(
                'Method %s() is neither a matcher nor callable on the expectation value (%s).',
                $name,
                Exporter::describe($subject),
            ));
        }

        $child       = new self($subject->{$name}(...$arguments));
        $child->root = $root;

        return $child;
    }

    // ── Identity, equality, truthiness ───────────────────────────

    public function toBe(mixed $expected): self
    {
        return $this->assert(new IsIdentical($expected));
    }

    public function toEqual(mixed $expected): self
    {
        return $this->assert(new IsEqual($expected));
    }

    /** The spec lists both spellings; they are one matcher. */
    public function toBeEqual(mixed $expected): self
    {
        return $this->toEqual($expected);
    }

    public function toEqualCanonicalizing(mixed $expected): self
    {
        return $this->assert(new IsEqual($expected, canonicalize: true));
    }

    public function toBeEqualCanonicalizing(mixed $expected): self
    {
        return $this->toEqualCanonicalizing($expected);
    }

    public function toEqualWithDelta(mixed $expected, float $delta): self
    {
        return $this->assert(new IsEqual($expected, delta: $delta));
    }

    public function toBeEqualWithDelta(mixed $expected, float $delta): self
    {
        return $this->toEqualWithDelta($expected, $delta);
    }

    public function toBeTrue(): self
    {
        return $this->assert(new IsTrue());
    }

    public function toBeFalse(): self
    {
        return $this->assert(new IsFalse());
    }

    public function toBeNull(): self
    {
        return $this->assert(new IsNull());
    }

    public function toBeEmpty(): self
    {
        return $this->assert(new IsEmpty());
    }

    public function toBeTruthy(): self
    {
        return $this->assert(new Callback(static fn(mixed $value): bool => (bool) $value, 'is truthy'));
    }

    public function toBeFalsy(): self
    {
        return $this->assert(new Callback(static fn(mixed $value): bool => (bool) $value === false, 'is falsy'));
    }

    /**
     * Any Phase 2 constraint, applied directly — the shared engine is
     * the spec's "PHPUnit constraint" parameter here.
     */
    public function toMatchConstraint(Constraint $constraint): self
    {
        return $this->assert($constraint);
    }

    // ── Types ────────────────────────────────────────────────────

    /**
     * @param class-string $class
     */
    public function toBeInstanceOf(string $class): self
    {
        return $this->assert(new IsInstanceOf($class));
    }

    public function toBeArray(): self
    {
        return $this->assert(new IsType(ValueType::Array));
    }

    public function toBeList(): self
    {
        return $this->assert(new IsList());
    }

    /**
     * The value fits a PHPStan type string (D-131):
     * `expect($json)->toMatchShape('array{id: positive-int, tags: list<string>}')`.
     * Checked here at run time; narrowed to the same type for the
     * analyser, the chain's value and the subject alike.
     */
    public function toMatchShape(string $shape): self
    {
        return $this->assert(new MatchesShape($shape));
    }

    public function toBeBool(): self
    {
        return $this->assert(new IsType(ValueType::Bool));
    }

    public function toBeCallable(): self
    {
        return $this->assert(new IsType(ValueType::Callable));
    }

    public function toBeFloat(): self
    {
        return $this->assert(new IsType(ValueType::Float));
    }

    public function toBeInt(): self
    {
        return $this->assert(new IsType(ValueType::Int));
    }

    public function toBeIterable(): self
    {
        return $this->assert(new IsType(ValueType::Iterable));
    }

    public function toBeNumeric(): self
    {
        return $this->assert(new IsType(ValueType::Numeric));
    }

    public function toBeObject(): self
    {
        return $this->assert(new IsType(ValueType::Object));
    }

    public function toBeResource(): self
    {
        return $this->assert(new IsType(ValueType::Resource));
    }

    public function toBeScalar(): self
    {
        return $this->assert(new IsType(ValueType::Scalar));
    }

    public function toBeString(): self
    {
        return $this->assert(new IsType(ValueType::String));
    }

    public function toBeNan(): self
    {
        return $this->assert(new IsNan());
    }

    public function toBeInfinite(): self
    {
        return $this->assert(new IsInfinite());
    }

    // ── Comparison ───────────────────────────────────────────────

    public function toBeGreaterThan(mixed $expected): self
    {
        return $this->assert(new GreaterThan($expected));
    }

    public function toBeGreaterThanOrEqual(mixed $expected): self
    {
        return $this->assert(new LogicalNot(new LessThan($expected)));
    }

    public function toBeLessThan(mixed $expected): self
    {
        return $this->assert(new LessThan($expected));
    }

    public function toBeLessThanOrEqual(mixed $expected): self
    {
        return $this->assert(new LogicalNot(new GreaterThan($expected)));
    }

    /**
     * Inclusive on both ends; works on anything PHP can order —
     * numbers, strings, DateTimeInterface.
     */
    public function toBeBetween(mixed $lowest, mixed $highest): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => $value >= $lowest && $value <= $highest,
            'is between ' . Exporter::describe($lowest) . ' and ' . Exporter::describe($highest),
        ));
    }

    /**
     * @param list<mixed> $values
     */
    public function toBeIn(array $values): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => in_array($value, $values, true),
            'is in the expected list',
        ));
    }

    // ── Strings ──────────────────────────────────────────────────

    /**
     * @param non-empty-string $prefix
     */
    public function toStartWith(string $prefix): self
    {
        return $this->assert(new StringStartsWith($prefix));
    }

    /**
     * @param non-empty-string $suffix
     */
    public function toEndWith(string $suffix): self
    {
        return $this->assert(new StringEndsWith($suffix));
    }

    /**
     * @param non-empty-string $pattern
     */
    public function toMatch(string $pattern): self
    {
        return $this->assert(new MatchesRegularExpression($pattern));
    }

    public function toBeJson(): self
    {
        return $this->assert(new IsJson());
    }

    public function toBeUrl(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'is a URL',
        ), SubjectRule::ReadableAsString);
    }

    public function toBeUuid(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1,
            'is a UUID',
        ), SubjectRule::IsString);
    }

    public function toBeUlid(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && preg_match(self::ULID_PATTERN, $value) === 1,
            'is a ULID',
        ), SubjectRule::IsString);
    }

    public function toBeEmail(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'is an email address',
        ), SubjectRule::ReadableAsString);
    }

    /**
     * A hostname: valid host syntax, one label or many. `localhost` and
     * `db-1.internal` both qualify — this asks whether a name *could*
     * address a host, not whether it is registrable.
     */
    public function toBeHostname(): self
    {
        return $this->assert($this->reduced(
            static fn(string $subject): mixed => filter_var($subject, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME),
            new LogicalNot(new IsEmpty()),
            'is a hostname',
        ), SubjectRule::IsString);
    }

    /**
     * A domain name: a hostname with more than one label. The single dot
     * is the whole distinction against toBeHostname() — measured against
     * the incumbent, the two agree on every input except `localhost`,
     * which is a hostname and not a domain. Nothing here asks for a
     * plausible top-level domain: `127.0.0.1` and `example.` are both
     * domains to the incumbent, and a stricter reading would fail
     * suites that pass there.
     */
    public function toBeDomain(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value)
                && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                && str_contains($value, '.'),
            'is a domain name',
        ), SubjectRule::IsString);
    }

    public function toBeIpAddress(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'is an IP address',
        ), SubjectRule::IsString);
    }

    public function toBeMacAddress(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && filter_var($value, FILTER_VALIDATE_MAC) !== false,
            'is a MAC address',
        ), SubjectRule::IsString);
    }

    /**
     * Sluggable: reducing the value to a URL segment leaves something
     * behind. This is the incumbent's meaning and not the obvious one —
     * `'run tests'`, `'a@b'` and `'---a---'` all pass, because each
     * still yields a slug. The strict "already in slug form" reading is
     * spelled toBeKebabCase(), and giving two names one meaning would
     * leave the incumbent's suites failing here for no reason.
     *
     * Nothing is transliterated, which is why `'ß'` and `'日本'` fail:
     * they reduce to nothing at all.
     *
     * `'0'` fails and `'9'` passes, matching the incumbent, and that is
     * not a special case either engine wrote down. The predicate is
     * "reduces to a NON-EMPTY slug", and emptiness is [IsEmpty] --
     * `empty('0')` is true in PHP, so `'0'` fails for exactly the reason
     * `'!!!'`, `'---'` and `'   '` do, all four with the same message,
     * while `'00'` passes. Measured in both engines.
     *
     * This used to be a hand-written `$slug !== ''` plus a quirk that
     * restored the incumbent's answer, on the reading that rejecting
     * `'0'` was a falsy-string bug. Composing the primitive instead
     * makes the incumbent's answer fall out, and the quirk had nothing
     * left to bridge.
     */
    public function toBeSlug(): self
    {
        return $this->assert($this->reduced(
            static fn(string $subject): string => trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $subject), '-'),
            new LogicalNot(new IsEmpty()),
            'can be converted to a slug',
        ), SubjectRule::ReadableAsString);
    }

    /**
     * Hexadecimal digits, either case, and nothing else. The `0x` prefix
     * is rejected — measured against the incumbent, which takes `'ff'`
     * and `'FF'` and refuses `'0xff'`. Accepting the prefix would pass
     * suites the incumbent fails, the more expensive direction to be
     * wrong in.
     */
    public function toBeHexadecimal(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && $value !== '' && ctype_xdigit($value),
            'is hexadecimal',
        ), SubjectRule::IsString);
    }


    /**
     * The arch matchers, and the one reading D1 settles.
     *
     * A subject is resolved the way `arch()` resolves a target — the
     * declared symbol it names, PLUS everything beneath it in the
     * configured source — through the one rule both spellings share, so
     * they cannot drift into meaning different things by the same
     * words. That subsumes the value reading rather than competing with
     * it: `expect(Foo::class)` resolves to exactly `{Foo}`, which is the
     * same answer asking about the string itself gave.
     *
     * A symbol outside the configured source still answers for itself.
     * Pure targeting would have said "not in your source, therefore
     * nothing to check" about a vendor class someone named on purpose.
     *
     * **An empty match passes, and warns.** That is the incumbent's
     * behaviour — measured: an arch expectation matching nothing passes
     * vacuously there — so a Pest suite gets Pest's verdict and Pest's
     * exit code. Crucible disagrees out loud instead of silently: the
     * warning names the target, `--fail-on-warning` turns it into a
     * failure, and D-088's position that a rule targeting nothing
     * enforces nothing stays available without being imposed on a
     * borrowed suite.
     *
     * The empty case short-circuits BEFORE negation reaches it, but only
     * once negation has become a predicate of its own. ✓ Measured
     * 2026-09-07: a hand-written `not->` form is an ordinary rule, so an
     * empty layer passes it vacuously; the `__call` fallback runs the
     * positive rule and fails when it passes, so there an empty layer is
     * a FAILURE. `toBeCasedCorrectly` is the only arch matcher on it.
     *
     * ✓ `->not` is not a logical inversion here. pest 5.1.1's
     * OppositeExpectation hand-writes a SECOND per-class predicate per
     * matcher and asserts it over every target, keeping the universal
     * quantifier. A matcher with no $negated form keeps the inversion,
     * which is what the incumbent's `__call` fallback does.
     *
     * @param Closure(class-string): bool  $predicate
     * @param non-empty-string             $description
     * @param ?Closure(class-string): bool $negated     the incumbent's own `not->` predicate, where it has one
     * @param ?non-empty-string            $negatedDescription
     */
    private function arch(
        Closure $predicate,
        string $description,
        ?Closure $negated = null,
        ?string $negatedDescription = null,
    ): self {
        if ($this->negated && $negated instanceof Closure) {
            $this->negated = false;
            $predicate     = $negated;
            $description   = $negatedDescription ?? $description;
        }

        return $this->archOverTargets(static fn(array $targets): Closure => $predicate, $description);
    }

    /**
     * The same, for a matcher whose answer depends on the whole target
     * set rather than on one class at a time: the dependency matchers,
     * where a reference between two TARGETED classes does not cross the
     * boundary the rule is about. The factory is called once the
     * targets are known, so the set is the resolved one even when the
     * subject is spread.
     *
     * @param Closure(list<class-string>): (Closure(class-string): bool) $factory
     * @param non-empty-string                                           $description
     */
    private function archOverTargets(Closure $factory, string $description): self
    {
        if (is_string($this->value) && $this->value !== '' && !$this->spread && self::targetsOf($this->value) === []) {
            trigger_error(
                sprintf('Nothing matches %s, so this expectation asserts nothing.', $this->value),
                E_USER_WARNING,
            );

            // Still negated means no `not->` predicate to swap in, so the
            // incumbent is on __call and inverts the vacuous pass.
            if (!$this->negated) {
                $this->shouldReset = true;

                return $this;
            }
        }

        return $this->assert(new Callback(
            static function (mixed $value) use ($factory): bool {
                if (!is_string($value) || $value === '') {
                    return false;
                }

                $targets = self::targetsOf($value);

                if ($targets === []) {
                    return true;
                }

                $predicate = $factory($targets);

                foreach ($targets as $class) {
                    if (!$predicate($class)) {
                        return false;
                    }
                }

                return true;
            },
            $description,
        ));
    }

    /**
     * The dependency and documentation matchers, in the pest spelling.
     *
     * Every one of these delegates to {@see ArchPredicates}, which is
     * also what `arch()` calls — one implementation to be right or
     * wrong, so the two spellings cannot answer differently. The
     * alternative was a second copy per matcher, which is how one
     * verdict classification became five and produced a false green
     * (D-108).
     */
    public function toExtendNothing(): self
    {
        return $this->arch(
            ArchPredicates::extendsNothing(),
            'extends nothing',
            ArchPredicates::extendsSomething(),
            'extends a class',
        );
    }

    public function toImplementNothing(): self
    {
        return $this->arch(
            ArchPredicates::implementsNothing(),
            'implements nothing',
            ArchPredicates::implementsSomething(),
            'implements an interface',
        );
    }

    public function toUseStrictTypes(): self
    {
        return $this->arch(
            ArchPredicates::declaresStrictTypes(Architecture::universe()),
            'declares strict types',
            ArchPredicates::declaresNoStrictTypes(Architecture::universe()),
            'declares no strict types',
        );
    }

    public function toUseStrictEquality(): self
    {
        return $this->arch(
            ArchPredicates::usesStrictEquality(Architecture::universe()),
            'uses strict equality',
            ArchPredicates::usesNoStrictEquality(Architecture::universe()),
            'uses no strict equality',
        );
    }

    public function toBeCasedCorrectly(): self
    {
        return $this->arch(ArchPredicates::casedCorrectly(Architecture::universe()), 'is named as its path implies');
    }

    public function toHaveMethodsDocumented(): self
    {
        return $this->arch(
            ArchPredicates::documentsMethods(Architecture::universe()),
            'documents its methods',
            ArchPredicates::documentsNoMethods(Architecture::universe()),
            'documents none of its methods',
        );
    }

    public function toHavePropertiesDocumented(): self
    {
        return $this->arch(
            ArchPredicates::documentsProperties(Architecture::universe()),
            'documents its properties',
            ArchPredicates::documentsNoProperties(Architecture::universe()),
            'documents none of its properties',
        );
    }

    /** Nothing it references crosses out of the target into the source. */
    public function toUseNothing(): self
    {
        $this->refuseNegatedDependency('toUseNothing');

        return $this->archOverTargets(
            static fn(array $targets): Closure => ArchPredicates::referencesNothingOwn(Architecture::universe(), $targets),
            'uses nothing from the source',
        );
    }

    /** Nothing outside the target references it. */
    public function toBeUsedInNothing(): self
    {
        $this->refuseNegatedDependency('toBeUsedInNothing');

        return $this->archOverTargets(
            static fn(array $targets): Closure => ArchPredicates::usedByNothing(Architecture::universe(), $targets),
            'is used by nothing in the source',
        );
    }

    /**
     * Pest has no negated spelling for these two, so neither has this.
     *
     * ✓ Measured 2026-09-06 against pest 5.1.1:
     * `expect(...)->not->toUseNothing()` raises
     * `Pest\Exceptions\InvalidExpectation: The expectation
     * [not->toUseNothing] does not exist`, and the same for
     * `toBeUsedInNothing`. They are the ONLY two arch matchers it
     * refuses that way — `not->toBeFinal`, `not->toHavePropertiesDocumented`
     * and the rest all invert normally, so this is a hole in the
     * incumbent's surface rather than a rule about arch matchers.
     *
     * Answering where the incumbent refuses is not a better dialect,
     * it is a different one. The Pest dialect's job is Pest's answers,
     * and 50 cells of the arch grid were Crucible inventing a spelling
     * a Pest suite cannot contain.
     *
     * @param non-empty-string $matcher
     */
    private function refuseNegatedDependency(string $matcher): void
    {
        if (!$this->negated) {
            return;
        }

        $this->negated     = false;
        $this->shouldReset = true;

        throw new InvalidExpectationValue(sprintf(
            'The expectation [not->%s] does not exist.',
            $matcher,
        ));
    }

    /**
     * @return list<class-string>
     */
    private static function targetsOf(string $target): array
    {
        $found = Architecture::universe()->matching($target);

        // A symbol the configured source does not hold still answers for
        // itself: naming a vendor class is a question about that class,
        // not about your architecture.
        if (!in_array($target, $found, true)
            && (class_exists($target) || interface_exists($target) || trait_exists($target))) {
            /** @var class-string $target */
            $found[] = $target;
        }

        return $found;
    }

    // -- architecture presets (D-066): class-shape matchers over a
    // class-string subject — reflection at match time, negation free
    // through ->not like every other matcher. The full arch()
    // namespace-sweeping tier stays a declined subsystem (recorded).

    public function toBeFinal(): self
    {
        return $this->arch(
            // !enum_exists first, exactly as toBeReadonly does below.
            // ✓ pest 5.1.1: `! enum_exists($object->name) && … isFinal()`.
            // A PHP enum reflects as final, so without the exclusion the
            // two engines answer differently about every enum in the
            // source — the incumbent calls this "not a final class"
            // rather than "final by construction", the same distinction
            // it already draws for readonly.
            static fn(string $class): bool => !enum_exists($class) && class_exists($class) && (new ReflectionClass($class))->isFinal(),
            'is a final class',
            // ✓ The enum exclusion is on BOTH sides upstream: an enum fails
            // `toBeFinal` and `not->toBeFinal` alike.
            static fn(string $class): bool => !enum_exists($class) && !(new ReflectionClass($class))->isFinal(),
            'is not a final class',
        );
    }

    public function toBeAbstract(): self
    {
        // interface_exists() too, not class_exists() alone. ✓ pest
        // 5.1.1, 2026-09-06: it reflects whatever its object
        // description holds and asks `isAbstract()`, and PHP answers
        // TRUE for an interface — so `expect('…\Interfaces')
        // ->toBeAbstract()` passes there. Gating on class_exists()
        // made Crucible answer no to the same source.
        return $this->arch(
            static fn(string $class): bool => (class_exists($class) || interface_exists($class))
                && (new ReflectionClass($class))->isAbstract(),
            'is an abstract class',
            static fn(string $class): bool => !(new ReflectionClass($class))->isAbstract(),
            'is not an abstract class',
        );
    }

    public function toBeReadonly(): self
    {
        // ✓ The incumbent excludes enums explicitly
        // (`!enum_exists(...) && ...->isReadOnly()`), so an enum is not
        // readonly here either — immutable by construction is not the
        // same claim.
        return $this->arch(
            static fn(string $class): bool => !enum_exists($class) && class_exists($class) && (new ReflectionClass($class))->isReadOnly(),
            'is a readonly class',
            static fn(string $class): bool => !enum_exists($class) && !(new ReflectionClass($class))->isReadOnly(),
            'is not a readonly class',
        );
    }

    public function toBeInterface(): self
    {
        return $this->arch(
            static fn(string $class): bool => interface_exists($class),
            'is an interface',
            static fn(string $class): bool => !interface_exists($class),
            'is not an interface',
        );
    }

    /** Plural reads correctly over a namespace; the incumbent aliases it too. */
    public function toBeInterfaces(): self
    {
        return $this->toBeInterface();
    }

    public function toBeEnum(): self
    {
        return $this->arch(
            static fn(string $class): bool => enum_exists($class),
            'is an enum',
            static fn(string $class): bool => !enum_exists($class),
            'is not an enum',
        );
    }

    public function toBeEnums(): self
    {
        return $this->toBeEnum();
    }

    public function toBeTrait(): self
    {
        return $this->arch(
            static fn(string $class): bool => trait_exists($class),
            'is a trait',
            static fn(string $class): bool => !trait_exists($class),
            'is not a trait',
        );
    }

    public function toBeTraits(): self
    {
        return $this->toBeTrait();
    }

    /**
     * @param class-string $parent
     */
    public function toExtend(string $parent): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && is_subclass_of($value, $parent),
            sprintf('extends %s', $parent),
        ));
    }

    /**
     * @param class-string $interface
     */
    public function toImplement(string $interface): self
    {
        return $this->assert(new Callback(
            static function (mixed $value) use ($interface): bool {
                if (!is_string($value) || (!class_exists($value) && !interface_exists($value))) {
                    return false;
                }

                $implements = class_implements($value);

                return $implements !== false && in_array($interface, $implements, true);
            },
            sprintf('implements %s', $interface),
        ));
    }

    /**
     * @param class-string $trait
     */
    public function toUseTrait(string $trait): self
    {
        return $this->assert(new Callback(
            static function (mixed $value) use ($trait): bool {
                if (!is_string($value) || !class_exists($value)) {
                    return false;
                }

                $parents = class_parents($value);

                return array_any(
                    [$value, ...array_values($parents === false ? [] : $parents)],
                    static function (string $class) use ($trait): bool {
                        $uses = class_uses($class);

                        return $uses !== false && in_array($trait, $uses, true);
                    },
                );
            },
            sprintf('uses the trait %s', $trait),
        ));
    }

    /**
     * @param non-empty-string $method
     */
    public function toHaveMethod(string $method): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && method_exists($value, $method),
            sprintf('declares the method %s()', $method),
        ));
    }

    /**
     * An array, not a variadic — the incumbent's signature, and the one
     * a ported suite will be written against.
     *
     * @param list<non-empty-string> $methods
     */
    public function toHaveMethods(array $methods): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value) && array_all(
                $methods,
                static fn(string $method): bool => method_exists($value, $method),
            ),
            sprintf('declares the methods %s', implode(', ', array_map(
                static fn(string $method): string => $method . '()',
                $methods,
            ))),
        ));
    }

    /**
     * A class, and only a class. `class_exists()` answers true for an
     * enum, so an enum would otherwise satisfy both this and
     * toBeEnum() — which makes neither of them mean anything.
     */
    public function toBeClass(): self
    {
        return $this->arch(
            static fn(string $class): bool => class_exists($class) && !enum_exists($class),
            'is a class',
            // ✓ `! class_exists()` alone upstream, and that is true for an
            // enum — so an enum fails both forms here too.
            static fn(string $class): bool => !class_exists($class),
            'is not a class',
        );
    }

    public function toBeClasses(): self
    {
        return $this->toBeClass();
    }

    public function toBeIntBackedEnum(): self
    {
        return $this->arch(
            static fn(string $class): bool => self::enumBackedBy($class) === 'int',
            'is an int-backed enum',
            static fn(string $class): bool => self::enumBackedBy($class) !== 'int',
            'is not an int-backed enum',
        );
    }

    public function toBeIntBackedEnums(): self
    {
        return $this->toBeIntBackedEnum();
    }

    public function toBeStringBackedEnum(): self
    {
        return $this->arch(
            static fn(string $class): bool => self::enumBackedBy($class) === 'string',
            'is a string-backed enum',
            static fn(string $class): bool => self::enumBackedBy($class) !== 'string',
            'is not a string-backed enum',
        );
    }

    public function toBeStringBackedEnums(): self
    {
        return $this->toBeStringBackedEnum();
    }

    /**
     * Invokable: `$subject(...)` is callable because `__invoke` exists.
     * Takes a class name or an instance, so a closure passes too.
     */
    public function toBeInvokable(): self
    {
        // An ARCH matcher, not a value one. ✓ pest 5.1.1, 2026-09-06:
        // `Targeted::make(…, $object->reflectionClass->hasMethod('__invoke'))`
        // — it resolves a namespace to a set and asks about each member.
        // Crucible had it as a Callback over the raw subject, so
        // `expect('ArchFixture\Kind\Invokable')->toBeInvokable()` asked
        // whether the NAMESPACE STRING had an __invoke method and said
        // no. It was excluded from the value grid as arch and
        // implemented as a value matcher, which is exactly the gap
        // between the two axes that let this stand.
        return $this->arch(
            static fn(string $class): bool => (class_exists($class) || interface_exists($class))
                && (new ReflectionClass($class))->hasMethod('__invoke'),
            'is invokable',
            static fn(string $class): bool => !(new ReflectionClass($class))->hasMethod('__invoke'),
            'is not invokable',
        );
    }

    /**
     * @param class-string $attribute
     */
    public function toHaveAttribute(string $attribute): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => is_string($value)
                && (class_exists($value) || interface_exists($value) || enum_exists($value) || trait_exists($value))
                && (new ReflectionClass($value))->getAttributes($attribute) !== [],
            sprintf('carries the attribute %s', $attribute),
        ));
    }

    /**
     * Declares a constructor of its own. An inherited one counts —
     * reflection reports it, and a subclass that inherits a constructor
     * genuinely has one.
     */
    public function toHaveConstructor(): self
    {
        return $this->arch(
            static fn(string $class): bool => (class_exists($class) || enum_exists($class))
                && (new ReflectionClass($class))->getConstructor() instanceof ReflectionMethod,
            'has a constructor',
            static fn(string $class): bool => !(new ReflectionClass($class))->hasMethod('__construct'),
            'has no constructor',
        );
    }

    public function toHaveDestructor(): self
    {
        return $this->arch(
            static fn(string $class): bool => method_exists($class, '__destruct'),
            'has a destructor',
            static fn(string $class): bool => !method_exists($class, '__destruct'),
            'has no destructor',
        );
    }

    public function toBeAlpha(): self
    {
        return $this->assert(new Callback(
            static function (mixed $value): bool {
                $subject = self::stringify($value);

                return $subject !== null && ctype_alpha($subject);
            },
            'contains only alphabetic characters',
        ), SubjectRule::ReadableAsString);
    }

    public function toBeAlphaNumeric(): self
    {
        return $this->assert(new Callback(
            static function (mixed $value): bool {
                $subject = self::stringify($value);

                return $subject !== null && ctype_alnum($subject);
            },
            'contains only alphanumeric characters',
        ), SubjectRule::ReadableAsString);
    }

    public function toBeDigits(): self
    {
        return $this->assert(new Callback(
            static function (mixed $value): bool {
                $subject = self::stringify($value);

                return $subject !== null && ctype_digit($subject);
            },
            'contains only digits',
        ), SubjectRule::ReadableAsString);
    }

    public function toBeLowercase(): self
    {
        return $this->assert(new Callback(
            static function (mixed $value): bool {
                $subject = self::stringify($value);

                if ($subject === null) {
                    return false;
                }

                return ctype_lower($subject);
            },
            'is lowercase',
        ), SubjectRule::ReadableAsString);
    }

    public function toBeUppercase(): self
    {
        return $this->assert(new Callback(
            static function (mixed $value): bool {
                $subject = self::stringify($value);

                if ($subject === null) {
                    return false;
                }

                return ctype_upper($subject);
            },
            'is uppercase',
        ), SubjectRule::ReadableAsString);
    }

    public function toBeCamelCase(): self
    {
        return $this->assert($this->caseConstraint('camelCase'), SubjectRule::ReadableAsString);
    }

    public function toBeKebabCase(): self
    {
        return $this->assert($this->caseConstraint('kebab-case'), SubjectRule::ReadableAsString);
    }

    public function toBeSnakeCase(): self
    {
        return $this->assert($this->caseConstraint('snake_case'), SubjectRule::ReadableAsString);
    }

    public function toBeStudlyCase(): self
    {
        return $this->assert($this->caseConstraint('StudlyCase'), SubjectRule::ReadableAsString);
    }

    // ── Filesystem ───────────────────────────────────────────────

    public function toBeDirectory(): self
    {
        return $this->assert(new DirectoryExists());
    }

    public function toBeReadableDirectory(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => (new DirectoryExists())->matches($value)
                && is_string($value) && is_readable($value),
            'is a readable directory',
        ));
    }

    public function toBeWritableDirectory(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => (new DirectoryExists())->matches($value)
                && is_string($value) && is_writable($value),
            'is a writable directory',
        ));
    }

    public function toBeFile(): self
    {
        return $this->assert(new FileExists());
    }

    public function toBeReadableFile(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => (new FileExists())->matches($value)
                && is_string($value) && is_readable($value),
            'is a readable file',
        ));
    }

    public function toBeWritableFile(): self
    {
        return $this->assert(new Callback(
            static fn(mixed $value): bool => (new FileExists())->matches($value)
                && is_string($value) && is_writable($value),
            'is a writable file',
        ));
    }

    // ── Collections ──────────────────────────────────────────────

    /**
     * Strings check substrings; everything else checks membership —
     * per the pest spec's dual behavior.
     */
    public function toContain(mixed ...$needles): self
    {
        if ($this->negated) {
            return $this->assertNegatedCompound(
                function () use ($needles): void {
                    foreach ($needles as $needle) {
                        $this->assert(is_string($this->value) && is_string($needle)
                            ? new StringContains($needle)
                            : new TraversableContains($needle, strict: true));
                    }
                },
                'contains all of ' . Exporter::export($needles),
            );
        }

        foreach ($needles as $needle) {
            $this->assert(is_string($this->value) && is_string($needle)
                ? new StringContains($needle)
                : new TraversableContains($needle, strict: true));
        }

        return $this;
    }

    /** Membership by loose equality — objects need not be the same instance. */
    public function toContainEqual(mixed ...$needles): self
    {
        if ($this->negated) {
            return $this->assertNegatedCompound(
                function () use ($needles): void {
                    foreach ($needles as $needle) {
                        $this->assert(new TraversableContains($needle, strict: false));
                    }
                },
                'contains all of ' . Exporter::export($needles),
            );
        }

        foreach ($needles as $needle) {
            $this->assert(new TraversableContains($needle, strict: false));
        }

        return $this;
    }

    /**
     * @param class-string $class
     */
    public function toContainOnlyInstancesOf(string $class): self
    {
        return $this->assert(new TraversableContainsOnlyInstancesOf($class));
    }

    public function toHaveCount(int $count): self
    {
        return $this->assert(new HasCount($count));
    }

    /** String length for strings, element count for everything countable. */
    public function toHaveLength(int $length): self
    {
        if (is_string($this->value)) {
            return $this->assert(new Callback(
                static fn(mixed $value): bool => is_string($value) && mb_strlen($value) === $length,
                sprintf('has length %d', $length),
            ));
        }

        return $this->assert(new HasCount($length));
    }

    public function toHaveSameSize(mixed $expected): self
    {
        if (!is_countable($expected) && !is_iterable($expected)) {
            Assert::fail('toHaveSameSize() expects a countable or iterable to compare against.');
        }

        $size = is_countable($expected)
            ? count($expected)
            : count(iterator_to_array($expected, preserve_keys: false));

        return $this->assert(new HasCount($size));
    }

    /**
     * A dot in the key traverses nested arrays, per the spec; the
     * optional value is compared with toEqual semantics.
     */
    public function toHaveKey(int|string $key, mixed $value = null): self
    {
        if (is_string($key) && str_contains($key, '.')) {
            $checkValue = func_num_args() > 1;

            return $this->assert(new Callback(
                static function (mixed $subject) use ($key, $value, $checkValue): bool {
                    [$found, $member] = self::dotGet($subject, $key);

                    return $found && (!$checkValue || (new IsEqual($value))->matches($member));
                },
                sprintf("has the key '%s'", $key) . (func_num_args() > 1 ? ' with the expected value' : ''),
            ));
        }

        $this->assert(new ArrayHasKey($key));

        if (func_num_args() > 1 && is_array($this->value)) {
            (new self($this->value[$key] ?? null))->toEqual($value);
        }

        return $this;
    }

    /**
     * A list of keys, or key => value pairs (string keys with a dot
     * traverse, as in toHaveKey).
     *
     * @param array<array-key, mixed> $keys
     */
    public function toHaveKeys(array $keys): self
    {
        if ($this->negated) {
            return $this->assertNoneFound($keys, function (int|string $key, mixed $value): array {
                if (is_int($key) && (is_string($value) || is_int($value))) {
                    return [$value, fn(): mixed => $this->toHaveKey($value)];
                }

                return [$key, fn(): mixed => $this->toHaveKey($key, $value)];
            });
        }

        foreach ($keys as $key => $value) {
            if (is_int($key) && (is_string($value) || is_int($value))) {
                $this->toHaveKey($value);
            } else {
                $this->toHaveKey($key, $value);
            }
        }

        return $this;
    }

    public function toHaveCamelCaseKeys(): self
    {
        return $this->assert($this->keyCaseConstraint('camelCase'), SubjectRule::IsIterable);
    }

    public function toHaveKebabCaseKeys(): self
    {
        return $this->assert($this->keyCaseConstraint('kebab-case'), SubjectRule::IsIterable);
    }

    public function toHaveSnakeCaseKeys(): self
    {
        return $this->assert($this->keyCaseConstraint('snake_case'), SubjectRule::IsIterable);
    }

    public function toHaveStudlyCaseKeys(): self
    {
        return $this->assert($this->keyCaseConstraint('StudlyCase'), SubjectRule::IsIterable);
    }

    /**
     * The actual array must contain every key => value pair of the subset.
     *
     * @param array<array-key, mixed> $array
     */
    public function toMatchArray(array $array): self
    {
        return $this->assert(new Callback(
            static function (mixed $subject) use ($array): bool {
                if (!is_array($subject)) {
                    return false;
                }
                return array_all($array, fn($value, $key) => array_key_exists($key, $subject) && (new IsEqual($value))->matches($subject[$key]));
            },
            'matches the array ' . Exporter::export($array),
        ));
    }

    // ── Objects ──────────────────────────────────────────────────

    /**
     * @param non-empty-string $property
     */
    public function toHaveProperty(string $property, mixed $value = null): self
    {
        if (func_num_args() > 1) {
            return $this->assert(new Callback(
                static fn(mixed $subject): bool => is_object($subject)
                    && property_exists($subject, $property)
                    && (new IsEqual($value))->matches($subject->{$property}),
                sprintf("has the property '%s' with the expected value", $property),
            ));
        }

        return $this->assert(new ObjectHasProperty($property));
    }

    /**
     * A list of property names, or name => value pairs.
     *
     * @param array<array-key, mixed> $properties
     */
    public function toHaveProperties(array $properties): self
    {
        if ($this->negated) {
            return $this->assertNegatedCompound(
                function () use ($properties): void {
                    foreach ($properties as $key => $value) {
                        if (is_int($key) && is_string($value) && $value !== '') {
                            $this->toHaveProperty($value);

                            continue;
                        }

                        $name = (string) $key;

                        if ($name === '') {
                            Assert::fail('toHaveProperties() received an empty property name.');
                        }

                        $this->toHaveProperty($name, $value);
                    }
                },
                'has all of ' . Exporter::export(array_values($properties)),
            );
        }

        foreach ($properties as $key => $value) {
            if (is_int($key) && is_string($value) && $value !== '') {
                $this->toHaveProperty($value);

                continue;
            }

            $name = (string) $key;

            if ($name === '') {
                Assert::fail('toHaveProperties() received an empty property name.');
            }

            $this->toHaveProperty($name, $value);
        }

        return $this;
    }

    /**
     * Every key => value pair of the subset, against object properties.
     *
     * @param array<array-key, mixed> $object
     */
    public function toMatchObject(array $object): self
    {
        return $this->assert(new Callback(
            static function (mixed $subject) use ($object): bool {
                if (!is_object($subject)) {
                    return false;
                }
                return array_all($object, fn($value, $property) => property_exists($subject, (string) $property) && (new IsEqual($value))->matches($subject->{$property}));
            },
            'matches the object ' . Exporter::export($object),
        ));
    }

    // ── Exceptions ───────────────────────────────────────────────

    /**
     * The value must be a closure; it is invoked here.
     *
     * @param class-string<Throwable>|non-empty-string $exception class name, or a message fragment
     */
    public function toThrow(string $exception, ?string $message = null): self
    {
        if (!$this->value instanceof Closure) {
            Assert::fail('toThrow() expects the expectation value to be a closure.');
        }

        try {
            ($this->value)();
        } catch (Throwable $thrown) {
            if ($this->negated) {
                Assert::fail('Expected no ' . $exception . ' to be thrown, but caught ' . $thrown::class . '.');
            }

            $this->assertThrown($thrown, $exception, $message);

            return $this;
        }

        if ($this->negated) {
            Assert::assertThat(null, new IsNull()); // counted: the absence was verified

            return $this;
        }

        Assert::fail('Expected ' . $exception . ' to be thrown, but nothing was.');
    }

    /**
     * The snapshot matcher (D-042) — the engine's one snapshot
     * assertion, reached from this dialect. Negation is meaningless
     * for a recording assertion and refuses loudly.
     *
     * @param ?non-empty-string $name
     */
    public function toMatchSnapshot(?string $name = null): self
    {
        if ($this->negated) {
            Assert::fail('->not->toMatchSnapshot() has no meaning: a snapshot records, it does not enumerate what a value is not.');
        }

        Snapshots::match($this->value, $name);

        return $this;
    }

    /**
     * The inline snapshot matcher (D-076): the expected value lives in
     * this call's argument, not a `.snap` file. `--update-snapshots`
     * rewrites the source to record it; once recorded, the exported
     * value must equal it. Negation is as meaningless here as for the
     * file flavor. The call site is captured here, from the caller.
     */
    public function toMatchInlineSnapshot(?string $expected = null): self
    {
        if ($this->negated) {
            Assert::fail('->not->toMatchInlineSnapshot() has no meaning: a snapshot records, it does not enumerate what a value is not.');
        }

        $frame = array_first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1));

        Snapshots::matchInline($this->value, $expected, is_string($frame['file'] ?? null) ? $frame['file'] : '', is_int($frame['line'] ?? null) ? $frame['line'] : 0);

        return $this;
    }

    private function assertThrown(Throwable $thrown, string $exception, ?string $message): void
    {
        if (class_exists($exception) || interface_exists($exception)) {
            Assert::assertInstanceOf($exception, $thrown);
        } else {
            // A bare string is a message fragment, per the spec.
            $message ??= $exception;
        }

        if ($message !== null && !str_contains($thrown->getMessage(), $message)) {
            Assert::fail('The exception message "' . $thrown->getMessage() . '" does not contain "' . $message . '".');
        }

        Assert::assertThat(true, new IsTrue()); // count the successful expectation
    }

    // ── Internals ────────────────────────────────────────────────

    /**
     * The backing type of an enum, or null when the name is not an enum
     * or the enum is pure. Reflection is the only thing that knows —
     * `enum_exists()` cannot tell a pure enum from a backed one.
     */
    /**
     * @param list<Quirk> $quirks
     */
    public static function configure(array $quirks): void
    {
        self::$quirks = $quirks;
    }

    private static function enumBackedBy(string $enum): ?string
    {
        if (!enum_exists($enum)) {
            return null;
        }

        $backing = (new ReflectionEnum($enum))->getBackingType();

        return $backing instanceof ReflectionNamedType ? $backing->getName() : null;
    }

    /**
     * $requires is the subject type the matcher declines to work
     * without — see refuse(). It is checked here rather than in the
     * matcher so the refusal happens before LogicalNot is applied, and
     * so ->each refuses per item without walking the value twice.
     */
    private function assert(Constraint $constraint, ?SubjectRule $requires = null): self
    {
        $applied = $this->negated ? new LogicalNot($constraint) : $constraint;

        if ($this->spread) {
            foreach ($this->items('each') as $item) {
                $this->refuse($item, $requires);
                Assert::assertThat($item, $applied);
            }
        } else {
            $this->refuse($this->value, $requires);
            Assert::assertThat($this->value, $applied);
        }

        $this->negated     = false;
        $this->shouldReset = true;

        return $this;
    }

    /**
     * The wrong-typed-subject refusal: eleven matchers in the spec
     * require a string or an iterable and decline anything else instead
     * of answering about it.
     *
     * Answering `false` instead reported GREEN where the incumbent
     * reports red. Positive, the two are the same outcome; negated they
     * are opposites, because a failure inverts and a refusal does not —
     * `expect([])->not->toBeHostname()` errors in the incumbent and
     * passed here, so a migrated suite went green exactly where it had
     * been red. Measured in both forms over the whole corpus, which is
     * why the sweep now records a refusal as its own third state rather
     * than folding it into `f`.
     *
     * Applied per SUBJECT, not per call: measured,
     * `expect([[]])->each->toBeHostname()` refuses the item while
     * `expect(['localhost'])->each->toBeHostname()` passes, so the
     * container's own type is never what is asked about.
     *
     * SubjectRule carries the three requirements and the wording for
     * each, measured one at a time against the running incumbent.
     */
    private function refuse(mixed $subject, ?SubjectRule $requires): void
    {
        if (!$requires instanceof SubjectRule || $requires->accepts($subject)) {
            return;
        }

        throw new InvalidExpectationValue($requires->refusal());
    }

    /**
     * assert() applies negation to exactly one constraint, then
     * consumes the flag — correct for a matcher with one assert()
     * call, wrong for one that loops over several (toContain(),
     * toContainEqual(), toHaveProperties()): only the loop's first
     * iteration would see $negated still true. Real Pest sidesteps
     * this because negation there lives entirely in a separate
     * wrapper object (OppositeExpectation), never a flag threaded
     * through the positive method itself — its generic fallback
     * (OppositeExpectation::__call, used by every matcher without a
     * bespoke override, which includes all three above) just runs the
     * whole positive call and inverts whether it threw. That gives a
     * weaker negation than "none of these" — "not *all* of these" —
     * confirmed against real Pest's own source, since toContain() has
     * no override there. $positive must run with $this->negated
     * already false (cleared below) so its own internal assert()
     * calls behave exactly as the un-negated method would.
     */
    private function assertNegatedCompound(Closure $positive, string $summary): self
    {
        $this->negated = false;

        try {
            $positive();
            $allSatisfied = true;
        } catch (AssertionFailedError) {
            $allSatisfied = false;
        }

        return $this->assert(new Callback(static fn(): bool => !$allSatisfied, $summary));
    }

    /**
     * The stricter sibling of assertNegatedCompound(): every item
     * must be individually absent, failing on the first one found —
     * matching real Pest's own toHaveKeys() override in
     * OppositeExpectation (unlike toContain() etc., which have no
     * override and so get the weaker generic fallback above).
     * $describeAndCheck must run with $this->negated already false
     * (cleared below), for the same reason as
     * assertNegatedCompound() — it returns [the name to report on
     * failure, a callable performing the positive-mode check for that
     * item], since toHaveKeys()'s int-indexed shorthand rows (a bare
     * key name, not key => value) mean $key itself is often just the
     * array's own position, not the thing being checked.
     *
     * @param array<array-key, mixed> $items
     * @param Closure(array-key, mixed): array{0: mixed, 1: Closure(): mixed} $describeAndCheck
     */
    private function assertNoneFound(array $items, Closure $describeAndCheck): self
    {
        $this->negated = false;

        foreach ($items as $key => $value) {
            [$name, $check] = $describeAndCheck($key, $value);

            try {
                $check();
            } catch (AssertionFailedError) {
                continue;
            }

            Assert::fail(sprintf('Expecting %s not to have the key %s.', Exporter::export($this->value), Exporter::export($name)));
        }

        Assert::countSatisfiedAssertion();

        return $this;
    }

    /**
     * The value as key-preserving items, for each()/sequence().
     *
     * @return array<array-key, mixed>
     */
    private function items(string $modifier): array
    {
        if (!is_iterable($this->value)) {
            Assert::fail($modifier . ' expects the expectation value to be iterable.');
        }

        return is_array($this->value) ? $this->value : iterator_to_array($this->value);
    }

    /** A fresh expectation on the same value, negating the next matcher. */
    private function negate(): self
    {
        $negated              = new self($this->value);
        $negated->negated     = true;
        $negated->spread      = $this->spread;
        $negated->root        = $this->root;
        $negated->shouldReset = $this->shouldReset;

        return $negated;
    }

    /** Higher-order member access: object property, or array key. */
    /**
     * A missing property or key is null, not a failure — real Pest's
     * own retrieve() is `$value->$key ?? $default`, the same silent
     * fallback (confirmed against Pest's Retrievable trait). A real
     * spatie/laravel-data test relies on exactly this: it chains
     * ->dataCollectionClass (the real property, on DataPropertyType,
     * is spelled dataCollectABLEClass — the test has had this typo
     * for a while) ->toBeNull(), which only ever passed because the
     * missing-property fallback IS null.
     */
    private function member(mixed $subject, string $name): mixed
    {
        // property_exists() is true for a declared-but-uninitialized
        // typed property, but reading it directly still fatals
        // ("must not be accessed before initialization") — real Pest's
        // own retrieve() is $value->$key ?? $default specifically to
        // stay safe there: ?? uses isset() semantics, which treats an
        // uninitialized typed property as unset instead of fataling
        // (confirmed against a real spatie/laravel-data case: a
        // property left uninitialized by disabled name-mapping).
        if (is_object($subject) && property_exists($subject, $name)) {
            return $subject->{$name} ?? null;
        }

        if (is_array($subject) && array_key_exists($name, $subject)) {
            return $subject[$name];
        }

        if ($subject instanceof ArrayAccess && $subject->offsetExists($name)) {
            return $subject[$name];
        }

        return null;
    }

    /**
     * @param key-of<self::CASE_PATTERNS> $style
     */
    private function caseConstraint(string $style): Callback
    {
        return new Callback(
            static function (mixed $value) use ($style): bool {
                $subject = self::stringify($value);

                return $subject !== null && preg_match(self::CASE_PATTERNS[$style], $subject) === 1;
            },
            'is ' . $style,
        );
    }

    /**
     * Reduce the subject, then let an EXISTING constraint decide.
     *
     * This is how the incumbent builds this family, and the difference is
     * not stylistic. A matcher that spells its own predicate out by hand
     * is an independent chance to disagree, and each disagreement then has
     * to be argued into "who is right" -- which is where quirks come from.
     * A matcher composed from a primitive inherits that primitive's
     * answers, so the verdict is settled by the same rule both engines
     * already share.
     *
     * The description stays ours. Fidelity is owed at the level of the
     * ANSWER, so the verdict comes from the primitive while the failure
     * message keeps naming the subject and the predicate -- the incumbent
     * reports "Failed asserting that false is true" here, which is a
     * verdict Crucible matches and a message it need not copy.
     *
     * The stringify() step is the cast family's; for a matcher guarded
     * by SubjectRule::IsString the subject is already a string when the
     * constraint runs, so it is the identity there.
     *
     * @param callable(string): mixed $reduce
     */
    private function reduced(callable $reduce, Constraint $inner, string $description): Callback
    {
        return new Callback(
            static function (mixed $value) use ($reduce, $inner): bool {
                $subject = self::stringify($value);

                return $subject !== null && $inner->evaluate($reduce($subject), '', true) === true;
            },
            $description,
        );
    }

    /**
     * The subject as the matcher should see it: the string itself, or
     * null for anything that is not one.
     *
     * Under Quirk::StringifiedSubject it is instead what PHP's cast
     * makes of the value, which is how `[]` reaches `toBeAlpha()` as
     * `'Array'` in the incumbent. The array case is spelled out rather
     * than cast, because casting one emits a warning that would surface
     * as a test failure rather than the verdict being reproduced.
     */
    private static function stringify(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        // A rendering PHP itself endorses, so reading the subject
        // through it is a cast Crucible was missing, not a bug to gate.
        if (is_int($value) || is_float($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return match (true) {
            is_array($value) => 'Array',
            $value === null  => '',
            is_bool($value)  => $value ? '1' : '',
            // 'Resource id #N'. Measured: the incumbent casts one like
            // anything else, and toBeSlug() is where the answer differs
            // rather than merely coinciding -- it reduces to something
            // truthy, where every other matcher in the family rejects
            // the digits and the spaces either way.
            is_resource($value) => (string) $value,
            default             => null,
        };
    }

    /**
     * String keys at every depth must match the style; integer keys
     * are exempt (a list inside a payload is fine).
     *
     * Any iterable, not only an array. The incumbent's guard is on
     * `iterable` and it answers about what it admits: measured,
     * `expect(new ArrayObject(['camelCase' => 1]))->toHaveCamelCaseKeys()`
     * passes and the same object with a `'not-camel'` key fails.
     * Refusing either as a non-array would fail a suite the incumbent
     * passes, and no corpus value could have shown it — every one of
     * them is an array or a scalar.
     *
     * @param key-of<self::CASE_PATTERNS> $style
     */
    private function keyCaseConstraint(string $style): Callback
    {
        return new Callback(
            static function (mixed $value) use ($style): bool {
                if (!is_iterable($value)) {
                    return false;
                }

                return self::keysMatch(
                    is_array($value) ? $value : iterator_to_array($value),
                    self::CASE_PATTERNS[$style],
                );
            },
            'has ' . $style . ' keys',
        );
    }

    /**
     * @param array<array-key, mixed> $value
     * @param non-empty-string        $pattern
     */
    private static function keysMatch(array $value, string $pattern): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match($pattern, $key) !== 1) {
                return false;
            }

            if (is_array($item) && !self::keysMatch($item, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dot-notation traversal into nested arrays.
     *
     * @return array{bool, mixed} [found, value]
     */
    private static function dotGet(mixed $subject, string $key): array
    {
        $current = $subject;

        foreach (explode('.', $key) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            if ($current instanceof ArrayAccess && $current->offsetExists($segment)) {
                $current = $current[$segment];

                continue;
            }

            return [false, null];
        }

        return [true, $current];
    }
}
