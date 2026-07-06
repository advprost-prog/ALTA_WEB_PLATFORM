<?php

namespace App\Filament\Pages;

use App\Models\NotificationMailSetting;
use App\Services\Commerce\NotificationMailManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class NotificationMailSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Сервер повідомлень';

    protected static ?string $title = 'Сервер повідомлень';

    protected static string|\UnitEnum|null $navigationGroup = 'Налаштування / Повідомлення';

    protected static ?int $navigationSort = 72;

    protected static ?string $slug = 'notification-mail-settings';

    protected string $view = 'filament-panels::pages.page';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('SMTP для notification email')
                    ->description('Налаштування зберігаються в БД. .env не редагується з адмінки.')
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label('Увімкнути SMTP для повідомлень'),
                        Select::make('mailer')
                            ->label('Mailer')
                            ->options([
                                'smtp' => 'SMTP',
                                'log' => 'Log',
                                'array' => 'Array',
                            ])
                            ->required(),
                        TextInput::make('host')
                            ->label('SMTP host')
                            ->maxLength(255),
                        TextInput::make('port')
                            ->label('Port')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535),
                        Select::make('encryption')
                            ->label('Encryption')
                            ->options([
                                'none' => 'None',
                                'tls' => 'TLS',
                                'ssl' => 'SSL',
                            ])
                            ->helperText('none - без шифрування; tls - STARTTLS, якщо підтримує сервер; ssl - implicit SSL, часто порт 465.')
                            ->required(),
                        TextInput::make('username')
                            ->label('Username')
                            ->maxLength(255)
                            ->autocomplete('off'),
                        Placeholder::make('password_saved')
                            ->label('Saved password')
                            ->content(fn (): string => $this->settings()->hasPassword() ? 'Збережено encrypted' : 'Не задано'),
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->autocomplete('new-password')
                            ->helperText('Порожнє поле не змінює збережений пароль. Для видалення використайте окрему дію.'),
                        TextInput::make('from_address')
                            ->label('From address')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('from_name')
                            ->label('From name')
                            ->maxLength(255),
                        TextInput::make('timeout')
                            ->label('Timeout, секунд')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(300),
                        Toggle::make('verify_peer')
                            ->label('Verify TLS peer'),
                    ])
                    ->columns(2),
                Section::make('Останній тест')
                    ->schema([
                        Placeholder::make('last_tested_at')
                            ->label('Last tested at')
                            ->content(fn (): string => $this->settings()->last_tested_at?->format('Y-m-d H:i:s') ?? '-'),
                        Placeholder::make('last_test_status')
                            ->label('Last test status')
                            ->content(fn (): string => $this->settings()->last_test_status ?: '-'),
                        Placeholder::make('last_test_error')
                            ->label('Last test error')
                            ->content(fn (): string => $this->settings()->last_test_error ?: '-'),
                    ])
                    ->columns(3),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        SchemaActions::make([
                            Action::make('save')
                                ->label('Зберегти налаштування')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                            Action::make('clearPassword')
                                ->label('Очистити пароль')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('clearPassword'),
                            Action::make('disableOverride')
                                ->label('Вимкнути SMTP override')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->action('disableOverride'),
                        ]),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('testEmail')
                ->label('Test email')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->schema([
                    TextInput::make('email')
                        ->label('Отримувач')
                        ->email()
                        ->required()
                        ->default(Auth::user()?->email ?: 'buyer@example.test'),
                ])
                ->action(fn (array $data): null => $this->sendTestEmail((string) $data['email'])),
        ];
    }

    public function save(bool $notify = true): void
    {
        $data = $this->form->getState();
        $settings = $this->settings();

        $settings->forceFill([
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'mailer' => (string) ($data['mailer'] ?? 'smtp'),
            'host' => filled($data['host'] ?? null) ? (string) $data['host'] : null,
            'port' => filled($data['port'] ?? null) ? (int) $data['port'] : null,
            'encryption' => ($data['encryption'] ?? 'none') === 'none' ? null : (string) $data['encryption'],
            'username' => filled($data['username'] ?? null) ? (string) $data['username'] : null,
            'from_address' => filled($data['from_address'] ?? null) ? (string) $data['from_address'] : null,
            'from_name' => filled($data['from_name'] ?? null) ? (string) $data['from_name'] : null,
            'timeout' => filled($data['timeout'] ?? null) ? (int) $data['timeout'] : null,
            'verify_peer' => (bool) ($data['verify_peer'] ?? true),
        ]);

        if (filled($data['password'] ?? null)) {
            $settings->setPassword((string) $data['password']);
        }

        $settings->save();
        $this->fillForm();

        if ($notify) {
            Notification::make()
                ->success()
                ->title('Налаштування email збережено')
                ->send();
        }
    }

    public function sendTestEmail(string $email): null
    {
        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            Notification::make()
                ->danger()
                ->title('Некоректний email')
                ->send();

            return null;
        }

        $this->save(notify: false);

        try {
            app(NotificationMailManager::class)->sendTestEmail(
                recipient: $email,
                source: NotificationMailManager::SOURCE_DB,
            );

            Notification::make()
                ->success()
                ->title('Тестовий email надіслано')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Тестовий email не надіслано')
                ->body(app(NotificationMailManager::class)->redact($exception->getMessage()))
                ->send();
        }

        $this->fillForm();

        return null;
    }

    public function clearPassword(): void
    {
        $settings = $this->settings();
        $settings->clearPassword();
        $settings->save();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('SMTP password очищено')
            ->send();
    }

    public function disableOverride(): void
    {
        $this->settings()->forceFill(['is_enabled' => false])->save();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('SMTP override вимкнено')
            ->send();
    }

    private function fillForm(): void
    {
        $settings = $this->settings();

        $this->form->fill([
            'is_enabled' => $settings->is_enabled,
            'mailer' => $settings->mailer ?: 'smtp',
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $settings->encryption ?: 'none',
            'username' => $settings->username,
            'password' => null,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'timeout' => $settings->timeout,
            'verify_peer' => $settings->verify_peer,
        ]);
    }

    private function settings(): NotificationMailSetting
    {
        return NotificationMailSetting::current()->refresh();
    }
}
