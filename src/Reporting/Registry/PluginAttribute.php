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
 * The metadata shape every registrable plugin kind's attribute carries
 * (report format, subscriber, progress view, and any future kind) —
 * extracted once these three turned out identical, so a new kind is a
 * `final readonly class X extends PluginAttribute {}`, not a fourth
 * copy of the same six properties. Kept as separate, named, per-kind
 * `#[Attribute(Attribute::TARGET_CLASS)]` classes rather than one
 * generic attribute with a "kind" field, since each registry's
 * reflection lookup (`getAttributes(Subscriber::class)`) targets one
 * exact class, and a named attribute is self-documenting on the plugin
 * class itself.
 */
abstract readonly class PluginAttribute
{
    /**
     * @param  array<string>  $requires  e.g. ['ext-dom'] — checked via extension_loaded()
     * @param  list<array{name: string, type: string, required: bool, default?: mixed, description: string, comment?: string}>  $params
     */
    public function __construct(
        public string $key,
        public string $description,
        public ?string $comment = null,
        public array $requires = [],
        public array $params = [],
        public int $priority = 0,
    ) {}
}
