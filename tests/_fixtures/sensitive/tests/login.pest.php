<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

// Listed by SensitiveArgumentsTest: the case names must not carry the password.

function sensitiveLogin(string $user, #[\SensitiveParameter] string $password): bool
{
    return $password === 'right';
}

\table(sensitiveLogin(...), [['ann', 'hunter2', false]]);
