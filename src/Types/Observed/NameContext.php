<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use LucianoPereira\Crucible\Types\TypeExpression;
use PhpToken;

use function count;
use function explode;
use function file_get_contents;
use function in_array;
use function ltrim;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * The namespace and class imports of a file, to read a docblock's names
 * where they were written: `LogRecord` under `use Hallmark\LogRecord;`
 * is `\Hallmark\LogRecord`, wherever the method inheriting it lives.
 */
final readonly class NameContext
{
    /**
     * @param array<string, string> $imports lowercased alias => fully qualified name
     */
    private function __construct(
        private string $namespace,
        private array $imports,
    ) {}

    public static function ofFile(string $file): self
    {
        $source = @file_get_contents($file);

        return $source === false ? new self('', []) : self::ofSource($source);
    }

    public static function ofSource(string $source): self
    {
        $tokens    = PhpToken::tokenize($source);
        $count     = count($tokens);
        $namespace = '';
        $imports   = [];
        $depth     = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }

            if ($token->is(T_NAMESPACE)) {
                $namespace = self::nameAfter($tokens, $i);

                continue;
            }

            // Only a top-level use imports; one inside a class body is a trait.
            if (!$token->is(T_USE) || $depth > 0) {
                continue;
            }

            $next = self::significant($tokens, $i + 1);

            if ($next === null || $tokens[$next]->is([T_FUNCTION, T_CONST])) {
                continue;
            }

            $statement = '';

            for ($j = $next; $j < $count && $tokens[$j]->text !== ';'; $j++) {
                $statement .= $tokens[$j]->text;
            }

            foreach (self::importsOf($statement) as $alias => $name) {
                $imports[$alias] = $name;
            }
        }

        return new self($namespace, $imports);
    }

    /** The type string with every class name fully qualified. */
    public function resolve(string $type): string
    {
        return (string) preg_replace_callback(
            '/(?<![\w\\\\$\'"-])\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*(?![\w\\\\-]|\s*:(?!:)|\s*\()/',
            fn(array $match): string => $this->name($match[0]),
            $type,
        );
    }

    private function name(string $name): string
    {
        if ($name[0] === '\\') {
            return $name;
        }

        $lower = strtolower($name);

        if (TypeExpression::isKeyword($lower) || in_array($lower, ['self', 'static', 'parent', 'min', 'max'], true)) {
            return $name;
        }

        $first = explode('\\', $name)[0];
        $rest  = substr($name, strlen($first));

        if (isset($this->imports[strtolower($first)])) {
            return '\\' . $this->imports[strtolower($first)] . $rest;
        }

        return '\\' . ($this->namespace === '' ? '' : $this->namespace . '\\') . $name;
    }

    /**
     * @return array<string, string>
     */
    private static function importsOf(string $statement): array
    {
        $statement = (string) preg_replace('/\s+/', ' ', $statement);
        $prefix    = '';
        $imports   = [];

        if (str_contains($statement, '{')) {
            $prefix    = ltrim(trim(substr($statement, 0, (int) strpos($statement, '{'))), '\\');
            $statement = substr($statement, (int) strpos($statement, '{') + 1, -1);
        }

        foreach (explode(',', $statement) as $clause) {
            $clause = trim($clause);

            if ($clause === '' || str_starts_with(strtolower($clause), 'function ') || str_starts_with(strtolower($clause), 'const ')) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $clause);
            $parts = $parts === false ? [$clause] : $parts;
            $name  = ltrim($prefix . trim($parts[0]), '\\');
            $last  = strrpos($name, '\\');
            $alias = trim($parts[1] ?? ($last === false ? $name : substr($name, $last + 1)));

            if ($name !== '' && $alias !== '') {
                $imports[strtolower($alias)] = $name;
            }
        }

        return $imports;
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private static function nameAfter(array $tokens, int $at): string
    {
        $next = self::significant($tokens, $at + 1);

        return $next !== null && $tokens[$next]->is([T_STRING, T_NAME_QUALIFIED]) ? $tokens[$next]->text : '';
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private static function significant(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }
}
