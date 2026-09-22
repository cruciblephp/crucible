<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

use Attribute;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;

/**
 * A planned test (growth G2's todo/wip/done vocabulary), visible to
 * the engine as metadata: `--todos` lists todo-marked tests without
 * caring which dialect declared them, and `--assignee`/`--issue`
 * narrow by the fields. A todo never executes — the runner reports it
 * incomplete with this label before anything else runs. wip and done
 * run their bodies like Pest, keeping the marker as documentation; a
 * body-less wip/done has nothing to run, so the frontend folds it to a
 * plain todo. The pest/crucible dialects construct this from
 * `->todo()/wip()/done()` chains and body-less calls; the phpunit
 * dialect takes it as a plain attribute.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Todo implements CrucibleAttribute
{
    public function __construct(
        public TodoStatus $status = TodoStatus::Todo,
        public ?string $assignee = null,
        public string|int|null $issue = null,
        public ?string $note = null,
    ) {}

    public function blocksExecution(): bool
    {
        // Only a todo is a pure placeholder. wip and done run their
        // bodies (Pest parity); a body-less wip/done never reaches here
        // — the frontend folds it to a todo, since there is nothing to
        // run.
        return $this->status === TodoStatus::Todo;
    }

    /**
     * @return non-empty-string
     */
    public function label(): string
    {
        // Only a todo blocks and shows this label; wip/done run, so the
        // status never varies the wording here.
        $label = 'TODO';

        if ($this->assignee !== null) {
            $label .= ' — assigned to ' . $this->assignee;
        }

        if ($this->issue !== null) {
            $label .= ' — issue #' . $this->issue;
        }

        if ($this->note !== null) {
            $label .= ': ' . $this->note;
        }

        return $label;
    }
}
