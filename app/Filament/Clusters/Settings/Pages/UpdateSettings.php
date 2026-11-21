<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\UpdateChannel;
use App\Filament\Clusters\Settings\SettingsGroup;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\DateFormat;
use App\Support\SiteSettings;
use App\Support\UpdateCheck;
use App\Support\Updates\UpdateFailed;
use App\Support\Updates\UpdateInstaller;
use App\Support\Updates\UpdatePreflight;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;
use UnitEnum;

/** Settings → Updates: whether the gallery looks for new versions, and installing them. */
class UpdateSettings extends SettingsPage
{
    protected const FIELDS = [
        'update_checks' => [SiteSettings::UPDATE_CHECKS, 'Update checks'],
        'update_channel' => [SiteSettings::UPDATE_CHANNEL, 'Update channel'],
    ];

    /** Wrong passwords allowed per minute when installing an update. */
    private const PASSWORD_ATTEMPTS = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpCircle;

    protected static string|UnitEnum|null $navigationGroup = SettingsGroup::System;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'updates';

    public static function getNavigationLabel(): string
    {
        return __('Updates');
    }

    protected function formValues(): array
    {
        return [
            'update_checks' => SiteSettings::updateChecksEnabled(),
            'update_channel' => SiteSettings::updateChannel()->value,
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make()
                ->description(__('Once a day the gallery asks GitHub whether a newer version exists. GitHub sees your server\'s address; nothing about your gallery is sent. Nothing is downloaded or installed automatically.'))
                ->schema([
                    Toggle::make('update_checks')
                        ->label(__('Check for new versions'))
                        ->live(),
                    Radio::make('update_channel')
                        ->label(__('Update channel'))
                        ->options(UpdateChannel::class)
                        ->required()
                        ->visible(fn (Get $get) => (bool) $get('update_checks')),
                ]),
            Section::make()
                ->schema([
                    Text::make(fn () => __('Installed version: :version', ['version' => AppVersion::current()])),
                    Text::make(fn () => self::lastCheckText())
                        ->color('gray')
                        ->visible(fn () => SiteSettings::updateChecksEnabled()),
                    Callout::make(fn () => self::resultHeading())
                        ->description(fn () => self::resultDetail())
                        ->status(fn () => UpdateInstaller::lastResult()['status'] === 'updated' ? 'success' : 'danger')
                        ->visible(fn () => UpdateInstaller::lastResult() !== null),
                    View::make('filament.settings.update-progress')
                        ->visible(fn () => self::mayInstall() && UpdateInstaller::preparing() !== null),
                    Callout::make(fn () => __('Version :version is available', ['version' => UpdateCheck::available()['version'] ?? '']))
                        ->description(fn () => self::updateDescription())
                        ->info()
                        ->actions([
                            $this->installAction(),
                            Action::make('viewRelease')
                                ->label(__('View release'))
                                ->color('gray')
                                ->url(fn () => UpdateCheck::available()['url'] ?? null, shouldOpenInNewTab: true),
                        ])
                        ->visible(fn () => UpdateCheck::available() !== null && UpdateInstaller::preparing() === null),
                    Actions::make([
                        Action::make('checkNow')
                            ->label(__('Check now'))
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->color('gray')
                            ->action(fn () => $this->checkNow()),
                    ])->visible(fn () => SiteSettings::updateChecksEnabled() && UpdateInstaller::preparing() === null),
                ]),
        ];
    }

