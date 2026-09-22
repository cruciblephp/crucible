<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\BrowserConfiguration;
use LucianoPereira\Crucible\Browser\PageCollection;
use LucianoPereira\Crucible\Browser\Playwright\Page;
use LucianoPereira\Crucible\Browser\Playwright\Session;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function getenv;
use function is_file;
use function is_string;
use function rawurlencode;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The D-064 interaction tail against a real browser: drag, attach,
 * withKeyDown, withinFrame, pressAndWaitFor, submit — each semantics
 * probe-pinned against the incumbent (spec/pest-api.md §6), plus the
 * visit([...]) fan-out surface.
 */
#[CoversClass(Page::class)]
#[CoversClass(PageCollection::class)]
final class InteractionTailTest extends TestCase
{
    public function testDragAttachKeyDownFramesAndSubmit(): void
    {
        $root = $this->playwrightRootOrSkip();

        $html = rawurlencode(<<<'HTML'
            <title>Tail</title>
            <form id="f" action="about:blank">
              <input type="text" name="q" value="preset">
              <input type="file" name="doc" id="doc">
            </form>
            <div id="drag-src" draggable="true" style="width:50px;height:50px;background:red"></div>
            <div id="drag-dst" style="width:100px;height:100px;background:blue"></div>
            <input id="keys" type="text">
            <iframe id="child" srcdoc="&lt;h1&gt;Inside Frame&lt;/h1&gt;&lt;button id=&quot;inner&quot; onclick=&quot;document.body.append(&#39;InnerClicked&#39;)&quot;&gt;Inner Button&lt;/button&gt;"></iframe>
            <script>
              const src = document.getElementById("drag-src");
              const dst = document.getElementById("drag-dst");
              src.addEventListener("dragstart", (e) => e.dataTransfer.setData("text/plain", "x"));
              dst.addEventListener("dragover", (e) => e.preventDefault());
              dst.addEventListener("drop", () => { document.title = "Dropped"; });
              document.getElementById("keys").addEventListener("keydown", (e) => {
                if (e.shiftKey && e.key === "A") { document.title = "ShiftA"; }
              });
              document.getElementById("f").addEventListener("submit", (e) => {
                e.preventDefault();
                document.title = "Submitted:" + new FormData(e.target).get("q");
              });
            </script>
            HTML);

        $session = Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        try {
            $browser = $session->launch();
            $page    = $browser->newContext()->newPage();
            $page->navigate('data:text/html,' . $html);

            // drag: one dragAndDrop frame call, HTML5 drag events fire.
            $page->drag('#drag-src', '#drag-dst');
            $this->assertSame('Dropped', $page->title());

            // attach: the payload form (the incumbent's localPaths
            // crashes on a stdio driver — probe-pinned, D-064).
            $file = sys_get_temp_dir() . '/crucible-attach-' . uniqid() . '.txt';
            file_put_contents($file, 'attached-bytes');

            try {
                $page->attach('doc', $file);
                $attached = $page->script("document.getElementById('doc').files[0].name");
                $this->assertIsString($attached);
                $this->assertStringContainsString('crucible-attach-', $attached);
            } finally {
                unlink($file);
            }

            // withKeyDown: the modifier rides the inner key events.
            $page->click('#keys');
            $page->withKeyDown('Shift', static function (Page $p): void {
                $p->keys('#keys', 'A');
            });
            $this->assertSame('ShiftA', $page->title());

            // withinFrame: same vocabulary, scoped to the iframe.
            $page->withinFrame('#child', function (Page $frame): void {
                $this->assertStringContainsString('Inside Frame', $frame->text('h1'));
                $frame->click('Inner Button');
                $this->assertStringContainsString('InnerClicked', $frame->text('body'));
            });

            // submit: a REAL submission — the submit event fires with
            // the form's data (the incumbent navigates to the bare
            // action URL, dropping the fields; recorded deviation).
            $page->submit();
            $this->assertSame('Submitted:preset', $page->title());

            // pressAndWaitFor is press + wait — the composition holds
            // on any button; reuse the frame's inner button.
            $page->withinFrame('#child', static function (Page $frame): void {
                $frame->pressAndWaitFor('Inner Button', 0.05);
            });

            $browser->close();
        } finally {
            $session->close();
        }
    }

    public function testTheVisitFanOutSurface(): void
    {
        $root = $this->playwrightRootOrSkip();

        $session = Session::start(new BrowserConfiguration(enabled: true, playwrightRoot: $root), new WorkingDirectory($root));

        try {
            $browser = $session->launch();
            $context = $browser->newContext();

            $pages = new PageCollection([
                $context->newPage()->navigate('data:text/html,<title>One</title><p>shared text</p>'),
                $context->newPage()->navigate('data:text/html,<title>Two</title><p>shared text</p>'),
            ]);

            // Every call fans out; the chain continues on the collection.
            $this->assertSame(2, $pages->count());
            $pages->assertSee('shared text')->assertNoJavaScriptErrors();

            // A page failing the fan-out fails with its own message.
            try {
                $pages->assertTitle('One');
                $this->fail('the second page must fail the fan-out');
            } catch (\LucianoPereira\Crucible\Assert\AssertionFailedError $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'title') || str_contains($e->getMessage(), 'Two'));
            }

            $browser->close();
        } finally {
            $session->close();
        }
    }

    /**
     * @return non-empty-string
     */
    private function playwrightRootOrSkip(): string
    {
        $env  = getenv('CRUCIBLE_PLAYWRIGHT_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/browser-oracle';

        if (!is_file($root . '/node_modules/.bin/playwright')) {
            $this->markTestSkipped('No Playwright install available (browser-oracle missing and CRUCIBLE_PLAYWRIGHT_ROOT unset).');
        }

        return $root;
    }
}
