<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\LightboxFooter;
use App\Enums\PhotoOrder;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Filament\Navigation\NavigationGroup;
use App\Models\Setting;
use App\Support\PhotoCompression;
use App\Support\PhotoUploads;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → Photos: the default photo order, uploads, compression, downloads and what the lightbox offers and shows below the photo. */
class PhotoSettings extends SettingsPage
{
    protected const FIELDS = [
        'default_photo_order' => [SiteSettings::DEFAULT_PHOTO_ORDER, 'Default photo order'],
        'upload_max_mb' => [SiteSettings::UPLOAD_MAX_MB, 'Largest photo'],
        'upload_types' => [SiteSettings::UPLOAD_TYPES, 'Accepted file types'],
        'compression_enabled' => [SiteSettings::COMPRESSION_ENABLED, 'Compress uploaded photos'],
        'compression_resize' => [SiteSettings::COMPRESSION_RESIZE, 'Limit the resolution'],
        'compression_max_px' => [SiteSettings::COMPRESSION_MAX_PX, 'Longest side'],
        'compression_reencode' => [SiteSettings::COMPRESSION_REENCODE, 'Lower the quality'],
        'compression_quality' => [SiteSettings::COMPRESSION_QUALITY, 'Quality'],
        'downloads_enabled' => [SiteSettings::DOWNLOADS_ENABLED, 'Albums can allow downloads'],
        'remove_location' => [SiteSettings::REMOVE_PHOTO_LOCATION, 'Remove location from downloads'],
        'favorites_enabled' => [SiteSettings::FAVORITES_ENABLED, 'Users can favorite photos'],
        'photo_info_enabled' => [SiteSettings::PHOTO_INFO_ENABLED, 'Show photo information'],
        'lightbox_footer' => [SiteSettings::LIGHTBOX_FOOTER, 'Below the photo'],
        'slideshow_enabled' => [SiteSettings::SLIDESHOW_ENABLED, 'Slideshow'],
        'slideshow_seconds' => [SiteSettings::SLIDESHOW_SECONDS, 'Seconds per photo'],
    ];

    protected const INTEGER_FIELDS = ['upload_max_mb', 'compression_max_px', 'compression_quality', 'slideshow_seconds'];

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
            'compression_enabled' => SiteSettings::compressionEnabled(),
            'compression_resize' => (bool) Setting::get(SiteSettings::COMPRESSION_RESIZE, true),
            'compression_max_px' => (int) Setting::get(SiteSettings::COMPRESSION_MAX_PX, SiteSettings::COMPRESSION_MAX_PX_DEFAULT),
            'compression_reencode' => (bool) Setting::get(SiteSettings::COMPRESSION_REENCODE, true),
            'compression_quality' => (int) Setting::get(SiteSettings::COMPRESSION_QUALITY, SiteSettings::COMPRESSION_QUALITY_DEFAULT),
            'downloads_enabled' => SiteSettings::downloadsEnabled(),
            'remove_location' => SiteSettings::removesPhotoLocation(),
            'favorites_enabled' => SiteSettings::favoritesEnabled(),
            'photo_info_enabled' => SiteSettings::photoInfoEnabled(),
            'lightbox_footer' => SiteSettings::lightboxFooter()->value,
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

            Section::make(__('Compression'))
                ->description(__('Makes photo files smaller to save storage. The smaller file replaces the original for good; camera, date and location details stay in it. GIF, HEIC and AVIF files are not changed.'))
                ->schema([
                    Toggle::make('compression_enabled')
                        ->label(__('Compress uploaded photos'))
                        ->live(),
                    Toggle::make('compression_resize')
                        ->label(__('Limit the resolution'))
                        ->helperText(__('Larger photos are scaled down; smaller ones stay as they are.'))
                        ->live()
                        ->visible(fn ($get) => (bool) $get('compression_enabled')),
                    TextInput::make('compression_max_px')
                        ->label(__('Longest side'))
                        ->integer()
                        ->minValue(SiteSettings::COMPRESSION_MAX_PX_MIN)
                        ->maxValue(SiteSettings::COMPRESSION_MAX_PX_MAX)
                        ->suffix(__('px'))
                        ->required()
                        ->maxWidth('xs')
                        ->visible(fn ($get) => $get('compression_enabled') && $get('compression_resize')),
                    Toggle::make('compression_reencode')
                        ->label(__('Lower the quality'))
                        ->helperText(__('JPEG and WebP photos are saved again at this quality. PNG files have no quality setting and are only scaled down.'))
                        ->live()
                        ->visible(fn ($get) => (bool) $get('compression_enabled')),
                    TextInput::make('compression_quality')
                        ->label(__('Quality'))
                        ->integer()
                        ->minValue(SiteSettings::COMPRESSION_QUALITY_MIN)
                        ->maxValue(SiteSettings::COMPRESSION_QUALITY_MAX)
                        ->suffix('%')
                        ->required()
                        ->maxWidth('xs')
                        ->visible(fn ($get) => $get('compression_enabled') && $get('compression_reencode')),
                    Text::make(fn () => $this->existingCompressionText())
                        ->color('gray')
                        ->visible(fn () => PhotoCompression::isActive()),
                    Actions::make([
                        Action::make('compressExisting')
                            ->label(__('Compress existing photos'))
                            ->icon(Heroicon::OutlinedArrowsPointingIn)
                            ->color('gray')
                            ->requiresConfirmation()
                            ->modalDescription(__('All photos already stored are compressed with the saved settings, a few at a time in the background jobs. The originals are replaced and cannot be restored.'))
                            ->action(fn () => $this->compressExisting()),
                    ])->key('compressionActions')->visible(fn () => PhotoCompression::isActive()),
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
                    Select::make('lightbox_footer')
                        ->label(__('Below the photo'))
                        ->options(LightboxFooter::class)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->helperText(__('What the lightbox shows under each photo. Camera details need photo information to be on, and the license needs licenses to be on. Nothing is shown when a photo has no such detail.')),
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

    public function compressExisting(): void
    {
        abort_unless(static::canAccess() && PhotoCompression::isActive(), 403);

        PhotoCompression::startExisting();

        Notification::make()
            ->title(__('Existing photos are being compressed'))
            ->body(__('This runs in the background jobs and may take a while for a large library.'))
            ->success()
            ->send();
    }

    /** What happens to the photos stored before; only the saved settings count. */
    private function existingCompressionText(): string
    {
        $remaining = PhotoCompression::remaining();

        if ($remaining !== null) {
            return trans_choice('{0} Existing photos are almost done.|{1} Existing photos: 1 photo still to check.|[2,*] Existing photos: :count photos still to check.', $remaining);
        }

        return __('New uploads are compressed with the saved settings. Photos stored before stay as they are until you compress them too.');
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
