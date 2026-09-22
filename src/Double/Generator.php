<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use LucianoPereira\Crucible\Generated\GeneratedCode;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

use function array_values;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function interface_exists;
use function is_object;
use function md5;
use function sprintf;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function trait_exists;
use function var_export;

/**
 * Builds the doubled class for an interface, a non-final class, or an
 * intersection of interfaces: every selected method forwards to
 * DoubleState::dispatch(); unselected methods keep their real
 * implementation (partial doubles, D-046). Code generation is
 * unavoidable for runtime type doubling in PHP; it is done from
 * reflection with typed rendering, evaluated once per specification
 * identity, and cached.
 *
 * Documented limits (the honest-boundary rule): final classes, enums,
 * and readonly classes cannot be doubled — use the real value.
 * Final methods on doubled classes keep their real implementation.
 * By-reference parameters are forwarded by value. State attaches
 * after construction, so a constructor that calls its own doubled
 * methods is out of contract.
 */
final class Generator
{
    /**
     * Generated classes are process-global and stateless (state is
     * injected per instance), so the cache is static: one generated
     * class per specification identity for the whole run.
     *
     * @var array<string, class-string>
     */
    private static array $cache = [];

    /**
     * @param class-string $target
     *
     * @return class-string
     */
    public function doubleClassFor(string $target): string
    {
        return $this->classFor(new DoubleSpecification([$target]));
    }

    /**
     * @return class-string
     */
    public function classFor(DoubleSpecification $specification): string
    {
        $identity = $specification->classIdentity();

        if (isset(self::$cache[$identity])) {
            return self::$cache[$identity];
        }

        $reflections = $this->validated($specification);
        $name        = $specification->className;

        // trait_exists is the third check because it is the one PHP
        // cannot be asked to survive: for a trait both class_exists and
        // interface_exists are false, and declaring over the name is an
        // uncatchable fatal — ✓ exit 255, catch (Throwable) never fires.
        // An enum needs no clause of its own; class_exists is already
        // true for one. Same lesson MockeryContainer.php:213-219 records.
        if ($name !== null && (class_exists($name) || interface_exists($name) || trait_exists($name))) {
            throw new DoubleCreationException(sprintf(
                'Cannot generate a double named %s: the name is already in use.',
                $name,
            ));
        }

        // Named by identity, not by call order: GeneratedCode::record()
        // files generated sources under md5($source) and calls the
        // directory "a set of distinct shapes rather than a log of
        // calls", which a counter made false — the same shape got a new
        // name whenever an unrelated double was created first.
        $name ??= 'CrucibleDouble_' . $reflections[0]->getShortName() . '_' . md5($identity);

        GeneratedCode::evaluate(
            $this->classCode($name, $reflections, $specification),
            'the double ' . $name . ' of ' . implode(', ', $specification->types),
        );

        /** @var class-string $name */
        return self::$cache[$identity] = $name;
    }

