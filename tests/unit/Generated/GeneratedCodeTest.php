<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Generated;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Generated\GeneratedCode;
use LucianoPereira\Crucible\Generated\GeneratedCodeException;
use ParseError;
use RuntimeException;

use function class_exists;
use function file_get_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The one place Crucible compiles code it wrote itself, and therefore
 * the one worth proving directly: five generators route through it, and
 * none of them can see what it does on failure.
 */
#[CoversClass(GeneratedCode::class)]
#[CoversClass(GeneratedCodeException::class)]
final class GeneratedCodeTest extends TestCase
{
    /** @var list<string> */
    private array $rubbish = [];

    protected function tearDown(): void
    {
        foreach ($this->rubbish as $path) {
            $files = glob($path . '/*');

            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }

            if (is_dir($path)) {
                rmdir($path);
            }
        }

        $this->rubbish = [];
    }

    public function testItCompilesAndHandsBackWhatTheSourceReturns(): void
    {
        $name = 'CrucibleGeneratedProbe_' . uniqid();

        self::assertNull(GeneratedCode::evaluate('class ' . $name . ' {}', 'a probe class'));
        self::assertTrue(class_exists($name, false));

        // The doctest site needs the VALUE back, not just the side
        // effect, so returning is part of the contract.
        self::assertSame(42, GeneratedCode::evaluate('return 42;', 'a probe expression'));
    }

    public function testSourceThatDoesNotParseNamesWhatItWasForAndShowsIt(): void
    {
        try {
            GeneratedCode::evaluate("class Broken {\n    public function (\n}", 'the double of App\Repo');
            self::fail('unparseable generated source must be reported, not swallowed');
        } catch (GeneratedCodeException $broken) {
            // What it was for, in the reader's terms — four of the five
            // generators used to raise a bare ParseError naming nothing.
            self::assertStringContainsString('the double of App\Repo', $broken->getMessage());

            // The source, numbered: a parse error's line number refers
            // to text that exists nowhere a reader can look.
            self::assertStringContainsString('1 | class Broken {', $broken->getMessage());
            self::assertStringContainsString('3 | }', $broken->getMessage());

            // And the original, so a caller wanting PHP's own words can
            // have them — the doctest site reports against the author's
            // docblock and uses exactly this.
            self::assertInstanceOf(ParseError::class, $broken->getPrevious());
        }
    }

    /**
     * The record hook is what makes generated code visible to a gate at
     * all -- `composer analyse:generated` has nothing to read without
     * it -- and it had no test. The tier catches a hook that writes
     * NOTHING, since every generator would then report zero shapes; it
     * cannot catch one that writes the wrong thing.
     */
    public function testRecordingWritesTheSourceWithWhatItWasFor(): void
    {
        $directory = $this->recordingDirectory();
        $source    = 'class CrucibleGeneratedRecorded_' . uniqid() . ' {}';

        GeneratedCode::recording($directory, static function () use ($source): void {
            GeneratedCode::evaluate($source, 'a probe worth recording');
        });

        $written = glob($directory . '/*.php');
        self::assertIsArray($written);
        self::assertCount(1, $written);

        $contents = (string) file_get_contents($written[0]);

        // The source itself, or the analysis grades something else.
        self::assertStringContainsString($source, $contents);

        // And what it was for: the file is the only place a reader can
        // look, since the string it came from exists nowhere.
        self::assertStringContainsString('a probe worth recording', $contents);
        self::assertStringStartsWith("<?php\n", $contents);
    }

    /**
     * The claim `record()` makes in its own comment, and the one the
     * double generator's naming now depends on: the directory is a set
     * of DISTINCT shapes, not a log of calls. It was false for doubles
     * until the counter went away, and nothing would have said so.
     */
    public function testTheSameShapeTwiceIsOneRecordedFile(): void
    {
        $directory = $this->recordingDirectory();
        $source    = 'class CrucibleGeneratedTwice_' . uniqid() . ' {}';

        GeneratedCode::recording($directory, static function () use ($source): void {
            GeneratedCode::evaluate($source, 'a shape generated once');

            // Compiling it again would redeclare the class, so only the
            // recording half is exercised -- which is the half under test.
            GeneratedCode::evaluate('return 1;', 'a different shape');
            GeneratedCode::evaluate('return 1;', 'the same shape, asked for again');
        });

        $written = glob($directory . '/*.php');
        self::assertIsArray($written);

        // Two distinct sources, three calls.
        self::assertCount(2, $written);
    }

    public function testRecordingStopsWhenTheScopeEnds(): void
    {
        $directory = $this->recordingDirectory();

        GeneratedCode::recording($directory, static function (): void {
            GeneratedCode::evaluate('return 1;', 'recorded');
        });

        GeneratedCode::evaluate('return 2;', 'not recorded');

        $written = glob($directory . '/*.php');
        self::assertIsArray($written);
        self::assertCount(1, $written, 'the scope must end the recording, or a run leaks sources into the last directory it was given');
    }

    /**
     * The reason the switch is scoped rather than a pair of calls: a
     * generator that throws midway through a corpus used to leave the
     * hook on, and the next thing to generate anything wrote into a
     * directory nobody meant — in a test run, one already deleted.
     */
    public function testRecordingStopsEvenWhenTheWorkThrows(): void
    {
        $directory = $this->recordingDirectory();

        try {
            GeneratedCode::recording($directory, static function (): void {
                GeneratedCode::evaluate('return 1;', 'recorded before the failure');

                throw new RuntimeException('a generator gave up midway');
            });

            self::fail('the failure must travel, not be swallowed by the scope');
        } catch (RuntimeException) {
            // Expected: the scope restores, it does not absorb.
        }

        GeneratedCode::evaluate('return 3;', 'after the scope, and after the throw');

        $written = glob($directory . '/*.php');
        self::assertIsArray($written);
        self::assertCount(1, $written, 'a throw inside the scope must still end the recording');
    }

    /**
     * @return non-empty-string
     */
    private function recordingDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/crucible-recorded-' . uniqid();

        mkdir($directory, 0o777, true);

        $this->rubbish[] = $directory;

        return $directory;
    }

    public function testAFailureInsideTheGeneratedCodeIsNotAGenerationFailure(): void
    {
        // A doctest that divides by zero, or a constructor that throws:
        // the code compiled fine and then something went wrong at
        // runtime, which belongs to whoever wrote the expression.
        // Wrapping that as a generation defect would blame the wrong
        // author and hide the real exception behind a numbered dump.
        $this->expectException(RuntimeException::class);

        GeneratedCode::evaluate('throw new \RuntimeException("from the generated code");', 'a probe that throws');
    }
}