    private function installAction(): Action
    {
        return Action::make('installUpdate')
            ->label(__('Install update'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->requiresConfirmation()
            ->modalHeading(fn () => __('Install version :version?', ['version' => UpdateCheck::available()['version'] ?? '']))
            ->modalDescription(__('The gallery is offline for visitors for a few minutes. Your database is backed up first and put back automatically if the update fails. Your photos are not changed. A separate backup is still a good idea.'))
            ->modalSubmitActionLabel(__('Install update'))
            ->schema([
                TextInput::make('password')
                    ->label(__('Your password'))
                    ->password()
                    ->required()
                    ->autocomplete('current-password'),
            ])
            ->visible(fn () => self::mayInstall() && UpdatePreflight::serverProblems() === [])
            ->action(function (array $data, Action $action): void {
                if (! $this->startInstall((string) $data['password'])) {
                    $action->halt();
                }
            });
    }

    /** Turning the checks on (again) should not leave the admin waiting for the next daily run. */
    protected function afterSettingsApplied(): void
    {
        if (SiteSettings::updateChecksEnabled() && UpdateCheck::isStale()) {
            UpdateCheck::run();
        }
    }

    public function checkNow(): void
    {
        abort_unless(static::canAccess() && SiteSettings::updateChecksEnabled(), 403);

        if (! UpdateCheck::run()) {
            Notification::make()->title(__('The update check failed. Try again later.'))->danger()->send();

            return;
        }

        $update = UpdateCheck::available();

        Notification::make()
            ->title(match (true) {
                $update !== null => __('Version :version is available', ['version' => $update['version']]),
                AppVersion::isRelease(AppVersion::current()) => __('This gallery is up to date'),
                default => __('This copy has no release version, so it cannot be compared with new versions.'),
            })
            ->success()
            ->send();
    }

    /** @return bool whether the preparation started */
    public function startInstall(string $password): bool
    {
        abort_unless(self::mayInstall(), 403);

        /** @var User $user */
        $user = auth()->user();
        $limiter = 'update-install:'.$user->id;

        if (RateLimiter::tooManyAttempts($limiter, self::PASSWORD_ATTEMPTS)) {
            Notification::make()->title(__('Too many attempts. Try again in a minute.'))->danger()->send();

            return false;
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($limiter, 60);
            Notification::make()->title(__('The password is not correct.'))->danger()->send();

            return false;
        }

        RateLimiter::clear($limiter);

        try {
            UpdateInstaller::start($user);
        } catch (UpdateFailed $failure) {
            Notification::make()->title(__('The update could not be started.'))->body($failure->getMessage())->danger()->persistent()->send();

            return false;
        }

        return true;
    }

    /** @return array{phase?: string, percent?: int, error?: string} */
    #[Renderless]
    public function advanceInstall(): array
    {
        abort_unless(self::mayInstall(), 403);

        try {
            return UpdateInstaller::advance();
        } catch (UpdateFailed $failure) {
            return ['error' => $failure->getMessage()];
        }
    }

    /** @return array{token?: string, url?: string, error?: string} */
    #[Renderless]
    public function handOverInstall(): array
    {
        abort_unless(self::mayInstall(), 403);

        /** @var User $user */
        $user = auth()->user();

        try {
            return ['token' => UpdateInstaller::handOver($user), 'url' => url('/')];
        } catch (UpdateFailed $failure) {
            return ['error' => $failure->getMessage()];
        }
    }

    private static function mayInstall(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return static::canAccess() && UpdateInstaller::allowed($user);
    }

    private static function updateDescription(): string
    {
        $manual = __('Back up your gallery, download the new version from its release page and follow the upgrade steps in the documentation.');

        if (! self::mayInstall()) {
            return $manual;
        }

        $problems = UpdatePreflight::serverProblems();

        return $problems === []
            ? __('You can install it here. The gallery is backed up first and restored automatically if something fails.')
            : implode(' ', [...$problems, $manual]);
    }

    private static function resultHeading(): string
    {
        $result = UpdateInstaller::lastResult();

        return ($result['status'] ?? null) === 'updated'
            ? __('Updated to version :version', ['version' => $result['version']])
            : __('The update to version :version failed', ['version' => $result['version'] ?? '']);
    }

    private static function resultDetail(): string
    {
        $result = UpdateInstaller::lastResult();
        $at = DateFormat::dateTime(now()->setTimestamp((int) ($result['at'] ?? 0)));

        if (($result['status'] ?? null) === 'updated') {
            return __('Installed on :time.', ['time' => $at]);
        }

        $restored = __('Version :version was put back on :time.', ['version' => $result['from'] ?? '', 'time' => $at]);

        return self::mayInstall() && filled($result['detail'] ?? null) ? "{$restored} {$result['detail']}" : $restored;
    }

    private static function lastCheckText(): string
    {
        $status = UpdateCheck::status();
        $checkedAt = $status['checked_at'] === null ? null : DateFormat::dateTime(now()->setTimestamp($status['checked_at']));

        return match (true) {
            $status['failed'] && $checkedAt !== null => __('The last check failed. Last successful check: :time.', ['time' => $checkedAt]),
            $status['failed'] => __('The last check failed.'),
            $checkedAt !== null => __('Last check: :time.', ['time' => $checkedAt]),
            default => __('No check has run yet.'),
        };
    }
}
