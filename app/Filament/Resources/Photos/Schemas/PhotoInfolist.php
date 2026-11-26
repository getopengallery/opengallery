<?php

namespace App\Filament\Resources\Photos\Schemas;

use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Album;
use App\Models\Photo;
use App\Support\SiteSettings;
use App\Support\Tagging;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class PhotoInfolist
{
    /** EXIF keys shown under "Camera", with readable labels (English, translated when shown). */
    private const EXIF = [
        'Make' => 'Make',
        'Model' => 'Camera',
        'LensModel' => 'Lens',
        'FocalLength' => 'Focal length',
        'FNumber' => 'Aperture',
        'ExposureTime' => 'Exposure',
        'ISOSpeedRatings' => 'ISO',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columnSpan(2)
                    ->extraAttributes(['class' => 'og-fill'])
                    ->schema([
                        self::previewImage(),
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->placeholder(__('No description'))
                            ->prose(),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->extraAttributes(['class' => 'og-fill'])
                    ->schema([
                        Section::make(__('Where it is'))
                            ->extraAttributes(['class' => 'og-fill'])
                            ->schema([
                                TextEntry::make('albums')
                                    ->label(fn (Photo $record) => $record->albums->count() === 1 ? __('Album') : __('Albums'))
                                    // Album ids: each line is worded and linked on its own (formatStateUsing / url).
                                    ->state(fn (Photo $record) => $record->albums->modelKeys() ?: null)
                                    ->formatStateUsing(fn (Photo $record, mixed $state): string => self::albumLabel($record->albums->find($state)))
                                    ->url(fn (Photo $record, mixed $state): ?string => self::albumUrl($record->albums->find($state)))
                                    ->listWithLineBreaks()
                                    ->bulleted(fn (Photo $record) => $record->albums->count() > 1)
                                    ->placeholder(__('In no album')),
                                self::coverOfEntry(),
                                TextEntry::make('tag_names')
                                    ->label(__('Tags'))
                                    ->state(fn (Photo $record) => Tagging::uniqueNames([
                                        ...Tagging::namesOf($record),
                                        ...Tagging::inheritedNames($record, auth()->user()),
                                    ]) ?: null)
                                    ->badge()
                                    ->color('gray')
                                    ->placeholder(__('No tags'))
                                    ->visible(fn (): bool => SiteSettings::taggingEnabled()),
                                TextEntry::make('deleted_at')
                                    ->label(__('In the trash since'))
                                    ->dateTime()
                                    ->visible(fn (Photo $record) => $record->trashed()),
                            ]),

                        Section::make(__('History'))
                            ->extraAttributes(['class' => 'og-fill'])
                            ->schema([
                                self::uploaderEntry(),
                                self::uploadedEntry(),
                                TextEntry::make('taken_at')->label(__('Taken'))->dateTime()->placeholder(__('Unknown')),
                                self::changedEntry(),
                            ]),
                    ]),

                // Second row: the file itself and the camera that made it.
                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        self::fileSection(),
                        self::cameraSection(),
                    ]),
            ]);
    }

    /** The large preview; the photo page and its edit form show it the same way. */
    public static function previewImage(): ImageEntry
    {
        return ImageEntry::make('preview')
            ->hiddenLabel()
            ->state(fn (Photo $record) => $record->mediaUrl('preview'))
            ->imageHeight('auto')
            ->extraImgAttributes(['class' => 'og-preview-image og-preview-image-contained'])
            ->placeholder(__('No preview available'));
    }

    public static function coverOfEntry(): TextEntry
    {
        return TextEntry::make('cover_of')
            ->label(__('Cover of'))
            ->state(fn (Photo $record) => $record->coverOf()->pluck('id')->all() ?: null)
            ->formatStateUsing(fn (mixed $state): string => (string) Album::find($state)?->title)
            ->url(fn (mixed $state): ?string => self::albumUrl(Album::find($state)))
            ->badge()
            ->placeholder(__('Not used as a cover'));
    }

    public static function uploaderEntry(): TextEntry
    {
        return TextEntry::make('uploader.name')
            ->label(__('Uploaded by'))
            ->placeholder(__('Unknown'))
            ->url(fn (Photo $record): ?string => $record->uploader && UserResource::canEdit($record->uploader)
                ? UserResource::getUrl('edit', ['record' => $record->uploader])
                : null);
    }

    public static function uploadedEntry(): TextEntry
    {
        return TextEntry::make('created_at')->label(__('Uploaded'))->dateTime();
    }

    public static function changedEntry(): TextEntry
    {
        return TextEntry::make('updated_at')->label(__('Last changed'))->dateTime();
    }

    public static function fileSection(): Section
    {
        return Section::make(__('File'))
            ->extraAttributes(['class' => 'og-fill'])
            ->columnSpan(fn (Photo $record): int => self::hasCameraData($record) ? 1 : 2)
            ->schema([
                TextEntry::make('file_name')->label(__('Name'))->state(fn (Photo $record) => $record->original()?->file_name)->copyable(),
                TextEntry::make('mime')->label(__('Type'))->state(fn (Photo $record) => $record->original()?->mime_type),
                TextEntry::make('size')->label(__('Size'))->state(fn (Photo $record) => $record->original() ? Number::fileSize($record->original()->size, precision: 1) : null),
                TextEntry::make('dimensions')->label(__('Dimensions'))->state(fn (Photo $record) => $record->width && $record->height ? "{$record->width} × {$record->height} px" : null)->placeholder(__('Unknown')),
            ]);
    }

    public static function cameraSection(): Section
    {
        return Section::make(__('Camera'))
            ->extraAttributes(['class' => 'og-fill'])
            ->visible(fn (Photo $record) => self::hasCameraData($record))
            ->columns(2)
            ->schema(collect(self::EXIF)->map(fn (string $label, string $key) => TextEntry::make("exif.{$key}")
                ->label(__($label))
                ->state(fn (Photo $record) => self::exifValue($key, $record->exif[$key] ?? null))
                ->visible(fn (Photo $record) => filled($record->exif[$key] ?? null)))
                ->values()
                ->all());
    }

    public static function hasCameraData(Photo $record): bool
    {
        return array_intersect_key((array) $record->exif, self::EXIF) !== [];
    }

    /** Album path, marked when the album is in the trash. */
    private static function albumLabel(?Album $album): string
    {
        return match (true) {
            $album === null => '',
            $album->trashed() => __(':album (in the trash)', ['album' => $album->path()]),
            default => $album->path(),
        };
    }

    /** The album's admin page, for people who may open it. */
    private static function albumUrl(?Album $album): ?string
    {
        return $album && AlbumResource::canEdit($album) ? AlbumResource::getUrl('edit', ['record' => $album]) : null;
    }

    /** EXIF stores fractions like "1/250" or "28/10"; show them the way photographers read them. */
    private static function exifValue(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return is_array($value) ? implode(', ', $value) : null;
        }

        $number = is_string($value) && preg_match('#^(\d+)/(\d+)$#', $value, $m) && (int) $m[2] !== 0
            ? (int) $m[1] / (int) $m[2]
            : (is_numeric($value) ? (float) $value : null);

        return match ($key) {
            'FNumber' => $number ? 'f/'.rtrim(rtrim(number_format($number, 1), '0'), '.') : (string) $value,
            'FocalLength' => $number ? round($number).' mm' : (string) $value,
            'ExposureTime' => $number ? ($number >= 1 ? rtrim(rtrim(number_format($number, 1), '0'), '.').' s' : '1/'.round(1 / $number).' s') : (string) $value,
            default => (string) $value,
        };
    }
}
