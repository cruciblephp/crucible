<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\PhpUnitExporter;
use Override;

use function enum_exists;
use function interface_exists;
use function sprintf;
use function trait_exists;

final class IsInstanceOf extends Constraint
{
    /**
     * @param string $className a name no class has matches nothing, as Mockery's type() needs
     */
    public function __construct(
        private readonly string $className,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return $other instanceof $this->className;
    }

    public function toString(): string
    {
        $kind = match (true) {
            interface_exists($this->className) => 'interface',
            enum_exists($this->className)      => 'enum',
            trait_exists($this->className)     => 'trait',
            default                            => 'class',
        };

        return sprintf('is an instance of %s %s', $kind, $this->className);
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return 'Failed asserting that ' . PhpUnitExporter::noun($other) . ' ' . $this->toString() . '.';
    }
}
