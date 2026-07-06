<?php

namespace App\Console\Commands;

use App\Mail\OrderNotificationMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TestNotificationEmail extends Command
{
    protected $signature = 'notifications:test-email {email}';

    protected $description = 'Send a technical test email for notification delivery without writing order data.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $this->line('current_mailer: '.(string) config('mail.default'));

        try {
            Mail::to($email)->send(new OrderNotificationMail(
                notificationSubject: 'Тестове повідомлення ALTA',
                notificationBody: 'Це тестове повідомлення для перевірки email-доставки ALTA_WEB_PLATFORM.',
            ));
        } catch (Throwable $exception) {
            $this->error('Email delivery failed: '.$this->safeError($exception));

            return self::FAILURE;
        }

        $this->info('Email delivery succeeded.');

        return self::SUCCESS;
    }

    private function safeError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            $message = $exception::class;
        }

        foreach ($this->mailSecrets() as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return $message;
    }

    /**
     * @return array<int, string>
     */
    private function mailSecrets(): array
    {
        return collect([
            config('mail.mailers.smtp.username'),
            config('mail.mailers.smtp.password'),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }
}
