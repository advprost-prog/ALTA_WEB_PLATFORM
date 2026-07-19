<?php

namespace App\Support\Addons\BackupRestore;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;

final class LaravelHostRestoreBridge
{
    public function __construct(private DatabaseManager $database) {}

    public function preflight(array $request): array
    {
        $connection = $this->connection($request);
        $definition = (array) config('database.connections.'.$connection);
        $database = (string) ($definition['database'] ?? '');
        $role = (string) ($definition['username'] ?? '');
        $driver = $definition['driver'] ?? null;
        if (! in_array($driver, ['pgsql', 'sqlite'], true) || ! in_array($database, (array) config('backup-restore-host.allowed_databases'), true) || ($driver === 'pgsql' && ! in_array($role, (array) config('backup-restore-host.allowed_backend_roles'), true))) {
            throw new HostRestoreException('restore_host_target_untrusted', 'Host restore target is not allowlisted.');
        }
        if (config('backup-restore-host.require_single_node', true) && ($request['single_node'] ?? false) !== true) {
            throw new HostRestoreException('restore_host_single_node_unproven', 'Single-node restore evidence is required.');
        }
        if (config('backup-restore-host.maintenance_store') !== 'file') {
            throw new HostRestoreException('restore_host_maintenance_unsafe', 'Restore maintenance must be database-independent.');
        }
        if ($driver === 'sqlite') {
            if ($database === '' || ! is_file($database) || is_link($database)) {
                throw new HostRestoreException('restore_host_sqlite_target_unsafe', 'SQLite restore target must be an existing regular file.');
            }

            return ['connection' => $connection, 'database_digest' => hash('sha256', $database), 'driver' => 'sqlite', 'single_node' => true];
        }
        $identity = $this->database->connection($connection)->selectOne("SELECT current_database() database,current_user role,current_setting('server_version_num')::int version");

        return ['connection' => $connection, 'database_digest' => hash('sha256', $identity->database), 'role_digest' => hash('sha256', $identity->role), 'server_version_num' => (int) $identity->version, 'single_node' => true];
    }

    public function enterMaintenance(array $request): array
    {
        $this->requireAcknowledgements($request);
        Artisan::call('down', ['--render' => 'errors::503']);
        Artisan::call('queue:restart');
        if (Artisan::all()['schedule:interrupt'] ?? null) {
            Artisan::call('schedule:interrupt');
        }
        if (! app()->isDownForMaintenance()) {
            throw new HostRestoreException('restore_host_maintenance_failed', 'Host maintenance could not be proven.');
        }

        return ['maintenance' => true, 'writers_fenced' => true, 'queues_restart_signalled' => true, 'schedulers_interrupt_signalled' => true];
    }

    public function drain(array $request): array
    {
        $connection = $this->connection($request);
        $definition = (array) config('database.connections.'.$connection);
        $database = (string) $definition['database'];
        if (($definition['driver'] ?? null) === 'sqlite') {
            $this->database->purge($connection);

            return ['inventory_count' => 0, 'terminated_count' => 0, 'database_digest' => hash('sha256', $database)];
        }
        $allowedRoles = (array) config('backup-restore-host.allowed_backend_roles');
        $pdo = $this->database->connection($connection)->getPdo();
        $inventory = $pdo->prepare('SELECT pid,usename FROM pg_stat_activity WHERE datname=? AND pid<>pg_backend_pid() ORDER BY pid');
        $inventory->execute([$database]);
        $rows = $inventory->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (! in_array($row['usename'], $allowedRoles, true)) {
                throw new HostRestoreException('restore_host_backend_untrusted', 'An active backend role is outside the termination allowlist.');
            }
        }
        $terminated = 0;
        foreach ($rows as $row) {
            $statement = $pdo->prepare('SELECT pg_terminate_backend(?)');
            $statement->execute([(int) $row['pid']]);
            $terminated += $statement->fetchColumn() ? 1 : 0;
        }
        $this->database->purge($connection);

