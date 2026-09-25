<?php

declare(strict_types=1);

namespace FrameworkFixtures;

use LucianoPereira\Crucible\Assert\Constraint\Constraint;

use function in_array;
use function sprintf;

/**
 * A framework's constraint, written to the incumbent's contract: its
 * failureDescription() is the fragment after "Failed asserting that",
 * as Laravel's HasInDatabase writes it. Outside Crucible's namespace on
 * purpose, since that is what makes it a framework's.
 */
final class RowExists extends Constraint
{
    /** @param list<string> $rows */
    public function __construct(
        private readonly array $rows,
    ) {}

    public function matches(mixed $other): bool
    {
        return in_array($other, $this->rows, true);
    }

    public function toString(): string
    {
        return '{"name":"this is data"}';
    }

    protected function failureDescription(mixed $other): string
    {
        return sprintf('a row in the table [%s] matches the attributes %s', $other, $this->toString());
    }
}
