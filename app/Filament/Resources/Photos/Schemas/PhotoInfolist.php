<?php

namespace App\Filament\Resources\Photos\Schemas;

use App\Models\Album;
use App\Models\Photo;
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
                    ->schema([
                        ImageEntry::make('preview')
                            ->hiddenLabel()
                            ->state(fn (Photo $record) => $record->mediaUrl('preview'))
                            ->imageHeight('auto')
                            ->extraImgAttributes(['class' => 'og-preview-image og-preview-image-contained'])
                            ->placeholder(__('No preview available')),
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->placeholder(__('No description'))
                            ->prose(),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make(__('Where it is'))
                            ->schema([
                                TextEntry::make('albums')
                                    ->label(fn (Photo $record) => $record->albums->count() === 1 ? __('Album') : __('Albums'))
                                    ->state(fn (Photo $record) => $record->albums
                                        ->map(fn (Album $album) => $album->trashed() ? __(':album (in the trash)', ['album' => $album->path()]) : $album->path())
                                        ->all() ?: null)
                                    ->listWithLineBreaks()
                                    ->bulleted(fn (Photo $record) => $record->albums->count() > 1)
                                    ->placeholder(__('In no album')),
                                TextEntry::make('cover_of')
                                    ->label(__('Cover of'))
                                    ->state(fn (Photo $record) => $record->coverOf()->pluck('title')->all() ?: null)
                                    ->badge()
                                    ->placeholder(__('Not used as a cover')),
                                TextEntry::make('deleted_at')
                                    ->label(__('In the trash since'))
                                    ->dateTime()
                                    ->visible(fn (Photo $record) => $record->trashed()),
                            ]),

                        Section::make(__('History'))
                            ->schema([
                                TextEntry::make('uploader.name')->label(__('Uploaded by'))->placeholder(__('Unknown')),
                                TextEntry::make('created_at')->label(__('Uploaded'))->dateTime(),
                                TextEntry::make('taken_at')->label(__('Taken'))->dateTime()->placeholder(__('Unknown')),
                                TextEntry::make('updated_at')->label(__('Last changed'))->dateTime(),
                            ]),

                        Section::make(__('File'))
                            ->schema([
                                TextEntry::make('file_name')->label(__('Name'))->state(fn (Photo $record) => $record->original()?->file_name)->copyable(),
                                TextEntry::make('mime')->label(__('Type'))->state(fn (Photo $record) => $record->original()?->mime_type),
                                TextEntry::make('size')->label(__('Size'))->state(fn (Photo $record) => $record->original() ? Number::fileSize($record->original()->size, precision: 1) : null),
                                TextEntry::make('dimensions')->label(__('Dimensions'))->state(fn (Photo $record) => $record->width && $record->height ? "{$record->width} × {$record->height} px" : null)->placeholder(__('Unknown')),
                            ]),

                        Section::make(__('Camera'))
                            ->visible(fn (Photo $record) => array_intersect_key((array) $record->exif, self::EXIF) !== [])
                            ->collapsible()
                            ->schema(collect(self::EXIF)->map(fn (string $label, string $key) => TextEntry::make("exif.{$key}")
                                ->label(__($label))
                                ->state(fn (Photo $record) => self::exifValue($key, $record->exif[$key] ?? null))
                                ->visible(fn (Photo $record) => filled($record->exif[$key] ?? null)))
                                ->values()
                                ->all()),
                    ]),
            ]);
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
