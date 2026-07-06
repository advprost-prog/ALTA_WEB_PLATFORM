<?php

namespace App\Services\Commerce;

use App\Mail\OrderNotificationMail;
use App\Models\NotificationMailSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class NotificationMailManager
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_DB = 'db';

    public const SOURCE_ENV = 'env';

    public const DB_MAILER_KEY = 'notification_smtp';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $lastSummary = null;

    /**
     * @return array<string, mixed>
     */
    public function send(string $recipient, Mailable $mailable, string $source = self::SOURCE_AUTO): array
    {
        $delivery = $this->resolve($source);
        $this->lastSummary = $this->publicSummary($delivery);

        if (! $delivery['configured']) {
            throw new RuntimeException($delivery['error'] ?? 'Notification mail delivery is not configured.');
        }

        $message = clone $mailable;

        if (filled($delivery['from_address'] ?? null)) {
            $message->from((string) $delivery['from_address'], $delivery['from_name'] ?: null);
        }

        Mail::mailer((string) $delivery['mailer_key'])
            ->to($recipient)
            ->send($message);

        return $this->lastSummary;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTestEmail(string $recipient, string $source = self::SOURCE_AUTO, bool $saveResult = true): array
    {
        try {
            $summary = $this->send($recipient, new OrderNotificationMail(
                notificationSubject: 'Тестове повідомлення ALTA',
                notificationBody: 'Це тестове повідомлення для перевірки email-доставки ALTA_WEB_PLATFORM.',
            ), $source);

            if ($saveResult && $summary['source'] === self::SOURCE_DB) {
                NotificationMailSetting::current()->markTestSuccess();
            }

            return $summary;
        } catch (Throwable $exception) {
            $summary = $this->lastSummary();

            if ($saveResult && $summary['source'] === self::SOURCE_DB) {
                NotificationMailSetting::current()->markTestFailure($this->redact($exception->getMessage()));
            }

            throw new RuntimeException($this->redact($exception->getMessage()), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $source = self::SOURCE_AUTO): array
    {
        return $this->publicSummary($this->resolve($source));
    }

    /**
     * @return array<string, mixed>
     */
    public function lastSummary(): array
    {
        return $this->lastSummary ?? $this->summary();
    }

    public function redact(string $message, ?NotificationMailSetting $settings = null): string
    {
        $secrets = collect([
            config('mail.mailers.smtp.username'),
            config('mail.mailers.smtp.password'),
        ]);

        $settings ??= NotificationMailSetting::query()->orderBy('id')->first();

        if ($settings) {
            $secrets = $secrets->merge($settings->secretsForRedaction());
        }

        foreach ($secrets
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique() as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $source): array
    {
        $source = $this->normalizeSource($source);
        $settings = NotificationMailSetting::query()->orderBy('id')->first();

        if ($source === self::SOURCE_DB) {
            return $this->dbDelivery($settings ?? NotificationMailSetting::current());
        }

        if ($source === self::SOURCE_AUTO && $settings?->isConfigured()) {
            return $this->dbDelivery($settings);
        }

        return $this->envDelivery();
    }

    /**
     * @return array<string, mixed>
     */
    private function dbDelivery(NotificationMailSetting $settings): array
    {
        $summary = $settings->safeSummary();
        $summary['source'] = self::SOURCE_DB;
        $summary['configured'] = $settings->isConfigured();
        $summary['mailer'] = $settings->normalizedMailer();
        $summary['mailer_key'] = self::DB_MAILER_KEY;
        $summary['error'] = null;

        if (! $settings->is_enabled) {
            $summary['error'] = 'DB notification mail settings are disabled.';

            return $summary;
        }

        $errors = $settings->configurationErrors();

        if ($errors !== []) {
            $summary['error'] = 'DB notification mail settings are incomplete: '.implode(', ', $errors).'.';

            return $summary;
        }

        $this->configureDbMailer($settings);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function envDelivery(): array
    {
        $mailer = trim((string) config('mail.default', ''));

        return [
            'source' => self::SOURCE_ENV,
            'configured' => $mailer !== '',
            'mailer' => $mailer,
            'mailer_key' => $mailer,
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'error' => $mailer === '' ? 'Laravel MAIL_MAILER is not configured.' : null,
        ];
    }

    private function configureDbMailer(NotificationMailSetting $settings): void
    {
        $mailer = $settings->normalizedMailer();
        $encryption = $settings->normalizedEncryption();
        $config = match ($mailer) {
            'log' => [
                'transport' => 'log',
                'channel' => config('mail.mailers.log.channel'),
            ],
            'array' => [
                'transport' => 'array',
            ],
            default => [
                'transport' => 'smtp',
                'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => $settings->host,
                'port' => $settings->port,
                'username' => $settings->username,
                'password' => $settings->getDecryptedPassword(),
                'require_tls' => $encryption === 'tls' ? true : null,
                'auto_tls' => $encryption === null ? false : null,
                'verify_peer' => $settings->verify_peer ? null : false,
                'timeout' => $settings->timeout,
                'local_domain' => config('mail.mailers.smtp.local_domain'),
            ],
        };

        config(['mail.mailers.'.self::DB_MAILER_KEY => array_filter(
            $config,
            fn (mixed $value): bool => $value !== null,
        )]);

        $mail = Mail::getFacadeRoot();

        if (is_object($mail) && method_exists($mail, 'purge')) {
            $mail->purge(self::DB_MAILER_KEY);
        }
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @return array<string, mixed>
     */
    private function publicSummary(array $delivery): array
    {
        return [
            'source' => $delivery['source'] ?? self::SOURCE_ENV,
            'configured' => (bool) ($delivery['configured'] ?? false),
            'mailer' => $delivery['mailer'] ?? null,
            'from_address' => $delivery['from_address'] ?? null,
            'from_name' => $delivery['from_name'] ?? null,
        ];
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));

        return in_array($source, [self::SOURCE_AUTO, self::SOURCE_DB, self::SOURCE_ENV], true)
            ? $source
            : self::SOURCE_AUTO;
    }
}
