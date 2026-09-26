<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Types;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function count;
use function in_array;
use function preg_match_all;
use function sprintf;
use function stripcslashes;
use function strtolower;
use function substr;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

/**
 * Reads a PHPStan type string into a TypeExpression (D-131): a
 * recursive-descent parser over the subset TypeExpression documents. A
 * string it cannot read is refused with the position where it stops —
 * a type assertion that silently meant something else would be worse
 * than none.
 */
final class TypeParser
{
    /** @var list<array{string, int}> token text, offset */
    private array $tokens = [];

    private int $at = 0;

    public function __construct(private readonly string $source)
    {
        preg_match_all(
            '/\s*(\.\.\.|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?|[A-Za-z_\\\\][A-Za-z0-9_\\\\-]*|\[\]|[|&?<>{}(),:]|\S)/',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $this->tokens[] = [$match[1][0], $match[1][1]];
        }
    }

    public function parse(): TypeExpression
    {
        $type = $this->union();

        if ($this->peek() !== null) {
            $this->refuse('unexpected ' . $this->peek());
        }

        return $type;
    }

    private function union(): TypeExpression
    {
        $members = [$this->intersection()];

        while ($this->peek() === '|') {
            $this->at++;
            $members[] = $this->intersection();
        }

        return count($members) === 1 ? $members[0] : TypeExpression::union($members);
    }

    private function intersection(): TypeExpression
    {
        $members = [$this->postfix()];

        while ($this->peek() === '&') {
            $this->at++;
            $members[] = $this->postfix();
        }

        return count($members) === 1 ? $members[0] : TypeExpression::intersection($members);
    }

    private function postfix(): TypeExpression
    {
        $type = $this->atom();

        while ($this->peek() === '[]') {
            $this->at++;
            $type = TypeExpression::array('array', [$type]);
        }

        return $type;
    }

    private function atom(): TypeExpression
    {
        $token = $this->next();

        if ($token === '?') {
            return TypeExpression::union([$this->atom(), TypeExpression::keyword('null')]);
        }

        if ($token === '(') {
            $type = $this->union();
            $this->expect(')');

            return $type;
        }

        if ($token[0] === "'" || $token[0] === '"') {
            return TypeExpression::literal(stripcslashes(substr($token, 1, -1)));
        }

        $integer = TypeExpression::integer($token);

        if ($integer !== null) {
            return TypeExpression::literal($integer);
        }

        if (TypeExpression::looksLikeFloat($token)) {
            return TypeExpression::literal((float) $token);
        }

        if (preg_match_all('/^[A-Za-z_\\\\]/', $token) !== 1) {
            $this->refuse('unexpected ' . $token, -1);
        }

        $name = strtolower($token);

        if ($this->peek() === '{' && in_array($name, ['array', 'list', 'non-empty-array', 'non-empty-list'], true)) {
            return $this->shape($name);
        }

        if ($this->peek() === '<') {
            return $this->generic($name, $token);
        }

        return TypeExpression::isKeyword($token)
            ? TypeExpression::keyword($name)
            : TypeExpression::class(TypeExpression::className($token));
    }

    private function generic(string $name, string $token): TypeExpression
    {
        $this->expect('<');

        if ($name === 'int') {
            $min = $this->bound('min');
            $this->expect(',');
            $max = $this->bound('max');
            $this->expect('>');

            return TypeExpression::range($min, $max);
        }

        $arguments = [$this->union()];

        while ($this->peek() === ',') {
            $this->at++;
            $arguments[] = $this->union();
        }

        $this->expect('>');

        if ($name === 'class-string') {
            return TypeExpression::classString($arguments);
        }

        if (!in_array($name, ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'], true) || count($arguments) > 2) {
            $this->refuse(sprintf('%s<…> is not a type this reads', $token));
        }

        return TypeExpression::array($name, $arguments);
    }

    private function bound(string $open): ?int
    {
        $token = $this->next();

        if (strtolower($token) === $open) {
            return null;
        }

        $integer = TypeExpression::integer($token);

        if ($integer === null) {
            $this->refuse('expected an integer or ' . $open, -1);
        }

        return $integer;
    }

    private function shape(string $name): TypeExpression
    {
        $this->expect('{');

        $keys     = [];
        $open     = true;
        $position = 0;

        while ($this->peek() !== '}') {
            if ($this->peek() === '...') {
                $this->at++;
                $open = true;

                // `...<K, V>`: the unnamed keys' types; read and allowed.
                if ($this->peek() === '<') {
                    $this->generic('array', 'array');
                }
            } elseif ($this->isKeyed()) {
                $key      = $this->next();
                $key      = $key[0] === "'" || $key[0] === '"' ? stripcslashes(substr($key, 1, -1)) : (TypeExpression::integer($key) ?? $key);
                $optional = $this->peek() === '?';

                if ($optional) {
                    $this->at++;
                }

                $this->expect(':');
                $keys[$key] = [$this->union(), $optional];
            } else {
                $keys[$position++] = [$this->union(), false];
            }

            if ($this->peek() !== ',') {
                break;
            }

            $this->at++;
        }

        $this->expect('}');

        return TypeExpression::shape($name, $keys, $open);
    }

    /** Whether the next entry is `key:` or `key?:` rather than a bare type. */
    private function isKeyed(): bool
    {
        $after = $this->tokens[$this->at + 1][0] ?? null;

        return $after === ':' || ($after === '?' && ($this->tokens[$this->at + 2][0] ?? null) === ':');
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->at][0] ?? null;
    }

    private function next(): string
    {
        $token = $this->peek();

        if ($token === null) {
            $this->refuse('the type ends too early', 0);
        }

        $this->at++;

        return $token;
    }

    private function expect(string $token): void
    {
        if ($this->peek() !== $token) {
            $this->refuse(sprintf('expected %s', $token));
        }

        $this->at++;
    }

    private function refuse(string $why, int $shift = 0): never
    {
        $offset = $this->tokens[$this->at + $shift][1] ?? null;

        throw new ConfigurationException(sprintf(
            'Cannot read the type "%s"%s: %s.',
            $this->source,
            $offset === null ? ' at its end' : sprintf(' at offset %d', $offset),
            $why,
        ));
    }
}
