<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\TraitShapes;

use LucianoPereira\Crucible\Framework\TestCase;

/*
 * The shapes TraitComposer generates a class for. APPEND ONLY.
 *
 * Its own file rather than the test class, because two of these end the
 * process they are composed in — PHP's limits, not Crucible's — so the
 * sweep reaches them from a subprocess.
 */

trait Greets
{
    public function greeting(): string
    {
        return 'hello';
    }
}

trait Counts
{
    public function count(): int
    {
        return 7;
    }
}

/** Collides with Greets on greeting(): PHP has no runtime insteadof. */
trait GreetsDifferently
{
    public function greeting(): string
    {
        return 'hi';
    }
}

/** An abstract trait method is satisfied by the class, not a collision. */
trait DemandsGreeting
{
    abstract public function greeting(): string;

    public function greetingLength(): int
    {
        return \strlen($this->greeting());
    }
}

class PlainBase extends TestCase {}

final class FinalBase extends TestCase {}

abstract class AbstractBase extends TestCase
{
    abstract public function unimplemented(): string;
}

/** A requirement on whoever uses it; nothing here answers it. */
trait DemandsUnanswered
{
    abstract public function required(): string;
}

trait HoldsAnInt
{
    public int $value = 1;
}

/** Same name, different type: PHP refuses unless declarations match. */
trait HoldsAString
{
    public string $value = 'x';
}

/** Same name AND identical declaration, which PHP does allow. */
trait HoldsTheSameInt
{
    public int $value = 1;
}

trait OverridesSealed
{
    public function sealed(): string
    {
        return 'trait';
    }
}

class SealedMethodBase extends TestCase
{
    final public function sealed(): string
    {
        return 'base';
    }
}

class StringPropertyBase extends TestCase
{
    public string $value = 'base';
}
