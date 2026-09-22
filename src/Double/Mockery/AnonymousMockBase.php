<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

/**
 * The empty base behind `Mockery::mock()` with no target: the
 * generated double extends this, and its catch-all __call accepts
 * whatever methods the test configures.
 */
class AnonymousMockBase {}
