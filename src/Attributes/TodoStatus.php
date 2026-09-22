<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

/**
 * The lifecycle of a todo-marked test: an open placeholder, one being
 * worked on, or one whose body landed (the marker stays as
 * documentation until it is removed). Enum cases are constant
 * expressions, so #[Todo(TodoStatus::Wip)] works natively.
 */
enum TodoStatus: string
{
    case Todo = 'todo';
    case Wip  = 'wip';
    case Done = 'done';
}
