<?php

namespace App\Console\Commands;

use App\Services\Commerce\NotificationMailManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TestNotificationEmail extends Command
{
    protected $signature = 'notifications:test-email
        {email}
        {--env-only : Use Laravel environment mail config only}
        {--db-only : Use admin-configured notification mail settings only}
        {--no-save-result : Do not update DB settings last test status}';

    protected $description = 'Send a technical test email for notification delivery without writing order data.';

    public function handle(NotificationMailManager $mail): int
    {
        $email = trim((string) $this->argument('email'));
        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        if ($this->option('env-only') && $this->option('db-only')) {
            $this->error('Use only one of --env-only or --db-only.');

            return self::FAILURE;
        }

        $source = match (true) {
            (bool) $this->option('env-only') => NotificationMailManager::SOURCE_ENV,
            (bool) $this->option('db-only') => NotificationMailManager::SOURCE_DB,
            default => NotificationMailManager::SOURCE_AUTO,
        };

        $summary = $mail->summary($source);

        $this->line('current_source: '.(string) $summary['source']);
        $this->line('current_mailer: '.(string) $summary['mailer']);
        $this->line('from_address: '.((string) ($summary['from_address'] ?? '') ?: '-'));

        try {
            $mail->sendTestEmail(
                recipient: $email,
                source: $source,
                saveResult: ! $this->option('no-save-result'),
            );
        } catch (Throwable $exception) {
            $this->error('Email delivery failed: '.$mail->redact($exception->getMessage()));

            return self::FAILURE;
        }

        $this->info('Email delivery succeeded.');

        return self::SUCCESS;
    }
}
