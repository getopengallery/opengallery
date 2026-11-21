<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\MailDelivery;
use App\Filament\Clusters\Settings\SettingsGroup;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Models\Setting;
use App\Support\MailQueue;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;
use UnitEnum;

/** Settings → E-mail: how e-mail is sent and who it comes from. */
class EmailSettings extends SettingsPage
{
    protected const FIELDS = [
        'mail_delivery' => [SiteSettings::MAIL_DELIVERY, 'E-mail delivery'],
        'mail_host' => [SiteSettings::MAIL_HOST, 'SMTP server'],
        'mail_port' => [SiteSettings::MAIL_PORT, 'SMTP port'],
        'mail_scheme' => [SiteSettings::MAIL_SCHEME, 'SMTP encryption'],
        'mail_username' => [SiteSettings::MAIL_USERNAME, 'SMTP user name'],
        'mail_password' => [SiteSettings::MAIL_PASSWORD, 'SMTP password'],
        'mail_from_address' => [SiteSettings::MAIL_FROM_ADDRESS, 'Sender address'],
        'mail_from_name' => [SiteSettings::MAIL_FROM_NAME, 'Sender name'],
        'mail_hourly_limit' => [SiteSettings::MAIL_HOURLY_LIMIT, 'E-mails per hour'],
    ];

    protected const INTEGER_FIELDS = ['mail_port', 'mail_hourly_limit'];

    protected const SECRET_FIELDS = ['mail_password'];

