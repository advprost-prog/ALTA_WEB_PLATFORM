<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class NotificationMailSetting extends Model
{
    public const TEST_STATUS_SUCCESS = 'success';

    public const TEST_STATUS_FAILED = 'failed';

    /**
     * @var array<int, string>
     */
    public const MAILERS = ['smtp', 'log', 'array'];

    /**
     * @var array<int, string|null>
     */
    public const ENCRYPTIONS = [null, 'tls', 'ssl'];

    protected $fillable = [
        'is_enabled',
        'mailer',
        'host',
        'port',
        'encryption',
        'username',
        'password_encrypted',
        'from_address',
        'from_name',
        'timeout',
        'verify_peer',
        'last_tested_at',
        'last_test_status',
        'last_test_error',
    ];

    protected $hidden = [
        'password_encrypted',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'port' => 'integer',
            'timeout' => 'integer',
            'verify_peer' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create([
            'is_enabled' => false,
            'mailer' => 'smtp',
            'verify_peer' => true,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->is_enabled && $this->configurationErrors() === [];
    }

    public function hasPassword(): bool
    {
        return filled($this->password_encrypted);
    }

    public function getDecryptedPassword(): ?string
    {
        if (! $this->hasPassword()) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $this->password_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function passwordDecrypts(): bool
    {
        return ! $this->hasPassword() || $this->getDecryptedPassword() !== null;
    }

    public function setPassword(?string $password): void
    {
        if (blank($password)) {
            return;
        }

        $this->password_encrypted = Crypt::encryptString((string) $password);
    }

    public function clearPassword(): void
    {
        $this->password_encrypted = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function safeSummary(): array
    {
        return [
            'source' => 'db',
            'enabled' => (bool) $this->is_enabled,
            'configured' => $this->isConfigured(),
            'mailer' => $this->mailer ?: 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption ?: 'none',
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
            'timeout' => $this->timeout,
            'verify_peer' => (bool) $this->verify_peer,
            'has_password' => $this->hasPassword(),
            'last_tested_at' => $this->last_tested_at?->toISOString(),
            'last_test_status' => $this->last_test_status,
            'last_test_error' => $this->last_test_error,
        ];
    }

    public function markTestSuccess(): void
    {
        $this->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => self::TEST_STATUS_SUCCESS,
            'last_test_error' => null,
        ])->save();
    }

    public function markTestFailure(string $error): void
    {
        $this->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => self::TEST_STATUS_FAILED,
            'last_test_error' => mb_substr($this->redact($error), 0, 2000),
        ])->save();
    }

    /**
     * @return array<int, string>
     */
    public function configurationErrors(): array
    {
        $mailer = $this->normalizedMailer();
        $errors = [];

        if (! in_array($mailer, self::MAILERS, true)) {
            $errors[] = 'mailer';
        }

        if ($mailer === 'smtp') {
            if (blank($this->host)) {
                $errors[] = 'host';
            }

            if (! $this->port) {
                $errors[] = 'port';
            }
        }

        if (filled($this->from_address) && ! filter_var((string) $this->from_address, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'from_address';
        }

        if ($mailer === 'smtp' && blank($this->from_address)) {
            $errors[] = 'from_address';
        }

        if (! in_array($this->normalizedEncryption(), self::ENCRYPTIONS, true)) {
            $errors[] = 'encryption';
        }

        if (! $this->passwordDecrypts()) {
            $errors[] = 'password_decrypt';
        }

        return array_values(array_unique($errors));
    }

    public function normalizedMailer(): string
    {
        return strtolower(trim((string) ($this->mailer ?: 'smtp')));
    }

    public function normalizedEncryption(): ?string
    {
        $encryption = strtolower(trim((string) $this->encryption));

        return $encryption === '' || $encryption === 'none' ? null : $encryption;
    }

    public function redact(string $message): string
    {
        foreach ($this->secretsForRedaction() as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return $message;
    }

    /**
     * @return array<int, string>
     */
    public function secretsForRedaction(): array
    {
        return collect([
            $this->username,
            $this->getDecryptedPassword(),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }
}
