<?php

namespace App\Filament\Resources\Photos\Schemas;

use App\Filament\Forms\Components\TagPicker;
use App\Filament\Resources\Photos\Tables\PhotosTable;
use App\Models\Album;
use App\Models\Photo;
use App\Support\AlbumCollaboration;
use App\Support\Licensing;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PhotoForm
{
    /**
     * Same layout as the photo page (PhotoInfolist): preview with where it is
     * and the history, then file and camera. Here title, description, albums,
     * tags and the taken date can be changed; the rest is shown as there.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columnSpan(2)
                    ->extraAttributes(['class' => 'og-fill'])
                    ->schema([
                        PhotoInfolist::previewImage(),
                        TextInput::make('title')
                            ->maxLength(255),
                        Textarea::make('description')
                            ->rows(4),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->extraAttributes(['class' => 'og-fill'])
                    ->schema([
                        Section::make(__('Where it is'))
                            ->extraAttributes(['class' => 'og-fill'])
                            ->schema([
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
                                PhotoInfolist::coverOfEntry(),
                                TagPicker::make('tag_names'),
                                self::licenseField(),
                            ]),

                        Section::make(__('History'))
                            ->extraAttributes(['class' => 'og-fill'])
                            ->schema([
                                PhotoInfolist::uploaderEntry(),
                                PhotoInfolist::uploadedEntry(),
                                DateTimePicker::make('taken_at')
                                    ->label(__('Taken'))
                                    ->helperText(__('From the camera when available; correct it here if the camera clock was wrong.')),
                                PhotoInfolist::changedEntry(),
                            ]),
                    ]),

                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        PhotoInfolist::fileSection(),
                        PhotoInfolist::cameraSection(),
                    ]),
            ]);
    }

    /**
     * The photo's license: offered from the licenses the user may pick, shown
     * read-only while they may not choose. Hidden while licensing is off.
     */
    public static function licenseField(): Select
    {
        return Select::make('license_id')
            ->label(__('License'))
            ->options(fn (?Photo $record): array => Licensing::optionsFor(auth()->user(), $record))
            ->placeholder(__('No license given'))
            ->live()
            ->helperText(fn (mixed $state): ?HtmlString => Licensing::describe($state))
            ->disabled(fn (): bool => ! Licensing::canChoose(auth()->user()))
            ->rule(fn (?Photo $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                if (! Licensing::allows(auth()->user(), filled($value) ? (int) $value : null, $record)) {
                    $fail(__('This license cannot be chosen.'));
                }
            })
            ->visible(fn (): bool => Licensing::enabled());
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
