<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\DoubleShapes;

use Countable;

/*
 * One declaration per SHAPE the double generator emits, so the branch
 * list in Generator::methodCode()/parameterCode()/typeCode() is driven
 * by a corpus rather than by whatever examples occurred to someone.
 *
 * APPEND ONLY. The sweep names these types, and a removal silently
 * shrinks what is proved.
 *
 * It lives in its own file rather than inside the test class because
 * one shape — an object parameter default — kills the process it is
 * generated in, so the sweep has to reach it from a subprocess.
 */

enum Suit: string
{
    case Hearts = 'H';
}

final class Config
{
    public const int LIMIT = 5;

    public function __construct(public int $limit = 10) {}
}

interface VoidReturn
{
    public function ping(): void;
}

interface NeverReturn
{
    public function boom(): never;
}

/** count(): int is TENTATIVE — invisible to getReturnType(). */
interface Tentative extends Countable {}

interface ByRef
{
    /** @param list<string> $out */
    public function fill(array &$out): void;
}

interface Variadic
{
    public function sum(int ...$n): int;
}

interface ScalarDefaults
{
    public function page(int $n = 1, string $s = 'a', bool $b = true, ?string $x = null): string;
}

interface EnumDefault
{
    public function of(Suit $s = Suit::Hearts): string;
}

interface UnionType
{
    public function widen(int|string $in): int|string|null;
}

interface SelfReturn
{
    public function chain(): self;
}

abstract class StaticMethod
{
    public static function make(): static
    {
        return new static();
    }

    abstract public function name(): string;
}

/**
 * The one that fatals. PHP 8.1+ allows `new` in a parameter
 * initializer, so this is ordinary code — and var_export() renders the
 * default as `\Config::__set_state(...)`, which is not a constant
 * expression.
 */
interface ObjectDefault
{
    public function find(Config $c = new Config()): mixed;
}

/**
 * The same shape carrying arguments, so a fix cannot pass by emitting a
 * bare `new Config()` and losing what the initializer actually said.
 * The class constant is the second half: it must survive fully
 * qualified into a class generated in another namespace.
 */
interface ObjectDefaultWithArguments
{
    public function find(Config $c = new Config(99)): mixed;

    public function keyed(Config $c = new Config(Config::LIMIT)): mixed;
}
