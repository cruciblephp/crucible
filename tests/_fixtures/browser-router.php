<?php

/* Router for the built-in server behind browser URL-assertion tests:
 * every path answers 200 with a title naming the path. */

declare(strict_types=1);

echo '<title>Routed</title><h1>ok: ', htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'), '</h1>';
