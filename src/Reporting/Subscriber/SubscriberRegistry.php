<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Subscriber;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Reporting\Registry\AbstractRegistry;

use function implode;
use function sprintf;

/**
 * Resolves subscriber keys (configured in `crucible.php` as
 * class-strings, never instances) to their `#[Subscriber]` metadata
 * and, once selected for a real run, a validated, constructed
 * {@see SubscriberContract}. The describe/list/validate machinery
 * lives on {@see AbstractRegistry}, shared with every other plugin
 * kind — only construction is genuinely different here: unlike
 * {@see \LucianoPereira\Crucible\Reporting\ReportFormat\ReportFormatRegistry},
 * whose formats take their params at render() call time and so
 * construct with zero args, a subscriber's params (e.g. an output
 * path) must reach its constructor — `Listener::handle()` has no room
 * for them. `resolve()` therefore constructs via
 * `new $class(...$params)`, a named-argument spread matching the
 * constructor's parameter names.
 */
final class SubscriberRegistry extends AbstractRegistry
{
    protected function attributeClass(): string
    {
        return Subscriber::class;
    }

    protected function contractClass(): string
    {
        return SubscriberContract::class;
    }

    protected function noun(): string
    {
        return 'Subscriber';
    }

    /**
     * Full validation (requirements + params against the attribute's
     * schema) — call only for subscribers a run actually selected.
     * Throws before the suite runs, never inside `handle()`.
     */
    public function resolve(string $key): SubscriberContract
    {
        $descriptor = $this->describe($key);

        if (!$descriptor->available) {
            throw new ConfigurationException(sprintf(
                'Subscriber "%s" is not available: %s.',
                $key,
                implode(', ', $descriptor->missingRequirements),
            ));
        }

        $params = $this->validatedParams($key, $descriptor, $this->configured[$key]['params']);

        /** @var SubscriberContract $instance */
        $instance = new $descriptor->class(...$params);

        return $instance;
    }
}
