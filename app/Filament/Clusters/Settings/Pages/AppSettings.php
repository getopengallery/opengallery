<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\ImagePreview;
use App\Filament\Clusters\Settings\SettingsGroup;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Support\SiteSettings;
use App\Support\WebApp;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → App: installing the site as an app. */
class AppSettings extends SettingsPage
{
    protected const FIELDS = [
        'web_app' => [SiteSettings::WEB_APP, 'Installable app'],
        'app_icon' => [SiteSettings::APP_ICON, 'App icon'],
    ];

    protected const FILE_FIELDS = ['app_icon'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = SettingsGroup::Site;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'app';

    public static function getNavigationLabel(): string
    {
        return __('App');
    }

    protected function formValues(): array
    {
        return [
            'web_app' => WebApp::enabled(),
            'app_icon' => SiteSettings::assetPath('app-icon'),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make()
                ->description(__('Visitors can add the site to their home screen or install it from the browser, and get a notice instead of an error while they are offline.'))
                ->columns(2)
                ->schema([
                    Toggle::make('web_app')
                        ->label(__('Allow installing the site as an app'))
                        ->helperText(__('Photos and pages are never stored on the device; only the offline notice and the site\'s styles are.'))
                        ->live()
                        ->inline(false),
                    self::brandingImage('app_icon', __('App icon'))
                        ->previewAs(ImagePreview::Icon)
                        ->acceptedFileTypes(['image/png', 'image/webp', 'image/jpeg'])
                        ->maxSize(2048)
                        ->visible(fn (Get $get): bool => (bool) $get('web_app'))
                        ->helperText(__('Square PNG, WebP or JPEG, at least 512 × 512 px, without transparency. Leave empty to use the favicon if it is a PNG, otherwise the built-in mark.')),
                ]),
        ];
    }

    protected function fileReplaced(string $field): void
    {
        WebApp::forgetIcons();
    }
}
