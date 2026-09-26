<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Inline;

use Closure;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\Check;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Dialect\Pest\PestBuilder;
use LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Generated\GeneratedCode;
use LucianoPereira\Crucible\Generated\GeneratedCodeException;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;
use ParseError;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;

use function array_diff;
use function array_map;
use function class_exists;
use function get_defined_functions;
use function get_included_files;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;

/**
 * The inline dialect frontend (growth G2c, DESIGN.md D-014/D-035):
 * tests declared inside application source, not test files. Two tiers —
 * `#[Check]` I/O tables on the function or method itself, and doctests:
 * one expression per docblock tag, compiled into the file's namespace
 * and run on the shared expect() surface. Both come out as the same
 * dialect-neutral TestGroup every other frontend produces (D-008).
 */
final readonly class InlineBuilder
{
    public function __construct(
        private ClassLocator $locator = new ClassLocator(),
    ) {}

    /**
     * A doctest: `@crucible <expression>` on a starred docblock line — the
     * one grammar the builder, the pre-filter and the PHPStan shadow read.
     * `@crucible-equivalent` (D-134) and any other `@crucible-…` tag is not
     * one: the tag must be followed by whitespace.
     */
    public const string DOCTEST = '/^[ \t]*\**[ \t]*@crucible[ \t]+(.+)$/m';

    /**
     * The discovery pre-filter: whether a source file can possibly
     * declare inline tests, so discovery only loads files that opted in.
     * A false positive is not merely one extra require: the file's classes
     * are then declared before any test asks for them, and a mutated class
     * can no longer load in their place — every mutant in the file would
     * read as escaped (D-137). So the doctest test is the real grammar,
     * not a substring.
     */
    public static function hasMarkers(string $source): bool
    {
        return str_contains($source, '#[Check')
            || str_contains($source, 'Crucible\Attributes\Check')
            || preg_match(self::DOCTEST, $source) === 1;
    }

    /**
     * @param non-empty-string $file     absolute path
     * @param non-empty-string $relative project-relative path, used for TestIds
     */
    public function build(string $file, string $relative): ?TestGroup
    {
        // Only what this require ADDS to the function table can have
        // come from this file, so the candidates are a difference
        // across it rather than every user function in the process. The
        // require can autoload other files on the way, so the
        // declaring-file check still decides — it just runs over a
        // handful of names instead of thousands. A file something else
        // already loaded adds nothing, and an empty difference would be
        // wrong rather than merely small, so that case scans.
        $resolved = realpath($file);
        $loaded   = $resolved !== false && in_array($resolved, get_included_files(), true);
        $before   = $loaded ? null : get_defined_functions()['user'];

        require_once $file;

        $declared = $this->functionsIn(
            $file,
            $before === null ? get_defined_functions()['user'] : array_diff(get_defined_functions()['user'], $before),
        );

        $definitions = [];

        foreach ($this->locator->classesIn($file) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $reflection  = new ReflectionClass($class);
            $classGroups = $this->groupsOf($reflection);

            $definitions = [
                ...$definitions,
                ...$this->doctests($reflection->getDocComment(), $reflection->getShortName(), $reflection->getNamespaceName(), $classGroups, $relative),
            ];

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $name   = $reflection->getShortName() . '::' . $method->getName();
                $groups = [...$classGroups, ...$this->groupsOf($method)];

                $definitions = [
                    ...$definitions,
                    ...$this->checks($method, $reflection, $name, $groups, $relative),
                    ...$this->doctests($method->getDocComment(), $name, $reflection->getNamespaceName(), $groups, $relative),
                ];
            }
        }

        foreach ($declared as $function) {
            $name   = $function->getShortName();
            $groups = $this->groupsOf($function);

            if ($name === '') {
                continue;
            }

            $definitions = [
                ...$definitions,
                ...$this->checks($function, null, $name, $groups, $relative),
                ...$this->doctests($function->getDocComment(), $name, $function->getNamespaceName(), $groups, $relative),
            ];
        }

        return $definitions === [] ? null : new TestGroup($relative, $definitions);
    }

    /**
     * One test per #[Check] attribute, in declaration order. The claim
     * hierarchy: `throws` expects the exception type, `returns`
     * compares with the equality spec (toEqual semantics, matching
     * table()), and a bare check claims the call completes.
     *
     * @param ?ReflectionClass<object> $class
     * @param non-empty-string         $name
     * @param list<Group>              $groups
     * @param non-empty-string         $relative
     *
     * @return list<TestDefinition>
     */
    private function checks(ReflectionFunctionAbstract $target, ?ReflectionClass $class, string $name, array $groups, string $relative): array
    {
        $attributes = $target->getAttributes(Check::class);

        if ($attributes === []) {
            return [];
        }

        $metadata    = MetadataCollection::from(...$groups);
        $definitions = [];
        $seen        = [];

        foreach ($attributes as $index => $attribute) {
            $check = $attribute->newInstance();

            if ($check->expectsReturn() && $check->throws !== null) {
                throw new ConfigurationException(sprintf(
                    '#[Check] on %s (%s) claims both returns: and throws: — pick one.',
                    $name,
                    $relative,
                ));
            }

            $case = $check->name ?? 'check ' . ($index + 1);

            if (isset($seen[$case])) {
                throw new ConfigurationException(sprintf(
                    '#[Check] on %s (%s): two checks named "%s".',
                    $name,
                    $relative,
                    $case,
                ));
            }

            $seen[$case] = true;
            $invoker     = $this->invokerFor($target, $class, $name, $relative);

            $definitions[] = new TestDefinition(
                new TestId($relative, $name, $case),
                static function (array $dependencyValues) use ($invoker, $check, $name): mixed {
                    try {
                        $result = $invoker()(...$check->args);
                    } catch (Throwable $thrown) {
                        if ($check->throws === null) {
                            throw $thrown;
                        }

                        Assert::assertInstanceOf($check->throws, $thrown);

                        return null;
                    }

                    if ($check->throws !== null) {
                        Assert::fail(sprintf('Expected %s to be thrown by %s, but nothing was.', $check->throws, $name));
                    }

                    if ($check->expectsReturn()) {
                        Assert::assertEquals($check->returns, $result);
                    } else {
                        Assert::countSatisfiedAssertion(); // completing without throwing is the claim
                    }

                    return $result;
                },
                $metadata,
            );
        }

        return $definitions;
    }

    /**
     * How a check reaches its target. The outer closure defers
     * instance creation to run time, so every check gets a fresh
     * instance and constructor failures land as that test's error.
     * ReflectionMethod::getClosure() binds into the declaring scope,
     * so non-public targets work — checks are not limited to the
     * public surface.
     *
     * @param ?ReflectionClass<object> $class
     * @param non-empty-string         $name
     * @param non-empty-string         $relative
     *
     * @return Closure(): Closure
     */
    private function invokerFor(ReflectionFunctionAbstract $target, ?ReflectionClass $class, string $name, string $relative): Closure
    {
        if ($target instanceof ReflectionFunction) {
            return $target->getClosure(...);
        }

        if (!$target instanceof ReflectionMethod || !$class instanceof ReflectionClass) {
            throw new ConfigurationException(sprintf('#[Check] on %s (%s): not a checkable target.', $name, $relative));
        }

        if ($target->isStatic()) {
            return $target->getClosure(...);
        }

        if ($target->isAbstract() || $class->isAbstract()) {
            throw new ConfigurationException(sprintf(
                '#[Check] on %s (%s): an abstract target cannot be called. Check a concrete subclass instead.',
                $name,
                $relative,
            ));
        }

        $constructor = $class->getConstructor();

        if ($constructor instanceof ReflectionMethod && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new ConfigurationException(sprintf(
                '#[Check] on %s (%s): the constructor requires arguments, so no instance can be made. '
                . 'Check a static factory or use a @crucible doctest.',
                $name,
                $relative,
            ));
        }

        return static fn(): Closure => $target->getClosure($class->newInstance());
    }

    /**
     * One test per @crucible docblock tag: the rest of the line is a
     * single expression, compiled into the file's namespace now (a
     * typo is a load error, not a silent skip) and evaluated at run
     * time — documentation examples that cannot rot. `use` imports
     * are not visible to the compiled expression; spell names
     * namespace-relative or fully qualified.
     *
     * @param non-empty-string  $name
     * @param list<Group>       $groups
     * @param non-empty-string  $relative
     * @return list<TestDefinition>
     */
    private function doctests(string|false $docComment, string $name, string $namespace, array $groups, string $relative): array
    {
        $expressions = $this->expressionsIn($docComment);

        if ($expressions === []) {
            return [];
        }

        PestBuilder::ensureUsable();

        $metadata    = MetadataCollection::from(...$groups);
        $definitions = [];

        foreach ($expressions as $index => $expression) {
            $closure = $this->compile($expression, $namespace, $name, $relative);

            $definitions[] = new TestDefinition(
                new TestId($relative, $name, 'crucible ' . ($index + 1)),
                static fn(array $dependencyValues): mixed => $closure(),
                $metadata,
            );
        }

        return $definitions;
    }

    /**
     * @return list<non-empty-string>
     */
    private function expressionsIn(string|false $docComment): array
    {
        if ($docComment === false || !str_contains($docComment, '@crucible')) {
            return [];
        }

        preg_match_all(self::DOCTEST, $docComment, $matches);

        $expressions = [];

        foreach ($matches[1] as $expression) {
            // A one-line docblock leaves its closer on the tag's line.
            $expression = rtrim((string) preg_replace('~\s*\*/\s*$~', '', $expression));

            if ($expression !== '') {
                $expressions[] = $expression;
            }
        }

        return $expressions;
    }

    /**
     * @param non-empty-string $expression
     * @param non-empty-string $where
     * @param non-empty-string $relative
     */
    private function compile(string $expression, string $namespace, string $where, string $relative): Closure
    {
        $prefix = $namespace === '' ? '' : 'namespace ' . $namespace . '; ';

        try {
            $closure = GeneratedCode::evaluate(
                // Parenthesised: this is the one generator whose payload
                // is author text rather than Crucible-composed source,
                // and the documented one-expression contract was
                // otherwise unenforced. ✓ Neutral over PHP_INT_MAX,
                // array literals, match, fn, yield, new, ?? and
                // expect()->toBe(); an escape becomes a ParseError the
                // catch below already reports against the docblock.
                $prefix . 'return static function (): mixed { return (' . $expression . '); };',
                'the @crucible doctest on ' . $where,
            );
        } catch (GeneratedCodeException $broken) {
            // The seam's own message names the generated source, which
            // is the right diagnosis when Crucible wrote it. Here the
            // author wrote the expression, so the parse error is
            // reported against THEIR docblock and the source we wrapped
            // it in is noise. The ParseError travels as `previous`.
            $parse = $broken->getPrevious();

            throw new ConfigurationException(sprintf(
                'The @crucible doctest on %s (%s) does not parse: %s — in `%s`.',
                $where,
                $relative,
                $parse instanceof ParseError ? $parse->getMessage() : $broken->getMessage(),
                $expression,
            ), $broken->getCode(), $broken);
        }

        if (!$closure instanceof Closure) {
            throw new ConfigurationException(sprintf('The @crucible doctest on %s (%s) is not one expression: `%s`.', $where, $relative, $expression));
        }

        return $closure;
    }

    /**
     * @param ReflectionClass<object>|ReflectionFunctionAbstract $member
     *
     * @return list<Group>
     */
    private function groupsOf(ReflectionClass|ReflectionFunctionAbstract $member): array
    {
        return array_map(
            static fn($attribute): Group => $attribute->newInstance(),
            $member->getAttributes(Group::class),
        );
    }

    /**
     * Which of the candidate functions the file declares. Reflection
     * rather than a token scan — precise, and no token games to tell
     * functions from methods and closures, or to decide whether a
     * conditionally declared one counts.
     *
     * The candidates are what `build()`'s own require added, so this
     * runs over a handful of names; passed every user function in the
     * process it still answers correctly, just slowly.
     *
     * @param non-empty-string   $file
     * @param array<int, string> $candidates
     *
     * @return list<ReflectionFunction>
     */
    private function functionsIn(string $file, array $candidates): array
    {
        $resolved  = realpath($file);
        $functions = [];

        foreach ($candidates as $name) {
            $reflection = new ReflectionFunction($name);
            $declaredIn = $reflection->getFileName();

            if ($declaredIn === $file || ($resolved !== false && $declaredIn === $resolved)) {
                $functions[] = $reflection;
            }
        }

        return $functions;
    }
}
