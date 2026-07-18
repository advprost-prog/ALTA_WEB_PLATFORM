<?php

declare(strict_types=1);

$required = ['PG_H2_PASSWORD', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'];

foreach ($required as $name) {
    if (getenv($name) === false || trim((string) getenv($name)) === '') {
        fwrite(STDERR, "Missing required PostgreSQL test variable: {$name}\n");
        exit(2);
    }
}

$database = (string) getenv('DB_DATABASE');
$host = strtolower((string) getenv('DB_HOST'));

if (! str_starts_with($database, 'alta_pg_h2_')) {
    fwrite(STDERR, "PostgreSQL test database must use the alta_pg_h2_ disposable prefix.\n");
    exit(2);
}

if (! in_array($host, ['127.0.0.1', 'localhost', 'postgresql'], true)) {
    fwrite(STDERR, "PostgreSQL tests may only target the local disposable service.\n");
    exit(2);
}

putenv('DB_CONNECTION=pgsql');
putenv('DB_PASSWORD='.(string) getenv('PG_H2_PASSWORD'));
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');

$root = dirname(__DIR__);
$commands = [
    [PHP_BINARY, 'artisan', 'config:clear', '--quiet'],
    [PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'],
    [PHP_BINARY, 'vendor/bin/phpunit', '--configuration', 'phpunit.postgresql.xml'],
];

foreach ($commands as $command) {
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, null, ['bypass_shell' => true]);

    if (! is_resource($process) || proc_close($process) !== 0) {
        exit(1);
    }
}