    /** Form field => key in SiteSettings::mailConfig(). */
    private const MAIL_FIELDS = [
        'mail_delivery' => 'delivery',
        'mail_host' => 'host',
        'mail_port' => 'port',
        'mail_scheme' => 'scheme',
        'mail_username' => 'username',
        'mail_password' => 'password',
        'mail_from_address' => 'from_address',
        'mail_from_name' => 'from_name',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = SettingsGroup::System;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'email';

    public static function getNavigationLabel(): string
    {
        return __('E-mail');
    }

    protected function formValues(): array
    {
        $mail = SiteSettings::storedMail();

        return [
            'mail_delivery' => SiteSettings::mailDelivery()->value,
            'mail_host' => $mail['host'],
            'mail_port' => $mail['port'] ?? 587,
            'mail_scheme' => $mail['scheme'] === 'smtps' ? 'smtps' : 'smtp',
            'mail_username' => $mail['username'],
            'mail_from_address' => $mail['from_address'],
            'mail_from_name' => $mail['from_name'],
            'mail_hourly_limit' => SiteSettings::mailHourlyLimit(),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make(__('Delivery'))
                ->description(__('Used for password resets, e-mail verification and notifications.'))
                ->columns(2)
                ->schema([
                    Radio::make('mail_delivery')
                        ->label(__('Send e-mail through'))
                        ->options(MailDelivery::class)
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                    Callout::make(__('Nobody receives e-mails'))
                        ->description(__('While the test mailbox is on, nobody receives e-mails: no password reset links, invitations or confirmations. Super administrators read them under System → Test mailbox. Switch to a real delivery before people use the gallery.'))
                        ->warning()
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => self::delivery($get('mail_delivery')) === MailDelivery::Mailbox),
                    TextInput::make('mail_host')
                        ->label(__('SMTP server'))
                        ->placeholder('smtp.example.com')
                        ->required()
                        ->maxLength(255)
                        ->visible($smtp = fn (Get $get) => $this->isSmtp($get('mail_delivery'))),
                    TextInput::make('mail_port')
                        ->label(__('Port'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->required()
                        ->maxWidth('xs')
                        ->visible($smtp),
                    Select::make('mail_scheme')
                        ->label(__('Encryption'))
                        ->options([
                            'smtp' => __('STARTTLS (usually port 587)'),
                            'smtps' => __('SSL/TLS (usually port 465)'),
                        ])
                        ->selectablePlaceholder(false)
                        ->required()
                        ->visible($smtp),
                    TextInput::make('mail_username')
                        ->label(__('User name'))
                        ->maxLength(255)
                        ->autocomplete('off')
                        ->visible($smtp),
                    TextInput::make('mail_password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->maxLength(255)
                        ->placeholder(fn () => SiteSettings::hasMailPassword() ? '••••••••' : null)
                        ->helperText(fn () => SiteSettings::hasMailPassword() ? __('Saved. Leave empty to keep it.') : null)
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->visible($smtp),
                    TextInput::make('mail_hourly_limit')
                        ->label(__('E-mails per hour'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(SiteSettings::MAIL_HOURLY_MAX)
                        ->required()
                        ->maxWidth('xs')
                        ->hint(fn (): ?string => ($waiting = MailQueue::waiting()) > 0
                            ? trans_choice(':count e-mail is waiting|:count e-mails are waiting', $waiting)
                            : null)
                        ->helperText(__('Notices to many people are sent in portions by the background jobs, up to this many per hour. Use the limit of your host or e-mail provider.'))
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => self::delivery($get('mail_delivery')) !== MailDelivery::Mailbox),
                ]),

            Section::make(__('Sender'))
                ->columns(2)
                ->schema([
                    TextInput::make('mail_from_address')
                        ->label(__('Sender address'))
                        ->email()
                        ->maxLength(255)
                        ->placeholder(fn () => config('mail.from.address'))
                        ->helperText(__('Leave empty to use the server configuration.')),
                    TextInput::make('mail_from_name')
                        ->label(__('Sender name'))
                        ->maxLength(80)
                        ->helperText(__('Leave empty to use the site name.')),
                    Actions::make([
                        Action::make('sendTestMail')
                            ->label(__('Send a test e-mail'))
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->color('gray')
                            ->action(fn () => $this->sendTestMail()),
                    ])->columnSpanFull(),
                ]),
        ];
    }

    protected function afterFieldsStored(array $data): void
    {
        // Without a user name there is nothing to log in with.
        if (array_key_exists('mail_username', $data) && blank($data['mail_username']) && SiteSettings::hasMailPassword()) {
            Setting::put(SiteSettings::MAIL_PASSWORD, null);
        }
    }

    protected function afterSettingsApplied(): void
    {
        Mail::purge();
    }

    private function isSmtp(mixed $delivery): bool
    {
        return self::delivery($delivery) === MailDelivery::Smtp;
    }

    private static function delivery(mixed $delivery): ?MailDelivery
    {
        return $delivery instanceof MailDelivery ? $delivery : MailDelivery::tryFrom((string) $delivery);
    }

    /**
     * Sends a test message to the current user with the mail settings as
     * they are in the form (saved or not); an empty password field uses the
     * saved password.
     */
    public function sendTestMail(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->validate();

        $mail = SiteSettings::storedMail();
        foreach (self::MAIL_FIELDS as $field => $key) {
            $value = $this->data[$field] ?? null;
            $value = $value instanceof BackedEnum ? $value->value : $value;

            if ($key !== 'password' || filled($value)) {
                $mail[$key] = is_string($value) ? (trim($value) ?: null) : $value;
            }
        }

        config(SiteSettings::mailConfig($mail));
        Mail::purge();

        $mailer = config('mail.default');
        $email = (string) auth()->user()->email;

        try {
            Mail::raw(
                __('This is a test message from :site. E-mail delivery works.', ['site' => config('app.name')]),
                fn (Message $message) => $message->to($email)->subject(__('Test e-mail from :site', ['site' => config('app.name')])),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('The test e-mail could not be sent'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        } finally {
            // Leave the saved configuration in place for the rest of the request.
            SiteSettings::apply();
            Mail::purge();
        }

        Notification::make()
            ->title(match ($mailer) {
                'mailbox' => __('Test e-mail is in the test mailbox'),
                'log' => __('Test e-mail written to the log file'),
                default => __('Test e-mail sent to :email', ['email' => $email]),
            })
            ->success()
            ->send();
    }
}
