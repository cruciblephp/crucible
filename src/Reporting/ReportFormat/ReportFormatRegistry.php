<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ReportFormat;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Reporting\Registry\AbstractRegistry;

use function implode;
use function sprintf;

/**
 * Resolves report-format keys (configured in `crucible.php` as
 * class-strings, never instances) to their `#[ReportFormat]` metadata
 * and, once selected for a real run, a validated, instantiated
 * {@see ReportFormatContract}. The describe/list/validate machinery
 * lives on {@see AbstractRegistry}, shared with every other plugin
 * kind — only construction is genuinely different here:
 * `ReportFormatContract::render()` takes `$params` as a call-time
 * argument, so this registry constructs with zero args and hands the
 * validated params back alongside the instance for the caller to pass
 * to `render()` later.
 */
final class ReportFormatRegistry extends AbstractRegistry
{
    protected function attributeClass(): string
    {
        return ReportFormat::class;
    }

    protected function contractClass(): string
    {
        return ReportFormatContract::class;
    }

    protected function noun(): string
    {
        return 'Report format';
    }

    /**
     * Instantiates an available format without validating its params
     * against the "required" rule — for `crucible extensions --preview`,
     * which supplies each param's own schema default (if any) rather
     * than a real, user-configured value (e.g. a required "output"
     * path that previewing has no reason to demand up front).
     */
    public function instantiate(string $key): ReportFormatContract
    {
        $descriptor = $this->describe($key);

        if (!$descriptor->available) {
            throw new ConfigurationException(sprintf(
                'Report format "%s" is not available: %s.',
                $key,
                implode(', ', $descriptor->missingRequirements),
            ));
        }

        /** @var ReportFormatContract $instance */
        $instance = new $descriptor->class();

        return $instance;
    }

    /**
     * Full validation (requirements + params against the attribute's
     * schema) — call only for formats a run actually selected. Throws
     * before the suite runs, never inside render().
     *
     * @return array{instance: ReportFormatContract, params: array<string, mixed>}
     */
    public function resolve(string $key): array
    {
        $descriptor = $this->describe($key);

        if (!$descriptor->available) {
            throw new ConfigurationException(sprintf(
                'Report format "%s" is not available: %s.',
                $key,
                implode(', ', $descriptor->missingRequirements),
            ));
        }

        $params = $this->validatedParams($key, $descriptor, $this->configured[$key]['params']);

        /** @var ReportFormatContract $instance */
        $instance = new $descriptor->class();

        return ['instance' => $instance, 'params' => $params];
    }
}
