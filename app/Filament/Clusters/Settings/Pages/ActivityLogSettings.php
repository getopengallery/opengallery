<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\ActivityLevel;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\ActivityEvents\ActivityEventResource;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Log settings: what is recorded and for how long. Opened with the button on
 * the activity log; listed in the menu only for people who cannot open the log.
 */
class ActivityLogSettings extends SettingsPage
{
    protected const FIELDS = [
        'activity_level' => [SiteSettings::ACTIVITY_LEVEL, 'Activity log detail'],
        'activity_retention' => [SiteSettings::ACTIVITY_RETENTION, 'Activity log retention'],
    ];

    protected const INTEGER_FIELDS = ['activity_retention'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'activity-log';

    public static function getNavigationLabel(): string
    {
        return __('Log settings');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! ActivityEventResource::canAccess();
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return ActivityEventResource::canAccess()
            ? [ActivityEventResource::getUrl() => ActivityEventResource::getNavigationLabel(), $this->getTitle()]
            : [];
    }

    protected function formValues(): array
    {
        return [
            'activity_level' => SiteSettings::activityLevel()->value,
            'activity_retention' => SiteSettings::activityRetentionDays(),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make()
                ->description(__('What the dashboard and the activity log record, and for how long. No IP addresses are stored.'))
                ->schema([
                    Radio::make('activity_level')
                        ->label(__('Record'))
                        ->options(ActivityLevel::class)
                        ->required(),
                    TextInput::make('activity_retention')
                        ->label(__('Keep entries for'))
                        ->integer()
                        ->minValue(SiteSettings::RETENTION_MIN_DAYS)
                        ->maxValue(SiteSettings::RETENTION_MAX_DAYS)
                        ->suffix(__('days'))
                        ->required()
                        ->maxWidth('xs')
                        ->helperText(__('Older entries are deleted automatically once a day by the background jobs. The default is 365 days.')),
                ]),
        ];
    }
}
