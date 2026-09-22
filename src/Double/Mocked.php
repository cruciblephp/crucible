<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

/**
 * The configuration surface every generated double implements.
 * createMock()/createStub() return T&Mocked, so static analysis knows
 * both the doubled type and this API.
 */
interface Mocked
{
    /**
     * @param non-empty-string $method
     */
    public function method(string $method): MethodConfigurator;

    public function expects(InvocationCount $count): MethodConfigurator;
}
