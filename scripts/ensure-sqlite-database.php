<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$driver = getenv('DB_CONNECTION') ?: null;

if ($driver === null && is_file($root.'/.env')) {
    foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\s*DB_CONNECTION\s*=\s*([^#\s]+)\s*$/', $line, $matches) === 1) {
            $driver = trim($matches[1], "\"'");
            break;
        }
    }
}

if ($driver !== null && $driver !== 'sqlite') {
    exit(0);
}

$database = $root.'/database/database.sqlite';

if (! file_exists($database) && ! touch($database)) {
    fwrite(STDERR, "Unable to create the default SQLite database file.\n");
    exit(1);
}
