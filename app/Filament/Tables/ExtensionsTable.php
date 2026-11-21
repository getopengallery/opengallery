<?php

namespace App\Filament\Tables;

use App\Extensions\Manifest;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Plugins and Themes lists: tables built from the extensions' manifests
 * (plugin.json / theme.json) rather than from the database, so search and
 * sorting happen here. Rows are keyed by slug; each page adds its own columns
 * (status, colours) and actions.
 */
class ExtensionsTable
{
    /**
     * @param  Collection<string, Manifest>  $extensions
     * @param  callable(Manifest): array<string, mixed>  $extra  the page's own fields per row
     * @param  (callable(array<string, mixed>): bool)|null  $keep  the page's filters
     * @return array<string, array<string, mixed>> rows keyed by slug, sorted by name
     */
    public static function records(Collection $extensions, ?string $search, callable $extra, ?callable $keep = null): array
    {
        return $extensions
            ->map(fn (Manifest $extension): array => [
                'slug' => $extension->slug,
                'name' => $extension->label(),
                'description' => $extension->summary(),
                'version' => $extension->version,
                'author' => $extension->author,
                ...$extra($extension),
            ])
            ->filter(fn (array $row): bool => blank($search)
                || Str::contains(implode(' ', [$row['name'], $row['description'], $row['author']]), $search, ignoreCase: true))
            ->filter(fn (array $row): bool => $keep === null || $keep($row))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }

    /**
     * Name with the description below it, version and author. On phones only
     * the name and description are shown, so the text gets the whole width.
     *
     * @return list<TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('name')
                ->label(__('Name'))
                ->weight(FontWeight::Medium)
                ->description(fn (array $record): ?string => $record['description'])
                ->wrap(),
            TextColumn::make('version')
                ->label(__('Version'))
                ->visibleFrom('md'),
            TextColumn::make('author')
                ->label(__('Author'))
                ->placeholder('–')
                ->visibleFrom('md'),
        ];
    }
}
