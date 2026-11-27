<?php

namespace App\Filament\Resources\Photos\Tables;

use App\Filament\Tables\Filters\DateRangeFilter;
use App\Support\Licensing;
use App\Support\SiteSettings;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Media library filters besides album, uploader and upload date (which the
 * media picker shares, see PhotosTable), to narrow the library down before
 * a bulk action: tags, license, date taken, file name, shape and how many
 * albums a photo is in. All of them use indexed columns or joins, no EXIF.
 */
class PhotoFilters
{
    /** @return list<BaseFilter> */
    public static function all(): array
    {
        return [
            SelectFilter::make('tags')
                ->label(__('Tags'))
                ->relationship('tags', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->visible(fn (): bool => SiteSettings::taggingEnabled()),
            TernaryFilter::make('tagged')
                ->label(__('Tagged'))
                ->placeholder(__('All photos'))
                ->trueLabel(__('With tags'))
                ->falseLabel(__('Without tags'))
                ->queries(
                    true: fn (Builder $query) => $query->has('tags'),
                    false: fn (Builder $query) => $query->doesntHave('tags'),
                )
                ->visible(fn (): bool => SiteSettings::taggingEnabled()),
            SelectFilter::make('license')
                ->label(__('License'))
                ->relationship('license', 'name')
                ->preload()
                ->visible(fn (): bool => Licensing::enabled()),
            DateRangeFilter::make('taken')
                ->label(__('Taken'))
                ->column('photos.taken_at'),
            SelectFilter::make('orientation')
                ->label(__('Shape'))
                ->options([
                    'landscape' => __('Landscape'),
                    'portrait' => __('Portrait'),
                    'square' => __('Square'),
                ])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'landscape' => $query->whereColumn('photos.width', '>', 'photos.height'),
                    'portrait' => $query->whereColumn('photos.width', '<', 'photos.height'),
                    'square' => $query->whereColumn('photos.width', '=', 'photos.height')->where('photos.width', '>', 0),
                    default => $query,
                }),
            SelectFilter::make('album_count')
                ->label(__('Number of albums'))
                ->options([
                    'one' => __('In one album'),
                    'several' => __('In several albums'),
                ])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'one' => $query->has('albums', '=', 1),
                    'several' => $query->has('albums', '>', 1),
                    default => $query,
                }),
            Filter::make('file_name')
                ->schema([
                    TextInput::make('file_name')->label(__('File name contains')),
                ])
                ->query(fn (Builder $query, array $data) => $query->when(
                    filled($data['file_name'] ?? null),
                    fn (Builder $query) => $query->whereHas('media', fn (Builder $query) => self::whereContains($query, 'file_name', trim($data['file_name']))),
                ))
                ->indicateUsing(fn (array $data): ?string => filled($data['file_name'] ?? null)
                    ? __('File name contains ":text"', ['text' => trim($data['file_name'])])
                    : null),
        ];
    }

    /**
     * Rows whose $column contains $text as typed: % and _ match only
     * themselves. The escape character is "!", which MySQL, MariaDB and
     * SQLite all accept.
     *
     * @param  Builder<Model>  $query
     */
    public static function whereContains(Builder $query, string $column, string $text, string $boolean = 'and'): Builder
    {
        $pattern = '%'.Str::replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';

        return $query->whereRaw($query->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern], $boolean);
    }
}
