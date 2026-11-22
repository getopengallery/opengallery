<?php

namespace App\Filament\Resources\Photos\Schemas;

use App\Filament\Forms\Components\TagPicker;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Models\Album;
use App\Models\Photo;
use App\Support\AlbumCollaboration;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PhotoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('title')
                            ->maxLength(255),
                        Textarea::make('description')
                            ->rows(4),
                        DateTimePicker::make('taken_at')
                            ->label(__('Taken'))
                            ->helperText(__('From the camera when available; correct it here if the camera clock was wrong.')),
                        Select::make('albums')
                            ->label(__('Albums'))
                            ->multiple()
                            ->relationship('albums', 'title')
                            ->options(fn (?Photo $record) => self::albumOptions($record))
                            ->getOptionLabelFromRecordUsing(fn (Album $album) => $album->path())
                            ->searchable()
                            ->required()
                            ->rule(fn (?Photo $record) => self::albumChangesAllowed($record))
                            ->helperText(__('The photo appears in every album listed here and can be seen by anyone who can see one of them.')),
                        TagPicker::make('tag_names'),
                    ]),
                Section::make()
                    ->columnSpan(1)
                    ->schema([
                        ImageEntry::make('preview')
                            ->hiddenLabel()
                            ->state(fn (?Photo $record) => $record?->mediaUrl('thumb'))
                            ->imageHeight('auto')
                            ->extraImgAttributes(['class' => 'og-preview-image']),
                    ]),
            ]);
    }

    /**
     * Albums the user may add to, plus the photo's current albums (shown even
     * without rights, so they are not silently dropped).
     *
     * @return array<int, string>
     */
    private static function albumOptions(?Photo $record): array
    {
        $options = PhotosTable::manageableAlbums('upload');

        foreach ($record?->albums ?? [] as $album) {
            $options[$album->id] ??= $album->path();
        }

        return $options;
    }

    /** Added albums must allow uploading; removed albums must be ones the user manages. */
    private static function albumChangesAllowed(?Photo $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record) {
            $user = auth()->user();
            $selected = array_map('intval', (array) $value);
            $current = $record?->albums->modelKeys() ?? [];

            foreach (Album::withTrashed()->whereKey(array_diff($selected, $current))->get() as $album) {
                // With only a collaborator role, own uploads only: adding a photo publishes it there.
                if (! $user->can('upload', $album) || ($record && ! AlbumCollaboration::mayAddPhoto($album, $record, $user))) {
                    $fail(__('You cannot add photos to ":album".', ['album' => $album->title]));
                }
            }

            foreach (Album::withTrashed()->whereKey(array_diff($current, $selected))->get() as $album) {
                if (! $user->can('update', $album)) {
                    $fail(__('You cannot remove photos from ":album".', ['album' => $album->title]));
                }
            }
        };
    }
}
