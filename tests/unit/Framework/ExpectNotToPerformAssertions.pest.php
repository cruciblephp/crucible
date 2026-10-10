<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 *
 * The Pest dialect binds its closures to a TestCase, so an in-body
 * expectNotToPerformAssertions() takes the same path as the PHPUnit
 * dialect's: the test is not risky for asserting nothing.
 */

\test('the declaration alone is not risky', function (): void {
    $this->expectNotToPerformAssertions();
});