    /**
     * @return non-empty-list<ReflectionClass<object>>
     */
    private function validated(DoubleSpecification $specification): array
    {
        $reflections = [];

        foreach ($specification->types as $type) {
            $reflection = new ReflectionClass($type);

            if ($reflection->isFinal()) {
                throw new DoubleCreationException(sprintf('Cannot double final class %s.', $type));
            }

            if ($reflection->isEnum()) {
                throw new DoubleCreationException(sprintf('Cannot double enum %s; use a real case.', $type));
            }

            // Reserved names differ per API surface; the incumbent
            // FATALS on Mockery-verb collisions — a named error is the
            // better behavior (D-017 rule, extended in D-060).
            $reserved = $specification->mockerySurface
                ? ['shouldReceive', 'shouldNotReceive', 'allows', 'expects', 'mockery_verbs']
                : ['method', 'expects'];

            foreach ($reserved as $name) {
                if ($reflection->hasMethod($name)) {
                    throw new DoubleCreationException(sprintf(
                        'Cannot double %s: it declares %s(), which collides with the double configuration API.',
                        $type,
                        $name,
                    ));
                }
            }

            $reflections[] = $reflection;
        }

        if (count($reflections) > 1) {
            foreach ($reflections as $reflection) {
                if (!$reflection->isInterface()) {
                    throw new DoubleCreationException(sprintf(
                        'An intersection double takes interfaces only; %s is a class.',
                        $reflection->getName(),
                    ));
                }
            }
        }

        if ($specification->onlyMethods !== null) {
            $this->validateSelection($reflections[0], $specification->onlyMethods);
        }

        return $reflections;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param list<non-empty-string>  $methods
     */
    private function validateSelection(ReflectionClass $reflection, array $methods): void
    {
        // An interface double must implement every method — leaving
        // some out of onlyMethods() would leave them abstract. (The
        // spec's runner fatals here; a named error is the better
        // behavior, recorded in D-046.)
        if ($reflection->isInterface()) {
            throw new DoubleCreationException(sprintf(
                'onlyMethods() cannot be used when doubling interface %s: every method must be implemented. Double the full interface.',
                $reflection->getName(),
            ));
        }

        foreach ($methods as $method) {
            if (!$reflection->hasMethod($method)) {
                throw new DoubleCreationException(sprintf(
                    'onlyMethods(): %s has no method named %s().',
                    $reflection->getName(),
                    $method,
                ));
            }

            $candidate = $reflection->getMethod($method);

            if ($candidate->isPrivate() || $candidate->isFinal() || $candidate->isStatic()) {
                throw new DoubleCreationException(sprintf(
                    'onlyMethods(): %s::%s() cannot be doubled — %s methods keep their real implementation.',
                    $reflection->getName(),
                    $method,
                    $candidate->isPrivate() ? 'private' : ($candidate->isFinal() ? 'final' : 'static'),
                ));
            }
        }
    }

    /**
     * @param non-empty-list<ReflectionClass<object>> $reflections
     *
     * @return non-empty-string a class declaration, always
     */
    private function classCode(string $name, array $reflections, DoubleSpecification $specification): string
    {
        $base = $reflections[0];

        // The marker differs per API surface: the two grammars declare
        // incompatible expects() signatures (D-060).
        $marker = $specification->mockerySurface
            ? \LucianoPereira\Crucible\Double\Mockery\MockeryMock::class
            : \LucianoPereira\Crucible\Double\Mocked::class;

        if ($base->isInterface()) {
            $interfaces = [];

            foreach ($reflections as $reflection) {
                $interfaces[] = '\\' . $reflection->getName();
            }

            $relation = 'implements ' . implode(', ', $interfaces) . ', ' . $marker;
        } else {
            $relation = 'extends \\' . $base->getName() . ' implements ' . $marker;
        }

        $methods = [];

        foreach ($this->selectedMethods($reflections, $specification) as $method) {
            $methods[] = $this->methodCode($method, $method->getDeclaringClass());
        }

        // Suppressing the original __clone (spec: disableOriginalClone)
        // is generated code, not state — a clone must not run the
        // parent's logic at all.
        if (!$specification->callOriginalClone && !$base->isInterface()) {
            $methods[] = "    public function __clone(): void\n    {\n    }";
        }

        $api = $specification->mockerySurface ? <<<'PHP'
            public ?\LucianoPereira\Crucible\Double\DoubleState $__crucibleState = null;

            public function shouldReceive(string ...$methods): \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation
            {
                return \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation::receiving(\LucianoPereira\Crucible\Double\DoubleState::of($this->__crucibleState, $this, __FUNCTION__), \array_values($methods));
            }

            public function shouldNotReceive(string ...$methods): \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation
            {
                return \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation::receiving(\LucianoPereira\Crucible\Double\DoubleState::of($this->__crucibleState, $this, __FUNCTION__), \array_values($methods))->never();
            }

            /** @param array<string, mixed>|string ...$methods */
            public function allows(array|string ...$methods): \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation
            {
                return \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation::allowing(\LucianoPereira\Crucible\Double\DoubleState::of($this->__crucibleState, $this, __FUNCTION__), \array_values($methods));
            }

            public function expects(string ...$methods): \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation
            {
                return \LucianoPereira\Crucible\Double\Mockery\MockeryExpectation::receiving(\LucianoPereira\Crucible\Double\DoubleState::of($this->__crucibleState, $this, __FUNCTION__), \array_values($methods))->once();
            }

            public function __call(string $method, array $arguments): mixed
            {
                // $obj->{''}() is legal syntax and reaches here; the state keeps call names non-empty.
                if ($method === '') {
                    throw new \LucianoPereira\Crucible\Double\Mockery\MockeryBadMethodCallException('A double cannot answer a call to a method with an empty name.');
                }

                return \LucianoPereira\Crucible\Double\DoubleState::of($this->__crucibleState, $this, $method)->dispatch($this, $method, $arguments);
            }
            PHP : <<<'PHP'
            public ?\LucianoPereira\Crucible\Double\DoubleState $__crucibleState = null;

            public function method(string $method): \LucianoPereira\Crucible\Double\MethodConfigurator
            {
                return \LucianoPereira\Crucible\Double\DoubleState::of($this->__crucibleState, $this, __FUNCTION__)->configureMethod($method);
            }

            public function expects(\LucianoPereira\Crucible\Double\InvocationCount $count): \LucianoPereira\Crucible\Double\MethodConfigurator
            {
                return \LucianoPereira\Crucible\Double\DoubleState::of($this->__crucibleState, $this, __FUNCTION__)->expectInvocation($count);
            }
            PHP;

        // The traits are mixed into the DOUBLE, not into a parent it
        // extends, because that is what keeps their own methods real —
        // ✓ measured against the mockery-main oracle, whose trait mock
        // answers from the trait itself and reports the trait in
        // class_uses(). selectedMethods() leaves those names alone for
        // the same reason.
        $uses = $specification->traits === []
            ? ''
            : '    use \\' . implode(', \\', $specification->traits) . ";\n\n";

        return sprintf(
            "class %s %s {\n%s%s\n\n%s\n}",
            $name,
            $relation,
            $uses,
            $api,
            implode("\n\n", $methods),
        );
    }

    /**
     * The methods that forward to dispatch: every doubleable method,
     * or — under onlyMethods() — the listed ones. Abstract methods are
     * always generated whatever the list says: they cannot be left
     * unimplemented.
     *
     * @param non-empty-list<ReflectionClass<object>> $reflections
     *
     * @return list<ReflectionMethod>
     */
    private function selectedMethods(array $reflections, DoubleSpecification $specification): array
    {
        /** @var array<non-empty-string, ReflectionMethod> $methods */
        $methods = [];

        // A method the double gets FROM a mixed-in trait is left
        // alone: overriding it would replace the trait's own code with
        // a dispatch, which is exactly what the incumbent does not do.
        $real = [];

        foreach ($specification->traits as $trait) {
            foreach ((new ReflectionClass($trait))->getMethods() as $method) {
                if (!$method->isAbstract()) {
                    $real[strtolower($method->getName())] = true;
                }
            }
        }

        foreach ($reflections as $reflection) {
            foreach ($this->doubleableMethods($reflection, $specification->onlyMethods) as $method) {
                if (isset($real[strtolower($method->getName())])) {
                    continue;
                }

                $methods[$method->getName()] ??= $method;
            }
        }

        if ($specification->onlyMethods === null) {
            return array_values($methods);
        }

        $selected = [];

        foreach ($methods as $name => $method) {
            if ($method->isAbstract() || in_array($name, $specification->onlyMethods, true)) {
                $selected[] = $method;
            }
        }

        return $selected;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param ?list<non-empty-string> $onlyMethods
     *
     * @return list<ReflectionMethod>
     */
    private function doubleableMethods(ReflectionClass $reflection, ?array $onlyMethods): array
    {
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->isConstructor() || $method->isDestructor() || $method->isFinal()) {
                continue;
            }

            if ($method->isPrivate()) {
                continue;
            }

            if (in_array($method->getName(), ['__clone', '__get', '__set', '__isset', '__unset', '__sleep', '__wakeup'], true)) {
                continue;
            }

            // Concrete protected methods on classes keep their real
            // implementation by default — but onlyMethods() can still
            // name one explicitly: real-world classes (Monolog's
            // SocketHandler, for one) expose protected wrapper methods
            // specifically so tests can override them ("Wrapper to
            // allow mocking"), and the spec's onlyMethods() honors
            // that explicit request even for a protected method.
            $requested = $onlyMethods !== null && in_array($method->getName(), $onlyMethods, true);

            if (!$reflection->isInterface() && $method->isProtected() && !$method->isAbstract() && !$requested) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function methodCode(ReflectionMethod $method, ReflectionClass $reflection): string
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $parameters[] = $this->parameterCode($parameter, $reflection);
        }

        // Built-in interfaces declare TENTATIVE return types
        // (Countable::count(): int) — invisible to getReturnType();
        // omitting them makes every generated override a deprecation.
        $returnType = $method->getReturnType() ?? $method->getTentativeReturnType();
        $returns    = $returnType !== null ? ': ' . $this->typeCode($returnType, $reflection) : '';

        $signature = sprintf(
            '    public %sfunction %s(%s)%s',
            $method->isStatic() ? 'static ' : '',
            $method->getName(),
            implode(', ', $parameters),
            $returns,
        );

        if ($method->isStatic()) {
            return $signature . "\n    {\n        throw new \\LogicException('Static methods are not doubled.');\n    }";
        }

        // Through DoubleState::of(), not the nullable property: the
        // state is attached after construction, so every generated
        // method dereferences a nullable — 39 true reports from the
        // generated-code type tier. A double built outside TestDoubles
        // now says so instead of dying on a null.
        $dispatch = '\\LucianoPereira\\Crucible\\Double\\DoubleState::of($this->__crucibleState, $this, __FUNCTION__)'
            . '->dispatch($this, __FUNCTION__, \\func_get_args())';

        $body = match (true) {
            $returnType instanceof ReflectionNamedType && $returnType->getName() === 'void'
                => sprintf("        %s;", $dispatch),
            $returnType instanceof ReflectionNamedType && $returnType->getName() === 'never'
                    => sprintf("        %s;\n        throw new \\LogicException('A never-returning double method must be configured to throw.');", $dispatch),
            default => sprintf('        return %s;', $dispatch),
        };

        return $signature . "\n    {\n" . $body . "\n    }";
    }

