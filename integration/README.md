# Retrigger producers

Tell a running `crucible --watch` which files changed, so it re-runs the tests those
files put in doubt (D-083).

Crucible deliberately does **not** map a built asset back to a source file. Every way
of doing that — inverting a bundler manifest, inferring a build root, scanning for
browser calls — requires a PHP test tool to own working knowledge of a JavaScript
build system, and each fails in the one direction a test tool must not: silently
running **too few** tests. So whoever already knows what changed says so, and
Crucible's declared impact rules decide what that endangers.

Neither side learns the other's domain. The producer never hears about PHP test
groups; Crucible never hears about chunks or hashes.

## Turning it on

Off by default — a test tool should not open a socket unasked.

```php
// crucible.php
return Crucible::configure()
    ->testSuite('default', ['tests'])
    ->retrigger()                                  // publishes .crucible.hot while watching
    ->impactRule('resources/js', ['browser'])      // what a JS change puts in doubt
    ->build();
```

Then `crucible --watch` prints its endpoint and writes the URL to `.crucible.hot`.
Add that file to `.gitignore`.

## Using the shipped producers

```js
// vite.config.js
import crucible from './integration/vite-plugin-crucible.mjs'

export default defineConfig({
    plugins: [vue(), crucible()],
})
```

Or call the helper from anything — a build script, a git hook, a watcher of your own:

```js
import { retrigger } from './integration/crucible-retrigger.mjs'

await retrigger(['resources/js/Cart.vue'])
```

Both are dependency-free and never throw: a watch session that is not running is
the normal case, not a build failure. Copy the files into your project if that is
easier than referencing them here — there is no package to install and no version
to keep in sync.

## The wire format

The files above are a convenience. The contract is the thing, and it is small
enough that any producer can speak it — `curl`, a Makefile, a CI step, another
language entirely.

**The hot file** holds one line: the full URL, token included.

```
http://127.0.0.1:54321/retrigger?token=6f1e…
```

Its presence is the liveness signal — the same contract a Vite dev server uses.
No file means Crucible is not watching, so a producer should do nothing. It is
removed when the session exits.

**The request** is a POST of paths, absolute or relative to the project root:

```
POST /retrigger?token=<token>
Content-Type: application/json

{"changed": ["resources/js/Cart.vue"]}
```

```bash
curl -s -X POST "$(cat .crucible.hot)" -d '{"changed":["resources/js/Cart.vue"]}'
```

**The response** is `202 {"accepted": N}`. It means the change set was queued, not
that tests passed — results appear in the watch terminal, on the NDJSON event
stream Crucible already emits. There is no broker and no subscription: HTTP in for
the trigger, NDJSON out for results.

| Status | Meaning |
|---|---|
| `202` | queued; `N` paths accepted |
| `400` | body was not `{"changed": [...]}` |
| `403` | bad or missing token |
| `405` | not a POST |

## What the payload may contain

**Paths only.** Never a filter, a group, a test name, or anything command-shaped.
The worst a caller can achieve is making the developer run their own suite over
their own files. Non-string entries are dropped rather than coerced.

The endpoint binds `127.0.0.1` — never a routable interface — and the token guards
against other local processes firing your suite. A fixed port is available for
environments that need one (`->retrigger(port: 9876)`), but the default ephemeral
port is what never collides between projects.
