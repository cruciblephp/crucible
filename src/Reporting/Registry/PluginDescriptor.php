<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Registry;

/**
 * A configured plugin's descriptive metadata plus its computed
 * availability — the shape `crucible extensions` prints, shared by
 * every plugin kind (report format, subscriber, progress view). Built
 * by an {@see AbstractRegistry} subclass from a class's per-kind
 * attribute; never requires constructing that class.
 */
final readonly class PluginDescriptor
{
    /**
     * @param  class-string  $class  not narrowed to the kind's own contract — a
     *         broken registration (wrong class, missing interface) is still a valid descriptor,
     *         represented via `available: false`, not excluded from this type
     * @param  array<string>  $requires
     * @param  list<array{name: string, type: string, required: bool, default?: mixed, description: string, comment?: string}>  $params
     * @param  list<string>  $missingRequirements
     */
    public function __construct(
        public string $key,
        public string $class,
        public string $description,
        public ?string $comment,
        public array $requires,
        public array $params,
        public int $priority,
        public bool $available,
        public array $missingRequirements,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key'                  => $this->key,
            'class'                => $this->class,
            'description'          => $this->description,
            'comment'              => $this->comment,
            'requires'             => $this->requires,
            'params'               => $this->params,
            'priority'             => $this->priority,
            'available'            => $this->available,
            'missing_requirements' => $this->missingRequirements,
        ];
    }
}
