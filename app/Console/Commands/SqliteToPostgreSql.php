<?php

namespace App\Console\Commands;

use App\Support\DatabaseTransition\OneOffSqliteToPostgreSqlImporter;
use App\Support\DatabaseTransition\SqliteToPostgreSqlVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SqliteToPostgreSql extends Command
{
    protected $signature = 'data:sqlite-to-postgresql
        {--source-snapshot= : Absolute protected SQLite snapshot path}
        {--source-sha256= : Required snapshot SHA-256 pin}
        {--target-connection=pgsql_transition : Dedicated non-default PostgreSQL connection}
        {--confirm= : Must equal IMPORT_REAL_DATA}
        {--report= : Absolute protected report path outside the repository}';

    protected $description = 'One-off import of the pinned SQLite snapshot into the isolated PostgreSQL transition target';

    public function handle(OneOffSqliteToPostgreSqlImporter $importer, SqliteToPostgreSqlVerifier $verifier): int
    {
        try {
            if ($this->option('confirm') !== 'IMPORT_REAL_DATA') {
                throw new RuntimeException('Explicit --confirm=IMPORT_REAL_DATA is required.');
            }
            if ($this->option('target-connection') !== 'pgsql_transition') {
                throw new RuntimeException('Only the pgsql_transition connection is allowed.');
            }
            $reportPath = (string) $this->option('report');
            if (! str_starts_with($reportPath, DIRECTORY_SEPARATOR) || str_starts_with($reportPath, base_path().DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('A report path outside the repository is required.');
            }
            $target = DB::connection('pgsql_transition');
            $snapshot = (string) $this->option('source-snapshot');
            $sha256 = (string) $this->option('source-sha256');
            $result = $importer->import($snapshot, $sha256, $target);
            $verification = $verifier->verify($snapshot, $target, $result);
            $identity = $target->selectOne("SELECT current_database() database, current_setting('server_version_num')::int version");
            $report = [
                'operation_id' => 'SQLITE-PG-'.gmdate('Ymd\THis\Z'),
                'source_snapshot_sha256' => $sha256,
                'target' => ['host' => '127.0.0.1', 'database' => $identity->database, 'server_version_num' => (int) $identity->version, 'schema' => 'public'],
                'verification' => $verification,
                'created_at' => gmdate(DATE_ATOM),
            ];
            $this->writeReport($reportPath, $report);
            $this->info('SQLite data imported and verified in the non-default PostgreSQL target.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function writeReport(string $path, array $report): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create report directory.');
        }
        chmod($directory, 0700);
        $temporary = $path.'.tmp';
        file_put_contents($temporary, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
        chmod($temporary, 0600);
        rename($temporary, $path);
    }
}
