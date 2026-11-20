<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use Closure;
use Composer\Autoload\ClassLoader;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Dialect\Inline\InlineBuilder;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use PhpToken;
use ReflectionClass;
use ReflectionFunction;

use function array_diff;
use function array_diff_key;
use function array_values;
use function basename;
use function count;
use function explode;
use function file_get_contents;
use function get_declared_classes;
use function get_declared_interfaces;
use function get_declared_traits;
use function get_defined_constants;
use function get_defined_functions;
use function get_included_files;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function realpath;
use function rtrim;
use function spl_autoload_functions;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * What a worker must load before it discovers only its own units, or
 * whether it must repeat the parent's whole discovery (D-180).
 *
 * The sequential run discovers every test file in one process, so a test
 * can use any function, class or constant another file declared, by name
 * or through a string (`$f = 'helper'; $f()`), and can lean on whatever
 * another file did at load time. A worker that loads only its own files
 * must end up with the same process, or it is no longer the sequential
 * run's twin.
 *
 * Reading names cannot get there: a string can name anything. So the
 * parent does not read; it watches its own discovery and records what
 * it added - the files it loaded, the functions and classes they
 * declared, the constants defined. Then:
 *
 * - every project file discovery loaded must only declare (the token
 *   rule below), because what a load-time statement changes cannot be
 *   recorded as a symbol;
 * - every symbol discovery declared must be one a worker will have too:
 *   declared by a test file the worker preloads, by vendor code Composer
 *   loads the same way in every process, or by a source class the
 *   autoloader resolves to the file that declared it;
 * - no constant may appear, since nothing says where it came from.
 *
 * If all three hold, each worker requires the preload list, in the
 * order the parent loaded it, and then discovers its own units: its
 * symbol table is the parent's. If any fails, every worker repeats the
 * parent's discovery. A Pest file, `Pest.php` and `Datasets/` are never
 * preloaded, because their dialect loads them itself (with `require`,
 * per directory), so a function or class declared in one of them sends
 * workers to full discovery. Source files are never preloaded either: a
 * cold mutation worker has to load the mutant in their place (D-137).
 */
