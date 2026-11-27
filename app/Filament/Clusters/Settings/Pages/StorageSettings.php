<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → Storage: whether photo storage per user is limited, and the default limit (App\Support\StorageQuota). */
class StorageSettings extends SettingsPage
{
    protected const FIELDS = [
        'storage_limits_enabled' => [SiteSettings::STORAGE_LIMITS, 'Limit storage per user'],
        'default_storage_quota_gb' => [SiteSettings::DEFAULT_STORAGE_QUOTA_GB, 'Default limit'],
    ];

    protected const INTEGER_FIELDS = ['default_storage_quota_gb'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 52;

    protected static ?string $slug = 'storage';

    public static function getNavigationLabel(): string
    {
        return __('Storage');
    }

    protected function formValues(): array
    {
        return [
            'storage_limits_enabled' => SiteSettings::storageLimitsEnabled(),
            'default_storage_quota_gb' => SiteSettings::defaultStorageQuotaGb(),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make(__('Storage per user'))
                ->schema([
                    Toggle::make('storage_limits_enabled')
                        ->label(__('Limit storage per user'))
                        ->helperText(__('Each user may only store photos up to a limit; uploads past it are refused. When off, everyone can upload without a limit.'))
                        ->live(),
                    TextInput::make('default_storage_quota_gb')
                        ->label(__('Default limit'))
                        ->integer()
                        ->minValue(SiteSettings::STORAGE_QUOTA_MIN_GB)
                        ->maxValue(SiteSettings::STORAGE_QUOTA_MAX_GB)
                        ->suffix('GB')
                        ->required()
                        ->maxWidth('xs')
                        ->visible(fn (Get $get): bool => (bool) $get('storage_limits_enabled'))
                        ->helperText(__('Applies to every user without their own limit, so changing it changes theirs too. Users who existed before storage limits were added have unlimited storage; set their limit on their user page.')),
                ]),
        ];
    }
}