        return ['inventory_count' => count($rows), 'terminated_count' => $terminated, 'database_digest' => hash('sha256', $database)];
    }

    public function reconnect(array $request): array
    {
        $connection = $this->connection($request);
        $this->database->purge($connection);
        $pdo = $this->database->reconnect($connection)->getPdo();
        $definition = (array) config('database.connections.'.$connection);
        $database = ($definition['driver'] ?? null) === 'sqlite' ? (string) ($definition['database'] ?? '') : $pdo->query('SELECT current_database()')->fetchColumn();

        return ['reconnected' => true, 'database_digest' => hash('sha256', (string) $database)];
    }

    public function switchDatabase(array $request): array
    {
        $connection = $this->connection($request);
        $definition = (array) config('database.connections.'.$connection);
        $live = $this->identifier($definition['database'] ?? null);
        $staged = $this->identifier($request['staging']['target_database'] ?? null);
        $operation = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($request['operation_id'] ?? '')));
        if ($operation === '' || ! str_starts_with($staged, (string) config('backup-restore-host.staging_prefix'))) {
            throw new HostRestoreException('restore_host_switch_target_untrusted', 'Staged database identity is not trusted.');
        }
        $rollback = $this->identifier(config('backup-restore-host.rollback_prefix').substr($operation, 0, 32));
        $admin = config('backup-restore-host.admin_connection');
        if (! is_string($admin) || $admin === '' || $admin === $connection) {
            throw new HostRestoreException('restore_host_admin_connection_unsafe', 'Independent restore admin connection is required.');
        }
        $pdo = $this->database->connection($admin)->getPdo();
        $role = $pdo->query("SELECT rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls,pg_has_role(current_user,'pg_signal_backend','MEMBER') can_signal FROM pg_roles WHERE rolname=current_user")->fetch(\PDO::FETCH_ASSOC);
        if (! $role || $role['rolsuper'] || ! $role['rolcreatedb'] || $role['rolcreaterole'] || $role['rolreplication'] || $role['rolbypassrls'] || ! $role['can_signal']) {
            throw new HostRestoreException('restore_host_admin_role_unsafe', 'Host restore admin role violates least-privilege policy.');
        }
        $pdo->exec(sprintf('ALTER DATABASE "%s" RENAME TO "%s"', $live, $rollback));
        try {
            $pdo->exec(sprintf('ALTER DATABASE "%s" RENAME TO "%s"', $staged, $live));
        } catch (\Throwable $failure) {
            $pdo->exec(sprintf('ALTER DATABASE "%s" RENAME TO "%s"', $rollback, $live));
            throw $failure;
        }

        return ['live_database_digest' => hash('sha256', $live), 'rollback_database' => $rollback, 'staged_database_digest' => hash('sha256', $staged)];
    }

    public function health(array $request): array
    {
        if (($request['zero_write'] ?? false) !== true) {
            throw new HostRestoreException('restore_host_health_mode_unsafe', 'Only zero-write restore health checks are allowed.');
        }
        $connection = $this->connection($request);
        $result = $this->database->connection($connection)->selectOne('SELECT 1 ok');
        if ((int) ($result->ok ?? 0) !== 1) {
            throw new HostRestoreException('restore_host_health_failed', 'Host database health check failed.');
        }

        return ['database' => true, 'application_booted' => app()->bound('router'), 'zero_write' => true];
    }

    public function rollback(array $request): array
    {
        $switch = $request['evidence']['host_switch'] ?? null;
        if (is_array($switch) && is_string($switch['rollback_database'] ?? null)) {
            $connection = $this->connection($request);
            $live = $this->identifier(config('database.connections.'.$connection.'.database'));
            $rollback = $this->identifier($switch['rollback_database']);
            $admin = config('backup-restore-host.admin_connection');
            $this->drain($request);
            $pdo = $this->database->connection($admin)->getPdo();
            $failed = $this->identifier('alta_failed_'.substr(hash('sha256', $live.'|'.$rollback), 0, 24));
            $pdo->exec(sprintf('ALTER DATABASE "%s" RENAME TO "%s"', $live, $failed));
            try {
                $pdo->exec(sprintf('ALTER DATABASE "%s" RENAME TO "%s"', $rollback, $live));
            } catch (\Throwable $failure) {
                $pdo->exec(sprintf('ALTER DATABASE "%s" RENAME TO "%s"', $failed, $live));
                throw $failure;
            }

            return ['rollback_reconciled' => true, 'failed_database' => $failed, 'live_database_digest' => hash('sha256', $live)];
        }
        $previous = $request['previous_connection'] ?? null;
        $current = $request['target_connection'] ?? null;
        if (is_string($current) && in_array($current, (array) config('backup-restore-host.allowed_connections'), true) && config('database.connections.'.$current.'.driver') === 'sqlite' && $previous === null) {
            return $this->reconnect($request) + ['rollback_reconciled' => true];
        }
        if (! is_string($previous) || ! in_array($previous, (array) config('backup-restore-host.allowed_connections'), true)) {
            throw new HostRestoreException('restore_host_rollback_target_untrusted', 'Host rollback target is not allowlisted.');
        }

        return $this->reconnect(array_merge($request, ['target_connection' => $previous])) + ['rollback_reconciled' => true];
    }

    public function leaveMaintenance(array $request): array
    {
        Artisan::call('up');
        if (app()->isDownForMaintenance()) {
            throw new HostRestoreException('restore_host_maintenance_release_failed', 'Host maintenance could not be released.');
        }

        return ['maintenance' => false, 'workers_require_supervisor_restart' => true];
    }

    private function connection(array $request): string
    {
        $connection = $request['target_connection'] ?? null;
        if (! is_string($connection) || ! in_array($connection, (array) config('backup-restore-host.allowed_connections'), true)) {
            throw new HostRestoreException('restore_host_connection_untrusted', 'Host connection is not allowlisted.');
        }

        return $connection;
    }

    private function requireAcknowledgements(array $request): void
    {
        foreach (['downtime', 'web_writers_stopped', 'queue_workers_stopped', 'schedulers_stopped'] as $key) {
            if (($request['acknowledgements'][$key] ?? false) !== true) {
                throw new HostRestoreException('restore_host_acknowledgement_missing', 'Host fencing acknowledgement is missing.');
            }
        }
    }

    private function identifier(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-z][a-z0-9_]{2,62}$/D', $value)) {
            throw new HostRestoreException('restore_host_database_identity_invalid', 'Host database identity is invalid.');
        }

        return $value;
    }
}
