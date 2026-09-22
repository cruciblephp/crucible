<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

use Attribute;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;

use function sprintf;

/**
 * A declared terminal outcome (D-073): the test asserts how it is
 * expected to end. When the run's actual outcome matches, the test
 * passes; when it does not, it fails — the ->fails()/->throws()
 * inversion (a met expectation is green) extended to the two
 * non-result outcomes, Skipped and Incomplete, which otherwise can
 * never count as passing and only ever swell a separate tally.
 *
 * The runner reconciles it at the single test:finish choke point, so a
 * body-triggered markTestSkipped, a declared ->skip(), an unmet
 * #[Requires], and a todo block are all covered without the
 * runner's callers knowing the expectation exists.
 *
 * Only Skipped and Incomplete are accepted: Failed and a thrown
 * exception are already served by ->fails()/->throws(), Passed is the
 * default, and Risky/Errored are defects, not outcomes one declares.
 * The crucible dialect constructs this from ->expectsSkip()/
 * ->expectsIncomplete() chains (superset-native, so the names do not
 * shadow the inherited ->skip()); the phpunit dialect takes it as a
 * plain attribute.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ExpectedOutcome implements CrucibleAttribute
{
    public function __construct(
        public Outcome $expected,
        public ?string $reasonFragment = null,
    ) {
        if ($expected !== Outcome::Skipped && $expected !== Outcome::Incomplete) {
            throw new ConfigurationException(sprintf(
                'ExpectedOutcome accepts only skip or incomplete; %s is served by fails()/throws() or is the default.',
                $expected->value,
            ));
        }
    }

    /**
     * The expectation read as an infinitive, for "Expected the test to
     * <verb>" messages.
     *
     * @return non-empty-string
     */
    public function verb(): string
    {
        return $this->expected === Outcome::Skipped ? 'skip' : 'be incomplete';
    }
}
