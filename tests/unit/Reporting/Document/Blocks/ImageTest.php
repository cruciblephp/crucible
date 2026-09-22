<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document\Blocks;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Image;

use function getimagesizefromstring;

use const IMAGETYPE_JPEG;

#[CoversClass(Image::class)]
final class ImageTest extends TestCase
{
    public function testCarriesTheJpegBytesAndNoExplicitWidthByDefault(): void
    {
        $image = new Image("\xFF\xD8\xFF\xD9");

        $this->assertSame("\xFF\xD8\xFF\xD9", $image->jpegBytes);
        $this->assertNull($image->width);
    }

    public function testWidthCanNarrowTheDefaultFitToContentWidth(): void
    {
        $image = new Image("\xFF\xD8\xFF\xD9", 120.0);

        $this->assertSame(120.0, $image->width);
    }

    public function testSampleReturnsARealDecodableJpeg(): void
    {
        $image = Image::sample();

        $info = getimagesizefromstring($image->jpegBytes);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }
}
