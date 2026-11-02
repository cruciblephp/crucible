<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Types\TypeExpression;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;

use function array_merge;
use function ctype_space;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * What a function's docblock says its parameters and return are, read
 * as PHPStan reads them: `@phpstan-` over `@psalm-` over the plain tag,
 * a tag the method does not restate inherited from the class or
 * interface it overrides, each name resolved in the file the tag is
 * written in. A slot whose type names a template or a type alias, `self`, `static` or
 * `$this`, or uses syntax TypeExpression does not read, is left out:
 * only what can be checked against a value is kept.
 */
final readonly class DocTypes
{
    /**
     * @param array<string, array{string, TypeExpression}> $parameters name => [as written, as read]
     * @param ?array{string, TypeExpression}               $return
     */
    private function __construct(
        public array $parameters,
        public ?array $return,
    ) {}

    public static function of(ReflectionFunctionAbstract $function): self
    {
        $docs       = self::docs($function);
        $templates  = self::templates($function, $docs);
        $parameters = [];

        foreach ($function->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            $read = self::slot($docs, $templates, static fn(string $doc): ?string => self::param($doc, $parameter->getName()));

            if ($read !== null) {
                $parameters[$parameter->getName()] = $read;
            }
        }

        return new self($parameters, self::slot($docs, $templates, self::returnOf(...)));
    }

    /**
     * @param list<array{string, string}> $docs
     * @param list<string>                $templates
     * @param callable(string): ?string   $read
     *
     * @return ?array{string, TypeExpression}
     */
    private static function slot(array $docs, array $templates, callable $read): ?array
    {
        foreach ($docs as [$doc, $file]) {
            $written = $read($doc);

            if ($written === null || $written === '') {
                continue;
            }

            if (preg_match('/(?<![\w\\\\-])(self|static|parent|\$this)(?![\w-])/', $written) === 1) {
                return null;
            }

            foreach ($templates as $template) {
                if (preg_match('/(?<![\w\\\\-])' . $template . '(?![\w\\\\-])/', $written) === 1) {
                    return null;
                }
            }

            try {
                return [$written, TypeExpression::parse(NameContext::ofFile($file)->resolve($written))];
            } catch (ConfigurationException) {
                return null;
            }
        }

        return null;
    }

    /**
     * The function's docblock, then each it inherits from, with the file each is written in.
     *
     * @return list<array{string, string}>
     */
    private static function docs(ReflectionFunctionAbstract $function): array
    {
        $docs = [[(string) $function->getDocComment(), (string) $function->getFileName()]];

        if (!$function instanceof ReflectionMethod) {
            return $docs;
        }

        $class     = $function->getDeclaringClass();
        $ancestors = $class->getParentClass() instanceof ReflectionClass ? [$class->getParentClass()] : [];

        foreach (array_merge($ancestors, $class->getInterfaces()) as $ancestor) {
            if ($ancestor->hasMethod($function->getName())) {
                $inherited = $ancestor->getMethod($function->getName());
                $docs[]    = [(string) $inherited->getDocComment(), (string) $inherited->getDeclaringClass()->getFileName()];
            }
        }

        return $docs;
    }

    /**
     * @param list<array{string, string}> $docs
     *
     * @return list<string>
     */
    private static function templates(ReflectionFunctionAbstract $function, array $docs): array
    {
        $text = '';

        foreach ($docs as [$doc]) {
            $text .= $doc . "\n";
        }

        if ($function instanceof ReflectionMethod) {
            $text .= (string) $function->getDeclaringClass()->getDocComment();
        }

        preg_match_all('/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\s+(\w+)/', $text, $templates);

        // A local type alias is a name only PHPStan can expand; skipped as a template is.
        preg_match_all('/@(?:phpstan|psalm)-type\s+(\w+)|@(?:phpstan|psalm)-import-type\s+(\w+)(?:\s+from\s+\S+)?(?:\s+as\s+(\w+))?/', $text, $aliases);

        $names = $templates[1];

        foreach ($aliases[1] as $index => $local) {
            $names[] = $local !== '' ? $local : ($aliases[3][$index] !== '' ? $aliases[3][$index] : $aliases[2][$index]);
        }

        return $names;
    }

    private static function param(string $doc, string $name): ?string
    {
        foreach (['@phpstan-param', '@psalm-param', '@param'] as $wanted) {
            foreach (self::tags($doc) as [$tag, $text]) {
                if ($tag !== $wanted) {
                    continue;
                }

                $type = self::leadingType($text);
                $rest = ltrim(substr($text, strlen($type)));

                if (preg_match('/^(?:\.\.\.)?&?\$' . $name . '\b/', $rest) === 1) {
                    return $type;
                }
            }
        }

        return null;
    }

    private static function returnOf(string $doc): ?string
    {
        foreach (['@phpstan-return', '@psalm-return', '@return'] as $wanted) {
            foreach (self::tags($doc) as [$tag, $text]) {
                if ($tag === $wanted) {
                    return self::leadingType($text);
                }
            }
        }

        return null;
    }

    /**
     * Each tag of the docblock with its text on one line.
     *
     * @return list<array{string, string}>
     */
    private static function tags(string $doc): array
    {
        $doc   = (string) preg_replace(['#^\s*/\*\*#', '#\*/\s*$#', '#^\s*\*\s?#m'], '', $doc);
        $parts = preg_split('/(?=^\s*@[a-z-]+)/m', $doc);
        $tags  = [];

        foreach ($parts === false ? [] : $parts as $part) {
            if (preg_match('/^\s*(@[a-z-]+)\s+(.*)$/s', $part, $match) === 1) {
                $tags[] = [$match[1], trim((string) preg_replace('/\s+/', ' ', $match[2]))];
            }
        }

        return $tags;
    }

    /** The type opening a tag's text: brackets balanced, a space kept only after `:`, `,` or `|`, or before `|`. */
    private static function leadingType(string $text): string
    {
        $depth = 0;
        $type  = '';

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];

            if (str_contains('<{([', $char)) {
                $depth++;
            } elseif (str_contains('>})]', $char)) {
                $depth--;
            } elseif (ctype_space($char) && $depth <= 0) {
                $before = rtrim($type);

                if (str_ends_with($before, ':') || str_ends_with($before, '|') || str_starts_with(ltrim(substr($text, $i)), '|')) {
                    $type .= $char;

                    continue;
                }

                break;
            }

            $type .= $char;
        }

        return trim($type);
    }
}
