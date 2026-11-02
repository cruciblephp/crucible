<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use Closure;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Types\TypeExpression;
use LucianoPereira\Crucible\Types\TypeParser;

use function class_exists;
use function dirname;
use function enum_exists;
use function file_get_contents;
use function interface_exists;
use function rtrim;
use function spl_autoload_functions;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_ends_with;
use function str_starts_with;
use function trait_exists;

/**
 * `--observe-types`: every class of the configured source is autoloaded
 * with the {@see Observer}'s probes in it, from before the bootstrap loads.
 * A file required by its path is included as it is.
 */
final class ObservedRun
{
    /** @var ?Closure(string): void */
    private static ?Closure $loader = null;

    private static int $depth = 0;

    /**
     * What the probes run, loaded before any probe does: once the rewriter
     * is in place a file of it included would be watching itself, and the
     * Observer's autoload barrier would defer every check that loads one.
     */
    public static function preload(): void
    {
        foreach ([Observer::class, DeferredCheck::class, DocTypes::class, NameContext::class, ValueTypes::class, TypeExpression::class, TypeParser::class, Exporter::class] as $class) {
            class_exists($class);
        }
    }

    public static function start(Source $source, WorkingDirectory $workingDirectory): void
    {
        self::preload();

        $included = [];
        $excluded = [WorkingDirectory::portable(dirname(__DIR__)) . '/'];

        foreach ($source->includeDirectories as $directory) {
            $included[] = rtrim(WorkingDirectory::portable($workingDirectory->absolute($directory)), '/') . '/';
        }

        foreach ($source->includeFiles as $file) {
            $included[] = WorkingDirectory::portable($workingDirectory->absolute($file));
        }

        foreach ($source->excludeDirectories as $directory) {
            $excluded[] = rtrim(WorkingDirectory::portable($workingDirectory->absolute($directory)), '/') . '/';
        }

        foreach ($source->excludeFiles as $file) {
            $excluded[] = WorkingDirectory::portable($workingDirectory->absolute($file));
        }

        $rewriter = new ProbeRewriter();
        $rewrite  = static function (string $path) use ($included, $excluded, $rewriter): ?string {
            if (!str_ends_with($path, '.php') || !self::under($path, $included) || self::under($path, $excluded)) {
                return null;
            }

            $contents = @file_get_contents($path);

            return $contents === false ? null : $rewriter->rewrite($contents);
        };

        // The wrapper is in place only while a class is being autoloaded:
        // outside that window no file operation of the run goes through
        // it, and a file proc_open() hands a child stays PHP's own.
        self::$loader = static function (string $class) use ($rewrite): void {
            if (self::$depth++ === 0) {
                IncludeRewriter::register($rewrite);
            }

            try {
                foreach (spl_autoload_functions() as $loader) {
                    if ($loader === self::$loader) {
                        continue;
                    }

                    $loader($class);

                    if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false)) {
                        return;
                    }
                }
            } finally {
                if (--self::$depth === 0) {
                    IncludeRewriter::unregister();
                }
            }
        };

        spl_autoload_register(self::$loader, true, true);
    }

    public static function stop(): void
    {
        if (self::$loader instanceof Closure) {
            spl_autoload_unregister(self::$loader);
            self::$loader = null;
        }

        Observer::settle();
    }

    /**
     * @param list<string> $roots in '/', directories ending in it, or files
     */
    private static function under(string $path, array $roots): bool
    {
        $path = WorkingDirectory::portable($path);

        foreach ($roots as $root) {
            if ($path === $root || (str_ends_with($root, '/') && str_starts_with($path, $root))) {
                return true;
            }
        }

        return false;
    }
}
