<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Clock;

use DateTimeImmutable;
use DateTimeZone;

use function ctype_digit;
use function getenv;
use function is_string;
use function sprintf;

/**
 * `SOURCE_DATE_EPOCH`, the reproducible-builds convention: seconds since
 * the Unix epoch, naming *when the source was last changed* rather than
 * when this run happened.
 *
 * It exists because "reproducible" and "undated" are not the same want.
 * A build or a report still has a meaningful date — the date of the code
 * it describes — and that date is identical for everyone rebuilding the
 * same commit. Reading it from the environment is how the whole
 * ecosystem already does this (`git log -1 --format=%ct` feeding it is
 * the usual recipe), so Crucible does not invent a spelling.
 *
 * Absent, the honest answer is to record no time at all rather than a
 * plausible-looking wrong one. A stamp of 1970 is still a stamp, and a
 * consumer has no way to know it was a placeholder.
 */
final readonly class SourceDateEpoch
{
    /**
     * The instant the environment names, or null when it names none or
     * names something that is not a count of seconds.
     */
    public static function read(): ?DateTimeImmutable
    {
        $raw = getenv('SOURCE_DATE_EPOCH');

        if (!is_string($raw) || $raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('U', $raw, new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    /**
     * The clock a reproducible run should use: the source date when the
     * environment names one, the epoch otherwise — some formats type
     * their timestamp attribute as required, and a required field cannot
     * be omitted however little there is to say.
     */
    public static function clock(): Clock
    {
        return new FrozenClock(self::read() ?? new DateTimeImmutable('@0'));
    }

    /** Where the convention is documented, for messages that mention it. */
    public static function hint(): string
    {
        return sprintf('%s (try %s)', 'SOURCE_DATE_EPOCH is unset, so no generation time is recorded', 'SOURCE_DATE_EPOCH=$(git log -1 --format=%ct)');
    }
}
