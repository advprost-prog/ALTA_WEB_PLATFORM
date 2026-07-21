<?php

return [
    'allowed_connections' => array_values(array_filter(explode(',', env('BACKUP_RESTORE_ALLOWED_CONNECTIONS', 'pgsql')))),
    'allowed_databases' => array_values(array_filter(explode(',', env('BACKUP_RESTORE_ALLOWED_DATABASES', env('DB_DATABASE', ''))))),
    'allowed_backend_roles' => array_values(array_filter(explode(',', env('BACKUP_RESTORE_ALLOWED_BACKEND_ROLES', env('DB_USERNAME', ''))))),
    'maintenance_store' => env('BACKUP_RESTORE_MAINTENANCE_STORE', 'file'),
    'require_single_node' => env('BACKUP_RESTORE_REQUIRE_SINGLE_NODE', true),
    'health_paths' => ['/', '/catalog'],
    'admin_connection' => env('BACKUP_RESTORE_ADMIN_CONNECTION'),
    'staging_prefix' => 'alta_restore_',
    'rollback_prefix' => 'alta_rollback_',
    'postgresql_backup' => [
        'enabled' => env('BACKUP_RESTORE_POSTGRESQL_BACKUP_ENABLED', false),
        'connections' => array_values(array_filter(explode(',', env('BACKUP_RESTORE_POSTGRESQL_BACKUP_CONNECTIONS', '')))),
        'client_directory' => env('BACKUP_RESTORE_POSTGRESQL_CLIENT_DIRECTORY'),
        'library_path' => env('BACKUP_RESTORE_POSTGRESQL_LIBRARY_PATH'),
        'server_major_versions' => array_values(array_filter(array_map(
            static fn (string $major): ?int => ctype_digit(trim($major)) ? (int) trim($major) : null,
            explode(',', env('BACKUP_RESTORE_POSTGRESQL_SERVER_MAJORS', '18')),
        ))),
    ],
];
