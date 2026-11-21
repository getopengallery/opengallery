<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\PhotoOrder;
use App\Filament\Clusters\Settings\SettingsGroup;
use App\Filament\Clusters\Settings\SettingsPage;
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

/** Settings → Photos: the default photo order, what can be uploaded and location data in downloads. */
class PhotoSettings extends SettingsPage
{
    protected const FIELDS = [
        'default_photo_order' => [SiteSettings::DEFAULT_PHOTO_ORDER, 'Default photo order'],
        'upload_max_mb' => [SiteSettings::UPLOAD_MAX_MB, 'Largest photo'],
        'upload_types' => [SiteSettings::UPLOAD_TYPES, 'Accepted file types'],
        'remove_location' => [SiteSettings::REMOVE_PHOTO_LOCATION, 'Remove location from downloads'],
    ];

    protected const INTEGER_FIELDS = ['upload_max_mb'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = SettingsGroup::Content;

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
            'remove_location' => SiteSettings::removesPhotoLocation(),
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
                    Toggle::make('remove_location')
                        ->label(__('Remove location from downloads'))
                        ->helperText(__('Visitors get original photos without GPS data. The stored files do not change, and people who may edit a photo still get its position.')),
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