    /**
     * A parameter default, as a constant expression.
     *
     * var_export() is right for everything that is not an object, and
     * wrong for objects in a way that cannot be recovered from: it
     * renders one as `\Cls::__set_state(array(...))`, a static call,
     * which is not a constant expression. PHP 8.1 made `new` legal in a
     * parameter initializer, so an interface declaring
     * `find(Config $c = new Config())` is ordinary code — and ✓ measured,
     * emitting the var_export form for it is not a catchable failure:
     * `try { eval(...) } catch (Throwable)` does not catch "Constant
     * expression contains invalid operations". The process dies, naming
     * neither the type being doubled nor the reason.
     *
     * PHP renders the initializer itself, and it is the text PHP
     * compiled the original from — no reconstruction, no guessing.
     * ✓ Measured: class constants come back fully qualified
     * (`new \App\Cfg(\App\Cfg::LIM)`) and a namespaced constant comes
     * back namespace-relative (`App\LIMIT`), which resolves correctly
     * because doubles are generated in the global namespace.
     *
     * Enum cases stay with var_export: they are objects, and it already
     * renders them as the constant expression `\Suit::Hearts`.
     */
    private function defaultCode(ReflectionParameter $parameter): string
    {
        $default = $parameter->getDefaultValue();

        if (!is_object($default) || $default instanceof UnitEnum) {
            return var_export($default, true);
        }

        $rendered = (string) $parameter;
        $opens    = strpos($rendered, ' = ');
        $closes   = strrpos($rendered, ' ]');

        if ($opens === false || $closes === false || $closes <= $opens) {
            throw new DoubleCreationException(sprintf(
                'Cannot double a method whose parameter $%s defaults to a %s: PHP did not render the '
                    . 'initializer, and there is no constant expression to emit in its place.',
                $parameter->getName(),
                $default::class,
            ));
        }

        return substr($rendered, $opens + 3, $closes - $opens - 3);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function parameterCode(ReflectionParameter $parameter, ReflectionClass $reflection): string
    {
        $type = $parameter->getType();

        $code = ($type !== null ? $this->typeCode($type, $reflection) . ' ' : '')
            . ($parameter->isPassedByReference() ? '&' : '')
            . ($parameter->isVariadic() ? '...' : '')
            . '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $code .= ' = ' . $this->defaultCode($parameter);
        } elseif ($parameter->allowsNull() && $parameter->isOptional() && !$parameter->isVariadic()) {
            $code .= ' = null';
        }

        return $code;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function typeCode(ReflectionType $type, ReflectionClass $reflection): string
    {
        if ($type instanceof ReflectionUnionType) {
            $members = [];

            foreach ($type->getTypes() as $member) {
                $members[] = $this->typeCode($member, $reflection);
            }

            return implode('|', $members);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $members = [];

            foreach ($type->getTypes() as $member) {
                $members[] = $this->typeCode($member, $reflection);
            }

            return implode('&', $members);
        }

        if (!$type instanceof ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();

        if ($name === 'self') {
            $name = '\\' . $reflection->getName();
        } elseif ($name === 'parent') {
            $parent = $reflection->getParentClass();
            $name   = $parent !== false ? '\\' . $parent->getName() : 'parent';
        } elseif (!$type->isBuiltin() && $name !== 'static') {
            $name = '\\' . $name;
        }

        if ($type->allowsNull() && !in_array($name, ['mixed', 'null'], true)) {
            return '?' . $name;
        }

        return $name;
    }
}
