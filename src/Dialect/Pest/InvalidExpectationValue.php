<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use InvalidArgumentException;
use LucianoPereira\Crucible\Exceptions\Exception;

/**
 * A matcher declining a subject whose type it does not accept.
 *
 * Deliberately NOT an AssertionFailedError. Declining to answer and
 * answering "no" are indistinguishable in the positive form — both stop
 * the test — which is why returning `false` for a wrong-typed subject
 * looked harmless for as long as only the positive form was measured.
 * Under `->not` they part company: a failure inverts into a pass, and a
 * refusal must stay a refusal. Routing this through the constraint
 * engine would hand it to LogicalNot and reintroduce exactly that.
 *
 * An InvalidArgumentException rather than a runtime one, because the
 * subject's type is a property of the call as written, not of the run:
 * `expect([])->toBeHostname()` is a test that cannot mean anything, and
 * no input would have made it meaningful.
 */
final class InvalidExpectationValue extends InvalidArgumentException implements Exception {}
