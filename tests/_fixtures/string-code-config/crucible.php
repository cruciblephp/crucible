<?php

declare(strict_types=1);

// A database a configuration reaches for is down: PDO puts the SQLSTATE,
// a string, where every other exception keeps an int code.
$down = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
(new ReflectionProperty($down, 'code'))->setValue($down, 'HY000');

throw $down;
