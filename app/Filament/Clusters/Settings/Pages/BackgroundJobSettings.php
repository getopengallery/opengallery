<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\BackgroundJobMode;
use App\Filament\Clusters\Settings\SettingsGroup;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Support\ActivityLog;
use App\Support\BackgroundJobs;
use App\Support\DateFormat;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → Background jobs: what starts the regular housekeeping. */
class BackgroundJobSettings extends SettingsPage
{
    protected const FIELDS = [
        'background_jobs' => [SiteSettings::BACKGROUND_JOBS, 'Background jobs'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = SettingsGroup::System;

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'background-jobs';

    public static function getNavigationLabel(): string
    {
        return __('Background jobs');
    }

    protected function formValues(): array
    {
        return [
            'background_jobs' => SiteSettings::backgroundJobs()->value,
            'webcron_url' => BackgroundJobs::webcronUrl(),
            'cron_command' => self::cronCommand(),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make()
                ->description(__('Regular housekeeping: deleting old activity log entries and unfinished uploads, and making thumbnails that wait in the background. Choose what starts it.'))
                ->schema([
                    Radio::make('background_jobs')
                        ->label(__('Start background jobs with'))
                        ->options(BackgroundJobMode::class)
                        ->required()
                        ->live(),
                    Text::make(fn () => self::lastRunText())
                        ->color('gray'),
                    Callout::make(__('Not set up yet'))
                        ->description(fn (Get $get) => self::jobMode($get('background_jobs')) === BackgroundJobMode::Cron
                            ? __('No cron job has started the background jobs in the last hour. Add the line below in your hosting panel.')
                            : __('The webcron address has not been opened in the last hour. Enter it in your webcron service; it only works once this choice is saved.'))
                        ->warning()
                        ->visible(fn (Get $get) => self::awaitsTrigger(self::jobMode($get('background_jobs')))),
                    TextInput::make('webcron_url')
                        ->label(__('Webcron address'))
                        ->readOnly()
                        ->copyable()
                        ->dehydrated(false)
                        ->helperText(__('Have a webcron service open this address every 5 to 15 minutes. Keep it to yourself: anyone who knows it can start the background jobs (at most once a minute).'))
                        ->hintAction(
                            Action::make('renewWebcronAddress')
                                ->label(__('New address'))
                                ->icon(Heroicon::OutlinedArrowPath)
                                ->requiresConfirmation()
                                ->modalDescription(__('The current address stops working at once. Enter the new one in your webcron service.'))
                                ->action(fn () => $this->renewWebcronAddress()),
                        )
                        ->visible(fn (Get $get) => self::jobMode($get('background_jobs')) === BackgroundJobMode::Webcron),
                    TextInput::make('cron_command')
                        ->label(__('Cron job'))
                        ->readOnly()
                        ->copyable()
                        ->dehydrated(false)
                        ->helperText(__('Add this line as a cron job in your hosting panel. Panels that ask for the times separately need "every minute" and the command after the five stars.'))
                        ->visible(fn (Get $get) => self::jobMode($get('background_jobs')) === BackgroundJobMode::Cron),
                ]),
        ];
    }

    private static function jobMode(mixed $mode): ?BackgroundJobMode
    {
        return $mode instanceof BackgroundJobMode ? $mode : BackgroundJobMode::tryFrom((string) $mode);
    }

    /** The line to add as a cron job, with the real path of this installation. */
    private static function cronCommand(): string
    {
        $artisan = base_path('artisan');

        return '* * * * * php '.(preg_match('/^[\w\/.\-]+$/', $artisan) ? $artisan : escapeshellarg($artisan)).' schedule:run';
    }

    private static function lastRunText(): string
    {
        $last = BackgroundJobs::lastRun();

        if ($last === null) {
            return __('The background jobs have not run yet.');
        }

        return __('Last run: :time, started by :trigger.', [
            'time' => DateFormat::dateTime($last['at']),
            'trigger' => $last['via']?->getLabel() ?? '–',
        ]);
    }

    /** Whether cron or webcron is chosen but has not started the jobs in the last hour. */
    private static function awaitsTrigger(?BackgroundJobMode $mode): bool
    {
        if (! in_array($mode, [BackgroundJobMode::Cron, BackgroundJobMode::Webcron], true)) {
            return false;
        }

        $seen = BackgroundJobs::lastSeen($mode);

        return $seen === null || $seen->lt(now()->subHour());
    }

    /** Replaces the secret webcron address (logged as a settings change). */
    public function renewWebcronAddress(): void
    {
        abort_unless(static::canAccess(), 403);

        BackgroundJobs::renewWebcronToken();
        $this->data['webcron_url'] = BackgroundJobs::webcronUrl();
        ActivityLog::settingsChanged(['Webcron address']);

        Notification::make()->title(__('New webcron address created'))->success()->send();
    }
}
