<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ProgressView;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Reporting\Registry\AbstractRegistry;

use function implode;
use function sprintf;

/**
 * Resolves progress-view keys (configured in `crucible.php` as
 * class-strings, never instances) to their `#[ProgressView]` metadata
 * and, once selected for a real run, a validated, constructed
 * {@see ProgressViewContract}. The describe/list/validate machinery
 * lives on {@see AbstractRegistry}, shared with every other plugin
 * kind — construction here takes one extra, always framework-supplied
 * argument neither Report-format nor Subscriber need: the stream a
 * view prints to (`STDOUT` for a real run, a memory buffer for
 * `--preview`), which is never a user-configured param.
 */
final class ProgressViewRegistry extends AbstractRegistry
{
    protected function attributeClass(): string
    {
        return ProgressView::class;
    }

    protected function contractClass(): string
    {
        return ProgressViewContract::class;
    }

    protected function noun(): string
    {
        return 'Progress view';
    }

    /**
     * Full validation (requirements + params against the attribute's
     * schema) — call only for the view a run actually selected. Throws
     * before the suite runs, never inside `handle()`.
     *
     * @param resource $stream
     */
    public function resolve(string $key, mixed $stream): ProgressViewContract
    {
        $descriptor = $this->describe($key);

        if (!$descriptor->available) {
            throw new ConfigurationException(sprintf(
                'Progress view "%s" is not available: %s.',
                $key,
                implode(', ', $descriptor->missingRequirements),
            ));
        }

        $params = $this->validatedParams($key, $descriptor, $this->configured[$key]['params']);

        /** @var ProgressViewContract $instance */
        $instance = new $descriptor->class($stream, ...$params);

        return $instance;
    }
}
