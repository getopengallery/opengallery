<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\ImagePreview;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Support\Locales;
use App\Support\SiteSettings;
use App\Support\WebApp;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → General: site name, logos, favicon and default language. */
class GeneralSettings extends SettingsPage
{
    protected const FIELDS = [
        'name' => [SiteSettings::NAME, 'Site name'],
        'show_name' => [SiteSettings::SHOW_NAME, 'Show site name'],
        'locale' => [SiteSettings::LOCALE, 'Default language'],
        'logo' => [SiteSettings::LOGO, 'Logo'],
        'logo_dark' => [SiteSettings::LOGO_DARK, 'Dark mode logo'],
        'favicon' => [SiteSettings::FAVICON, 'Favicon'],
    ];

    protected const FILE_FIELDS = ['logo', 'logo_dark', 'favicon'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'general';

    public static function getNavigationLabel(): string
    {
        return __('General');
    }

    protected function formValues(): array
    {
        return [
            'name' => SiteSettings::name(),
            'show_name' => SiteSettings::showName(),
            'locale' => Locales::default(),
            'logo' => SiteSettings::assetPath('logo'),
            'logo_dark' => SiteSettings::assetPath('logo-dark'),
            'favicon' => SiteSettings::assetPath('favicon'),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make(__('Identity'))
                ->description(__('How the site presents itself in the header, browser tab and e-mails.'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Site name'))
                        ->required()
                        ->maxLength(80),
                    Toggle::make('show_name')
                        ->label(__('Show site name next to the logo'))
                        ->helperText(__('Turn off if your logo already contains the name.'))
                        ->inline(false),
                    self::brandingImage('logo', __('Logo'))
                        ->helperText(__('Shown about 28 px high. Leave empty to use the built-in mark.')),
                    self::brandingImage('logo_dark', __('Logo for dark mode'))
                        ->previewAs(ImagePreview::DarkLogo)
                        ->helperText(__('Optional. Used when the site is shown in dark mode.')),
                    self::brandingImage('favicon', __('Favicon'))
                        ->previewAs(ImagePreview::Icon)
                        ->previewChip(':name', 'name', fn (): string => SiteSettings::name())
                        ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'])
                        ->maxSize(256)
                        ->helperText(__('Square PNG or SVG, at least 64 × 64 px.')),
                ]),

            Section::make(__('Language'))
                ->schema([
                    Select::make('locale')
                        ->label(__('Default language'))
                        ->options(Locales::available())
                        ->selectablePlaceholder(false)
                        ->required()
                        ->in(array_keys(Locales::available()))
                        ->maxWidth('sm')
                        ->helperText(__('Used for visitors and for everyone who has not chosen a language in their profile. E-mails go out in the recipient\'s language.')),
                ]),
        ];
    }

    /** The app icon may be made from the favicon (WebApp::iconSource()). */
    protected function fileReplaced(string $field): void
    {
        if ($field === 'favicon') {
            WebApp::forgetIcons();
        }
    }
}
