<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\PhpUnit;

use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Attributes\DataProviderExternal;
use LucianoPereira\Crucible\Attributes\Depends;
use LucianoPereira\Crucible\Attributes\DependsUsingDeepClone;
use LucianoPereira\Crucible\Attributes\DependsUsingShallowClone;
use LucianoPereira\Crucible\Attributes\RequiresFunction;
use LucianoPereira\Crucible\Attributes\RequiresMethod;
use LucianoPereira\Crucible\Attributes\RequiresOperatingSystem;
use LucianoPereira\Crucible\Attributes\RequiresOperatingSystemFamily;
use LucianoPereira\Crucible\Attributes\RequiresPhp;
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
use LucianoPereira\Crucible\Attributes\RequiresSetting;
use LucianoPereira\Crucible\Attributes\Test;
use LucianoPereira\Crucible\Attributes\TestWith;
use LucianoPereira\Crucible\Attributes\TestWithJson;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\HookPlanner;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataParser;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;
use ReflectionClass;
use ReflectionMethod;

use function array_values;
use function explode;
use function is_array;
use function is_iterable;
use function is_string;
use function json_decode;
use function ltrim;
use function mb_strtolower;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The phpunit dialect frontend (D-008): turns a TestCase class into a
 * dialect-neutral TestGroup. A test method is a public method named
 * test*, carrying #[Test], or tagged with the legacy `@test` docblock;
 * parameterized tests expand to one TestDefinition per dataset row at
 * discovery time.
 */
