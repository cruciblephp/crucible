<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Every fixture line where Crucible's extension answers differently from
 * the incumbents', as label => [Crucible's type, the cause]. A row states
 * a decision; a difference with no row is a finding, and compare.php
 * fails on it. So does a row whose difference has gone away.
 *
 * Each subject row is Crucible answering MORE than the incumbent: every
 * subject line the incumbent leaves as it was, Crucible narrows. The
 * other rows are where Crucible gives both dialects one answer (D-137):
 * where the two incumbents disagree with each other, or where one of
 * them is unsound.
 *
 * @return array<string, array{crucible: string, cause: string}>
 */

$oneReading = 'one reading for both dialects (D-137): a matcher narrows exactly as its assertion does, through AssertConditions, so toBeArray() is assertIsArray() is PHPStan\'s own is_array() — array<mixed, mixed>, the answer phpstan-phpunit gives (phpunit.isArray.*). pest-plugin-phpstan builds its own array<int|string, mixed>: the same values, keys spelled out.';

$soundList = 'assertIsList() reads as toBeList() does (D-137): a list of the value\'s elements. phpstan-phpunit intersects array<string, mixed> with list and gets *NEVER* — unsound, because the empty array is both, and code after a passing assertion would be reported as unreachable.';

$subject = 'the subject narrows (D-129): after the statement, the variable passed to expect() carries what the matchers proved. The incumbent narrows only the chain. Sound because an expectation that fails throws.';

return [
    'phpunit.isList.this'            => ['crucible' => 'list', 'cause' => $soundList],
    'phpunit.isList.self'            => ['crucible' => 'list', 'cause' => $soundList],
    'phpunit.isList.static'          => ['crucible' => 'list', 'cause' => $soundList],
    'pest.notToBeInstanceOf.subject' => ['crucible' => 'null', 'cause' => $subject],
    'pest.notToBeNull.subject'       => ['crucible' => 'string', 'cause' => $subject],
    'pest.notToBeString.subject'     => ['crucible' => 'int', 'cause' => $subject],
    'pest.toBeArray.chain'           => ['crucible' => 'array<mixed, mixed>', 'cause' => $oneReading],
    'pest.toBeArray.subject'         => ['crucible' => 'array<mixed, mixed>', 'cause' => $subject],
    'pest.toBeBool.subject'          => ['crucible' => 'bool', 'cause' => $subject],
    'pest.toBeCallable.subject'      => ['crucible' => 'callable(): mixed', 'cause' => $subject],
    'pest.toBeFalse.subject'         => ['crucible' => 'false', 'cause' => $subject],
    'pest.toBeFloat.subject'         => ['crucible' => 'float', 'cause' => $subject],
    'pest.toBeInstanceOf.subject'    => ['crucible' => 'CrucibleProbe\\Types\\Widget', 'cause' => $subject],
    'pest.toBeInt.subject'           => ['crucible' => 'int', 'cause' => $subject],
    'pest.toBeIterable.subject'      => ['crucible' => 'iterable', 'cause' => $subject],
    'pest.toBeList.subject'          => ['crucible' => 'list', 'cause' => $subject],
    'pest.toBeNull.subject'          => ['crucible' => 'null', 'cause' => $subject],
    'pest.toBeNumeric.subject'       => ['crucible' => 'float|int|numeric-string', 'cause' => $subject],
    'pest.toBeObject.subject'        => ['crucible' => 'object', 'cause' => $subject],
    'pest.toBeScalar.subject'        => ['crucible' => 'bool|float|int|string', 'cause' => $subject],
    'pest.toBeString.subject'        => ['crucible' => 'string', 'cause' => $subject],
    'pest.toBeTrue.subject'          => ['crucible' => 'true', 'cause' => $subject],
    'pest.chained.subject'           => ['crucible' => 'string', 'cause' => $subject],
    'pest.notMethod.subject'         => ['crucible' => 'string', 'cause' => $subject],
    'pest.andAborts.subject'         => ['crucible' => 'string', 'cause' => $subject . ' A step that changes the subject ends the reading; toBeString() came before it.'],
];
