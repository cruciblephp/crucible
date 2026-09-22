<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console;

/** The lifecycle state of a {@see Components\Prompt}, used to drive rendering. */
enum PromptState
{
    /** The prompt has not yet been drawn. */
    case Initial;

    /** The prompt is being interacted with. */
    case Active;

    /** The current value failed validation. */
    case Error;

    /** The prompt has been answered successfully. */
    case Submit;

    /** The prompt was cancelled by the user. */
    case Cancel;
}