final readonly class TestBuilder
{
    public function __construct(
        private MetadataParser $parser = new MetadataParser(),
    ) {}

    /**
     * @param class-string<TestCase> $className
     * @param non-empty-string       $file      project-relative path, used for TestIds
     */
    public function build(string $className, string $file): TestGroup
    {
        $class       = new ReflectionClass($className);
        $hooks       = HookPlanner::forClass($class);
        $definitions = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract()) {
                continue;
            }

            $methodName = $method->getName();

            /** @var non-empty-string $methodName */
            $metadata = $this->parser->forClassAndMethod($className, $methodName);

            // Class-level first, then method-level (PHPUnit's own
            // precedence: a class-wide requirement applies to every
            // test unless the method narrows it further) — matches
            // Monolog's own MongoDBHandlerTest, whose `@requires
            // extension mongodb` sits on the class docblock, not any
            // individual method.
            $docblockRequirements = [
                ...$this->docblockRequirements($class->getDocComment()),
                ...$this->docblockRequirements($method->getDocComment()),
            ];

            if ($docblockRequirements !== []) {
                $metadata = $metadata->mergedWith(\LucianoPereira\Crucible\Metadata\MetadataCollection::from(...$docblockRequirements));
            }

            if (!str_starts_with($methodName, 'test') && !$metadata->has(Test::class) && !$this->docblockMarksAsTest($method->getDocComment())) {
                continue;
            }

            $dependencies = $this->dependenciesOf($metadata, $method);

            foreach ($this->datasets($className, $methodName) as $datasetName => $arguments) {
                $id = new TestId(
                    $file,
                    $methodName,
                    $datasetName === '' ? null : $datasetName,
                );

                $definitions[] = new TestDefinition(
                    $id,

                    // Spec order: dataset arguments first, then the
                    // return values of depended-upon tests.
                    static fn(array $dependencyValues): mixed => (new $className())->invokeTest($methodName, [...$arguments, ...$dependencyValues], $hooks, $id->dataset),
                    $metadata,
                    $dependencies,
                );
            }
        }

        return new TestGroup(
            $className,
            $this->sortedByDependencies($definitions),
            static function () use ($className): void {
                $className::setUpBeforeClass();
            },
            static function () use ($className): void {
                $className::tearDownAfterClass();
            },
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private function dependenciesOf(\LucianoPereira\Crucible\Metadata\MetadataCollection $metadata, ReflectionMethod $method): array
    {
        $dependencies = [];

        foreach ($metadata->ofType(Depends::class) as $depends) {
            $dependencies[] = $depends->methodName;
        }

        foreach ($metadata->ofType(DependsUsingDeepClone::class) as $depends) {
            $dependencies[] = $depends->methodName;
        }

        foreach ($metadata->ofType(DependsUsingShallowClone::class) as $depends) {
            $dependencies[] = $depends->methodName;
        }

        // PHPUnit-compat fallback: the legacy `@depends` docblock form
        // still appears in real, actively-maintained PHPUnit suites
        // alongside the modern #[Depends] attribute (confirmed against
        // Monolog's own tests) — the compat surface's whole point is
        // running that code unmodified, so both forms are honored.
        foreach ($this->docblockDependencies($method) as $dependency) {
            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * PHPUnit-compat fallback: the legacy `@test` docblock tag marks a
     * method as a test regardless of its name, still used alongside
     * the modern #[Test] attribute and test-prefixed naming (confirmed
     * against Monolog's own GoogleCloudLoggingFormatterTest, whose
     * descriptively-named methods rely on it — without this, they're
     * silently never discovered at all, not even as skipped). `\b`
     * after `test` keeps this from matching `@testWith`/`@testdox`
     * (oracle-verified against PHPUnit's own tag tokenizer, DocBlock.php).
     */
    private function docblockMarksAsTest(string|false $doc): bool
    {
        return $doc !== false && preg_match('/@test\b/', $doc) === 1;
    }

    /**
     * @return list<non-empty-string>
     */
    private function docblockDependencies(ReflectionMethod $method): array
    {
        $doc = $method->getDocComment();

        if ($doc === false) {
            return [];
        }

        $dependencies = [];

        if (preg_match_all('/@depends\s+(?:clone\s+|shallowClone\s+)?(\S+)/', $doc, $matches) > 0) {
            /** @var list<non-empty-string> $names */
            $names = $matches[1];

            foreach ($names as $name) {
                $dependencies[] = $name;
            }
        }

        return $dependencies;
    }

    /**
     * PHPUnit-compat fallback: the legacy `@requires` docblock form
     * (extension/function/PHP/OS/OSFAMILY/setting), still common
     * alongside the modern #[Requires*] attributes (confirmed against
     * Monolog's own tests — e.g. its MongoDB handler tests skip via
     * `@requires extension mongodb`, never migrated to attributes).
     * Synthesizes the same attribute instances Requirements::unmet()
     * already reads, so no downstream code needs to change.
     *
     * @return list<\LucianoPereira\Crucible\Metadata\CrucibleAttribute>
     */
    private function docblockRequirements(string|false $doc): array
    {
        if ($doc === false) {
            return [];
        }

        $requirements = [];

        if (preg_match_all('/@requires\s+(\S+)[ \t]*([^\r\n]*)/', $doc, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $kind = mb_strtolower($match[1]);
            $rest = trim($match[2]);

            if ($rest === '') {
                continue;
            }

            $requirement = match ($kind) {
                'php'       => new RequiresPhp($rest),
                'os'        => new RequiresOperatingSystem($rest),
                'osfamily'  => new RequiresOperatingSystemFamily($rest),
                'extension' => $this->extensionRequirement($rest),
                'function'  => $this->functionOrMethodRequirement($rest),
                'setting'   => $this->settingRequirement($rest),
                default     => null,
            };

            if ($requirement !== null) {
                $requirements[] = $requirement;
            }
        }

        return $requirements;
    }

    private function extensionRequirement(string $rest): ?RequiresPhpExtension
    {
        $parts = preg_split('/\s+/', $rest, 2);

        if ($parts === false || $parts[0] === '') {
            return null;
        }

        return new RequiresPhpExtension($parts[0], isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null);
    }

    private function functionOrMethodRequirement(string $rest): RequiresFunction|RequiresMethod|null
    {
        $name = ltrim($rest, '\\');

        if ($name === '') {
            return null;
        }

        if (str_contains($name, '::')) {
            [$className, $methodName] = explode('::', $name, 2);

            if ($className === '' || $methodName === '') {
                return null;
            }

            /** @var class-string $className */
            return new RequiresMethod($className, $methodName);
        }

        return new RequiresFunction($name);
    }

    private function settingRequirement(string $rest): ?RequiresSetting
    {
        $parts = preg_split('/\s+/', $rest, 2);

        if ($parts === false || $parts[0] === '' || !isset($parts[1]) || $parts[1] === '') {
            return null;
        }

        return new RequiresSetting($parts[0], $parts[1]);
    }

    /**
     * Stable topological order: dependencies before dependents.
     * Unresolvable references (cycles, unknown names) keep declaration
     * order and are reported at run time.
     *
     * @param list<TestDefinition> $definitions
     *
     * @return list<TestDefinition>
     */
    private function sortedByDependencies(array $definitions): array
    {
        $remainingRows = [];

        foreach ($definitions as $definition) {
            $remainingRows[$definition->id->name] = ($remainingRows[$definition->id->name] ?? 0) + 1;
        }

        $ordered = [];
        $pending = $definitions;

        while ($pending !== []) {
            $progress = false;
            $next     = [];

            foreach ($pending as $definition) {
                $ready = true;

                foreach ($definition->dependencies as $dependency) {
                    if (($remainingRows[$dependency] ?? 0) > 0 && $dependency !== $definition->id->name) {
                        $ready = false;

                        break;
                    }
                }

                if ($ready) {
                    $ordered[] = $definition;
                    $remainingRows[$definition->id->name]--;
                    $progress = true;
                } else {
                    $next[] = $definition;
                }
            }

            if (!$progress) {
                // Cycle: append what is left in declaration order.
                foreach ($next as $definition) {
                    $ordered[] = $definition;
                }

                break;
            }

            $pending = $next;
        }

        return $ordered;
    }

    /**
     * Resolves every dataset source of a method into named argument
     * rows. A method without parameterization yields one unnamed row.
     *
     * @param class-string<TestCase> $className
     * @param non-empty-string       $methodName
     *
     * @return iterable<string, list<mixed>>
     */
    private function datasets(string $className, string $methodName): iterable
    {
        $metadata = $this->parser->forMethod($className, $methodName);

        $providers = [];

        foreach ($metadata->ofType(DataProvider::class) as $provider) {
            $providers[] = [$className, $provider->methodName];
        }

        foreach ($metadata->ofType(DataProviderExternal::class) as $provider) {
            $providers[] = [$provider->className, $provider->methodName];
        }

        $inline     = $metadata->ofType(TestWith::class);
        $inlineJson = $metadata->ofType(TestWithJson::class);

        if ($providers === [] && $inline === [] && $inlineJson === []) {
            yield '' => [];

            return;
        }

        $index = 0;

        foreach ($providers as [$providerClass, $providerMethod]) {
            $rows = $this->rowsFromProvider($providerClass, $providerMethod);

            foreach ($rows as $key => $row) {
                if (!is_array($row)) {
                    throw new ConfigurationException(sprintf(
                        'Data provider %s::%s() must yield arrays of arguments.',
                        $providerClass,
                        $providerMethod,
                    ));
                }

                $name = is_string($key) ? $key : (string) $index;

                yield $name => array_values($row);

                $index++;
            }
        }

        foreach ($inline as $row) {
            yield ($row->name ?? (string) $index) => array_values($row->data);

            $index++;
        }

        foreach ($inlineJson as $row) {
            $decoded = json_decode($row->json, true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new ConfigurationException(sprintf(
                    'TestWithJson on %s::%s() must decode to an array of arguments.',
                    $className,
                    $methodName,
                ));
            }

            yield ($row->name ?? (string) $index) => array_values($decoded);

            $index++;
        }
    }

    /**
     * @param class-string     $providerClass
     * @param non-empty-string $providerMethod
     *
     * @return iterable<mixed>
     */
    private function rowsFromProvider(string $providerClass, string $providerMethod): iterable
    {
        $method = (new ReflectionClass($providerClass))->getMethod($providerMethod);

        if (!$method->isStatic()) {
            throw new ConfigurationException(sprintf(
                'Data provider %s::%s() must be static.',
                $providerClass,
                $providerMethod,
            ));
        }

        $rows = $method->invoke(null);

        if (!is_iterable($rows)) {
            throw new ConfigurationException(sprintf(
                'Data provider %s::%s() must return an iterable.',
                $providerClass,
                $providerMethod,
            ));
        }

        return $rows;
    }
}
