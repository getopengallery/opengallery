<?php

namespace App\Filament\Resources\Photos\Tables;

use App\Filament\Tables\Filters\DateRangeFilter;
use App\Filament\Tables\Filters\TextFilter;
use App\Support\Licensing;
use App\Support\SiteSettings;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Media library filters besides album, uploader and upload date (which the
 * media picker shares, see PhotosTable), to narrow the library down before
 * a bulk action: tags, license, date taken, shape, resolution, file type,
 * missing details, how many albums a photo is in, your favorites and file
 * name. All of them use columns or joins, no EXIF.
 */
class PhotoFilters
{
    /** @return list<BaseFilter> */
    public static function all(): array
    {
        return [
            SelectFilter::make('tags')
                ->label(__('Tags'))
                ->relationship('tags', 'name', hasEmptyOption: true)
                ->emptyRelationshipOptionLabel(__('Without tags'))
                ->multiple()
                ->searchable()
                ->preload()
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
            SelectFilter::make('resolution')
                ->label(__('Resolution'))
                ->options([
                    'small' => __('Under :count MP', ['count' => 2]),
                    'medium' => __(':from–:until MP', ['from' => 2, 'until' => 12]),
                    'large' => __('Over :count MP', ['count' => 12]),
                ])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'small' => $query->whereRaw('photos.width * photos.height < ?', [2_000_000]),
                    'medium' => $query->whereRaw('photos.width * photos.height between ? and ?', [2_000_000, 12_000_000]),
                    'large' => $query->whereRaw('photos.width * photos.height > ?', [12_000_000]),
                    default => $query,
                }),
            SelectFilter::make('file_type')
                ->label(__('File type'))
                ->options(fn (): array => collect(config('gallery.accepted_mime_types'))
                    ->mapWithKeys(fn (string $mime): array => [$mime => Str::upper(Str::after($mime, '/'))])
                    ->all())
                ->multiple()
                ->query(fn (Builder $query, array $data) => $query->when(
                    filled($data['values'] ?? null),
                    fn (Builder $query) => $query->whereHas('media', fn (Builder $query) => $query->whereIn('mime_type', $data['values'])),
                )),
            SelectFilter::make('missing')
                ->label(__('Missing'))
                ->options(fn (): array => array_filter([
                    'title' => __('Title'),
                    'description' => __('Description'),
                    'taken_at' => __('Date taken'),
                    'license' => Licensing::enabled() ? __('License') : null,
                ]))
                ->multiple()
                ->query(fn (Builder $query, array $data) => $query->when(
                    filled($data['values'] ?? null),
                    fn (Builder $query) => $query->where(fn (Builder $query) => self::whereMissing($query, $data['values'])),
                )),
            SelectFilter::make('album_count')
                ->label(__('Number of albums'))
                ->options([
                    'none' => __('In no album'),
                    'one' => __('In one album'),
                    'several' => __('In several albums'),
                ])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'none' => $query->doesntHave('albums'),
                    'one' => $query->has('albums', '=', 1),
                    'several' => $query->has('albums', '>', 1),
                    default => $query,
                }),
            Filter::make('favorites')
                ->label(__('My favorites'))
                ->toggle()
                ->query(fn (Builder $query) => $query->whereIn('photos.id', DB::table('photo_favorites')
                    ->where('user_id', auth()->id())
                    ->select('photo_id'))),
            TextFilter::make('file_name')
                ->label(__('File name'))
                ->matchUsing(fn (Builder $query, string $text) => $query->whereHas('media', fn (Builder $query) => self::whereContains($query, 'file_name', $text))),
        ];
    }

    /**
     * Photos that lack any of the given details ("Missing: title or description").
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $details
     */
    private static function whereMissing(Builder $query, array $details): void
    {
        foreach ($details as $detail) {
            match ($detail) {
                'title', 'description' => $query->orWhere(fn (Builder $query) => $query->whereNull('photos.'.$detail)->orWhere('photos.'.$detail, '')),
                'taken_at' => $query->orWhereNull('photos.taken_at'),
                'license' => $query->orWhereNull('photos.license_id'),
                default => null,
            };
        }
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
