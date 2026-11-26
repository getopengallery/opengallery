<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\PhotoOrder;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Support\PhotoUploads;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → Photos: the default photo order, uploads, downloads and what the lightbox offers. */
class PhotoSettings extends SettingsPage
{
    protected const FIELDS = [
        'default_photo_order' => [SiteSettings::DEFAULT_PHOTO_ORDER, 'Default photo order'],
        'upload_max_mb' => [SiteSettings::UPLOAD_MAX_MB, 'Largest photo'],
        'upload_types' => [SiteSettings::UPLOAD_TYPES, 'Accepted file types'],
        'downloads_enabled' => [SiteSettings::DOWNLOADS_ENABLED, 'Albums can allow downloads'],
        'remove_location' => [SiteSettings::REMOVE_PHOTO_LOCATION, 'Remove location from downloads'],
        'favorites_enabled' => [SiteSettings::FAVORITES_ENABLED, 'Users can favorite photos'],
        'photo_info_enabled' => [SiteSettings::PHOTO_INFO_ENABLED, 'Show photo information'],
        'slideshow_enabled' => [SiteSettings::SLIDESHOW_ENABLED, 'Slideshow'],
        'slideshow_seconds' => [SiteSettings::SLIDESHOW_SECONDS, 'Seconds per photo'],
    ];

    protected const INTEGER_FIELDS = ['upload_max_mb', 'slideshow_seconds'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'photos';

    public static function getNavigationLabel(): string
    {
        return __('Photos');
    }

    protected function formValues(): array
    {
        return [
            'default_photo_order' => SiteSettings::defaultPhotoOrder()->value,
            'upload_max_mb' => SiteSettings::maxUploadMb(),
            'upload_types' => $this->processableOnly(SiteSettings::uploadTypes()),
            'downloads_enabled' => SiteSettings::downloadsEnabled(),
            'remove_location' => SiteSettings::removesPhotoLocation(),
            'favorites_enabled' => SiteSettings::favoritesEnabled(),
            'photo_info_enabled' => SiteSettings::photoInfoEnabled(),
            'slideshow_enabled' => SiteSettings::slideshowEnabled(),
            'slideshow_seconds' => SiteSettings::slideshowSeconds(),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make(__('Albums'))
                ->schema([
                    Select::make('default_photo_order')
                        ->label(__('Sort photos in new albums by'))
                        ->options(PhotoOrder::class)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->helperText(__('Preselected when an album is created; each album can still choose its own order. Existing albums keep theirs.')),
                ]),

            Section::make(__('Uploads'))
                ->schema([
                    TextInput::make('upload_max_mb')
                        ->label(__('Largest photo'))
                        ->integer()
                        ->minValue(SiteSettings::UPLOAD_MIN_MB)
                        ->maxValue(SiteSettings::UPLOAD_MAX_MB_LIMIT)
                        ->suffix('MB')
                        ->required()
                        ->maxWidth('xs')
                        ->helperText(__('Photos are uploaded in small pieces, so your host\'s upload limit does not apply. Very large images still need enough server memory to make thumbnails.')),
                    CheckboxList::make('upload_types')
                        ->label(__('Accepted file types'))
                        ->options(PhotoUploads::TYPES)
                        ->descriptions(fn () => array_map(
                            fn () => __('Not supported by this server\'s image library'),
                            array_diff_key(PhotoUploads::TYPES, array_flip(PhotoUploads::processableTypes())),
                        ))
                        ->disableOptionWhen(fn (string $value) => ! in_array($value, PhotoUploads::processableTypes(), true))
                        ->columns(3)
                        ->required(),
                ]),

            Section::make(__('Downloads'))
                ->schema([
                    Toggle::make('downloads_enabled')
                        ->label(__('Albums can allow downloads'))
                        ->live()
                        ->helperText(__('When on, each album\'s settings decide whether visitors may download its photos. When off, nobody can download photos or albums on the site.')),
                    Toggle::make('remove_location')
                        ->label(__('Remove location from downloads'))
                        ->visible(fn ($get) => (bool) $get('downloads_enabled'))
                        ->helperText(__('Visitors get original photos without GPS data. The stored files do not change, and people who may edit a photo still get its position.')),
                ]),

            Section::make(__('Favorites'))
                ->schema([
                    Toggle::make('favorites_enabled')
                        ->label(__('Users can favorite photos'))
                        ->helperText(__('Logged-in people get a heart in the lightbox. Photos they like are collected in their own private Favorites album.')),
                ]),

            Section::make(__('Photo information'))
                ->schema([
                    Toggle::make('photo_info_enabled')
                        ->label(__('Show photo information'))
                        ->helperText(__('An "i" button in the lightbox shows the photo\'s description and camera details. Location data is never shown there.')),
                ]),

            Section::make(__('Slideshow'))
                ->schema([
                    Toggle::make('slideshow_enabled')
                        ->label(__('Users can play a slideshow'))
                        ->live()
                        ->helperText(__('A play button in the lightbox shows the photos one after another.')),
                    TextInput::make('slideshow_seconds')
                        ->label(__('Seconds per photo'))
                        ->integer()
                        ->minValue(SiteSettings::SLIDESHOW_SECONDS_MIN)
                        ->maxValue(SiteSettings::SLIDESHOW_SECONDS_MAX)
                        ->suffix(__('s'))
                        ->required()
                        ->maxWidth('xs')
                        ->visible(fn ($get) => (bool) $get('slideshow_enabled')),
                ]),
        ];
    }

    protected function prepareValue(string $field, mixed $value): mixed
    {
        return $field === 'upload_types' ? $this->processableOnly((array) $value) : $value;
    }

    /**
     * @param  array<int, string>  $types
     * @return list<string> the given types this server can process, in the usual order
     */
    private function processableOnly(array $types): array
    {
        return array_values(array_intersect(array_keys(PhotoUploads::TYPES), PhotoUploads::processableTypes(), $types));
    }
}
