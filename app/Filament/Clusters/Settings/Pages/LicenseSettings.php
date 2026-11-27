<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Models\License;
use App\Support\Licensing;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Settings → Licenses: licenses on or off, the license new photos get, and
 * whether (and from which licenses) uploaders may choose another one. The
 * licenses themselves are edited in LicenseResource.
 */
class LicenseSettings extends SettingsPage
{
    protected const FIELDS = [
        'licensing_enabled' => [SiteSettings::LICENSING, 'Licenses'],
        'default_license' => [SiteSettings::LICENSE_DEFAULT, 'Default license'],
        'allow_license_choice' => [SiteSettings::LICENSES_ALLOW_CHOICE, 'Uploaders choose the license'],
        'choosable_licenses' => [SiteSettings::LICENSES_CHOOSABLE, 'Licenses to choose from'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 57;

    protected static ?string $slug = 'licenses';

    public static function getNavigationLabel(): string
    {
        return __('Licenses');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editLicenses')
                ->label(__('Edit licenses'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->url(LicenseResource::getUrl('index')),
        ];
    }

    protected function formValues(): array
    {
        return [
            'licensing_enabled' => SiteSettings::licensingEnabled(),
            'default_license' => SiteSettings::defaultLicenseId(),
            'allow_license_choice' => SiteSettings::allowsLicenseChoice(),
            'choosable_licenses' => SiteSettings::choosableLicenseIds()
                ?? License::query()->active()->ordered()->pluck('id')->all(),
        ];
    }

    protected function formSections(): array
    {
        $licensingOn = fn (Get $get): bool => (bool) $get('licensing_enabled');

        return [
            Section::make(__('Licenses'))
                ->schema([
                    Toggle::make('licensing_enabled')
                        ->label(__('Use licenses'))
                        ->helperText(__('Photos carry a license, shown in the photo info on the site. When off, licenses are hidden, but photos keep theirs.'))
                        ->live(),
                    Select::make('default_license')
                        ->label(__('Default license'))
                        ->helperText(fn (mixed $state): HtmlString => new HtmlString(collect([
                            e(__('New photos get this license. Photos uploaded before keep theirs.')),
                            Licensing::describe($state)?->toHtml(),
                        ])->filter()->implode('<br>')))
                        ->options(fn (): array => self::licenseOptions())
                        ->live()
                        ->required($licensingOn)
                        ->in(fn (): array => array_keys(self::licenseOptions()))
                        ->visible($licensingOn),
                ]),

            Section::make(__('Choosing a license'))
                ->visible($licensingOn)
                ->schema([
                    Toggle::make('allow_license_choice')
                        ->label(__('Uploaders choose the license'))
                        ->helperText(__('People who can edit a photo can give it another license. When off, new photos always get the default license; people who manage settings can still change it.'))
                        ->live(),
                    CheckboxList::make('choosable_licenses')
                        ->label(__('Licenses to choose from'))
                        ->helperText(__('The default license can always be chosen.'))
                        ->options(fn (): array => self::licenseOptions())
                        ->descriptions(fn (): array => License::query()->active()->ordered()->get()
                            ->mapWithKeys(fn (License $license): array => [$license->getKey() => $license->displayDescription() ?? $license->displayTitle() ?? ''])
                            ->all())
                        ->columns(2)
                        ->visible(fn (Get $get): bool => (bool) $get('allow_license_choice')),
                ]),
        ];
    }

    protected function prepareValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'default_license' => filled($value) ? (int) $value : null,
            'choosable_licenses' => array_values(array_map('intval', (array) $value)),
            default => $value,
        };
    }

    /** @return array<int, string> active licenses by id */
    private static function licenseOptions(): array
    {
        return License::query()->active()->ordered()->get()
            ->mapWithKeys(fn (License $license): array => [$license->getKey() => $license->displayName()])
            ->all();
    }
}
