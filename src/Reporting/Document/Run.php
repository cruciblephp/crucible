<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

/**
 * A marker for one span of inline text within a `Heading` or
 * `Paragraph` — plain text, a test name in code font, an emphasized
 * word. Same shape as `Block`: a pure marker, no rendering contract.
 */
interface Run {}