final readonly class UnitDependencies
{
    /**
     * The top-level calls that declare tests: Pest's vocabulary as
     * PestFileSniffer reads it, `pest()`, and Crucible's own dialect.
     *
     * @var list<non-empty-string>
     */
    private const array REGISTRATION_CALLS = [
        'test', 'it', 'describe', 'uses', 'dataset', 'todo', 'covers', 'mutates',
        'beforeEach', 'afterEach', 'beforeAll', 'afterAll', 'pest',
        'check', 'property', 'table', 'visit', 'arch',
    ];

    private const string PRELOAD = 'preload';

    private const string SOURCE = 'source';

    private const string DIALECT = 'dialect';

    /**
     * @param list<non-empty-string> $preload project-relative, portable paths, in the order the parent's discovery loaded them
     */
    public function __construct(
        public bool $fullDiscovery,
        public array $preload = [],
    ) {}

    /**
     * Every worker repeats the parent's discovery: the answer when
     * nothing was observed.
     */
    public static function full(): self
    {
        return new self(true);
    }

    /**
     * Run $discover and record what it added to this process.
     *
     * @template T
     *
     * @param Closure(): T $discover
     *
     * @return array{T, self}
     */
    public static function observe(Closure $discover, WorkingDirectory $workingDirectory, Source $source): array
    {
        $files     = get_included_files();
        $functions = get_defined_functions()['user'];
        $classes   = self::declaredClassLikes();
        $constants = get_defined_constants(true)['user'] ?? [];

        $result = $discover();

        if (array_diff_key(get_defined_constants(true)['user'] ?? [], $constants) !== []) {
            return [$result, self::full()];
        }

        return [$result, self::plan(
            array_values(array_diff(get_included_files(), $files)),
            array_values(array_diff(get_defined_functions()['user'], $functions)),
            array_values(array_diff(self::declaredClassLikes(), $classes)),
            $workingDirectory,
            $source,
        )];
    }

    /**
     * Whether the source's top level only declares: `declare`, `use`, an
     * unbraced `namespace`, class-likes, named functions, and the
     * dialects' registration calls. Anything else (an expression, a
     * `require`, a `const`, a braced namespace, inline output) answers
     * no, because what it does at load time may be what another file
     * relies on.
     */
    public static function isDeclarationOnly(string $source): bool
    {
        return self::topLevel($source) !== null;
    }

    /**
     * @param list<string>       $loaded    files discovery loaded, in order
     * @param list<string>       $functions functions discovery declared
     * @param list<class-string> $classes   class-likes discovery declared
     */
    private static function plan(array $loaded, array $functions, array $classes, WorkingDirectory $workingDirectory, Source $source): self
    {
        $real = realpath($workingDirectory->path);
        $root = rtrim($real === false ? $workingDirectory->path : $real, '/\\') . DIRECTORY_SEPARATOR;

        $sourcePaths = self::sourcePaths($workingDirectory, $source);

        /** @var array<string, string> $roles real path => PRELOAD|SOURCE|DIALECT */
        $roles = [];

        foreach ($loaded as $file) {
            $relative = self::inProject($file, $root);

            if ($relative === null) {
                continue;
            }

            $contents = file_get_contents($file);
            $kinds    = $contents === false ? null : self::topLevel($contents);

            if ($contents === false || $kinds === null) {
                return self::full();
            }

            $roles[$file] = self::roleOf($file, $relative, $contents, $kinds, $sourcePaths);
        }

        foreach ($functions as $function) {
            $file = (new ReflectionFunction($function))->getFileName();

            if (!self::reachable($file, null, $roles, $root)) {
                return self::full();
            }
        }

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            if (!$reflection->isInternal() && !self::reachable($reflection->getFileName(), $class, $roles, $root)) {
                return self::full();
            }
        }

        $preload = [];

        foreach ($roles as $file => $role) {
            $relative = self::inProject($file, $root);
            $portable = $relative === null ? '' : WorkingDirectory::portable($relative);

            if ($role === self::PRELOAD && $portable !== '') {
                $preload[] = $portable;
            }
        }

        return new self(false, $preload);
    }

    /**
     * Whether a worker will have the symbol $file declared: from a file
     * it preloads, from vendor code, or, for a class in a source file,
     * through the autoloader that resolves it to that file.
     *
     * @param array<string, string> $roles
     */
    private static function reachable(string|false $file, ?string $class, array $roles, string $root): bool
    {
        $real = $file === false ? false : realpath($file);

        if ($real === false) {
            // eval()'d code: nothing a worker can load again.
            return false;
        }

        if (self::inProject($real, $root) === null) {
            // Vendor code and code outside the project load the same way
            // in every process: Composer, not discovery.
            return true;
        }

        return match ($roles[$real] ?? null) {
            self::PRELOAD => true,
            self::SOURCE  => $class !== null && self::autoloads($class, $real),
            default       => false,
        };
    }

    /**
     * Whether a registered Composer loader resolves $class to $file.
     */
    private static function autoloads(string $class, string $file): bool
    {
        foreach (spl_autoload_functions() as $function) {
            if (!is_array($function) || !$function[0] instanceof ClassLoader) {
                continue;
            }

            $found = $function[0]->findFile($class);

            if (is_string($found) && realpath($found) === $file) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $kinds       what the file's top level holds
     * @param list<string>        $sourcePaths real source include directories (with a trailing separator) and files
     */
    private static function roleOf(string $file, string $relative, string $contents, array $kinds, array $sourcePaths): string
    {
        if (isset($kinds['registration'])
            || basename($file) === 'Pest.php'
            || in_array('Datasets', explode(DIRECTORY_SEPARATOR, $relative), true)
            || str_ends_with($file, '.pest.php')
            || str_ends_with($file, '.crucible.php')
        ) {
            return self::DIALECT;
        }

        if (InlineBuilder::hasMarkers($contents)) {
            return self::SOURCE;
        }

        foreach ($sourcePaths as $path) {
            if ($file === $path || (str_ends_with($path, DIRECTORY_SEPARATOR) && str_starts_with($file, $path))) {
                return self::SOURCE;
            }
        }

        return self::PRELOAD;
    }

    /**
     * The configured source includes as real paths: directories with a
     * trailing separator, files without.
     *
     * @return list<string>
     */
    private static function sourcePaths(WorkingDirectory $workingDirectory, Source $source): array
    {
        $paths = [];

        foreach ($source->includeDirectories as $directory) {
            $real = realpath($workingDirectory->absolute($directory));

            if ($real !== false) {
                $paths[] = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
            }
        }

        foreach ($source->includeFiles as $file) {
            $real = realpath($workingDirectory->absolute($file));

            if ($real !== false) {
                $paths[] = $real;
            }
        }

        return $paths;
    }

    /**
     * $file relative to the project, or null outside it or under any
     * vendor directory.
     */
    private static function inProject(string $file, string $root): ?string
    {
        if (!str_starts_with($file, $root)) {
            return null;
        }

        $relative = substr($file, strlen($root));

        return $relative === '' || in_array('vendor', explode(DIRECTORY_SEPARATOR, $relative), true) ? null : $relative;
    }

    /**
     * @return list<class-string>
     */
    private static function declaredClassLikes(): array
    {
        return [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()];
    }

    /**
     * What the source's top level holds - `declaration`, `registration` -
     * or null when it holds anything else.
     *
     * @return ?array<string, true>
     */
    private static function topLevel(string $source): ?array
    {
        $tokens = PhpToken::tokenize($source);
        $count  = count($tokens);
        $kinds  = [];
        $i      = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->is([T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY])
                || $token->text === ';'
            ) {
                $i++;

                continue;
            }

            if ($token->is(T_INLINE_HTML)) {
                if (trim($token->text) !== '') {
                    return null;
                }

                $i++;

                continue;
            }

            if ($token->is(T_ATTRIBUTE)) {
                $i = self::pastStatement($tokens, $i, untilGroupCloses: true);

                continue;
            }

            if ($token->is([T_DECLARE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $kinds['declaration'] = true;
                $i                    = self::pastStatement($tokens, $i);

                continue;
            }

            if ($token->is(T_NAMESPACE)) {
                $next = self::nextSignificant($tokens, $i);

                // `namespace\name()` is a call, not a declaration, and a
                // braced namespace hides its own top level in a block.
                if ($next === null || !$tokens[$next]->is([T_STRING, T_NAME_QUALIFIED])) {
                    return null;
                }

                $after = self::nextSignificant($tokens, $next);

                if ($after === null || $tokens[$after]->text !== ';') {
                    return null;
                }

                $i = $after + 1;

                continue;
            }

            if ($token->is(T_FUNCTION)) {
                $next = self::nextSignificant($tokens, $i);

                if ($next !== null && $tokens[$next]->text === '&') {
                    $next = self::nextSignificant($tokens, $next);
                }

                if ($next === null || !$tokens[$next]->is(T_STRING)) {
                    return null;
                }

                $kinds['declaration'] = true;
                $i                    = self::pastStatement($tokens, $i);

                continue;
            }

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && in_array(ltrim($token->text, '\\'), self::REGISTRATION_CALLS, true)
            ) {
                $next = self::nextSignificant($tokens, $i);

                if ($next !== null && $tokens[$next]->text === '(') {
                    $kinds['registration'] = true;
                    $i                     = self::pastStatement($tokens, $i);

                    continue;
                }
            }

            return null;
        }

        return $kinds;
    }

    /**
     * The index after the statement starting at $start: past the `;` that
     * ends it at depth 0, or past the `}` that closes its block (a class
     * or function body). With $untilGroupCloses, past the closer of the
     * group $start opens instead (an attribute's `#[...]`).
     *
     * @param array<PhpToken> $tokens
     */
    private static function pastStatement(array $tokens, int $start, bool $untilGroupCloses = false): int
    {
        $count = count($tokens);
        $depth = 0;

        for ($j = $start; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token->is([T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0 && ($untilGroupCloses || $token->text === '}')) {
                    return $j + 1;
                }

                continue;
            }

            if ($depth === 0 && $token->text === ';') {
                return $j + 1;
            }
        }

        return $count;
    }

    /**
     * The index of the next token that is not whitespace or a comment.
     *
     * @param array<PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($j = $from + 1; $j < $count; $j++) {
            if (!$tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $j;
            }
        }

        return null;
    }
}
