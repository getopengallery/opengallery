<?php

namespace App\Filament\Tables;

use App\Models\Photo;
use Filament\Actions\Action;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

/**
 * The admin's one photo grid: compact click-to-select tiles
 * (filament.tables.media-tile) in justified rows like the site's albums:
 * every photo keeps its shape and the photos of a row share its height.
 * Used by the media library's grid layout, an album's Photos section and the
 * media picker. Selecting works through Filament's table selection, so bulk
 * actions work as usual; add selectionActions() to the toolbar.
 *
 *   MediaGrid::configure($table, ['coverId' => $album->cover_photo_id])
 */
class MediaGrid
{
    /**
     * Records carry their photo's shape as a class (og-r-30 = 1.50), since
     * Filament's record wrapper takes classes but no style. Steps of 0.05,
     * from 0.25 (tall) to 5 (panorama); the admin styles define each class.
     */
    public const RATIO_STEPS = 20;

    public const RATIO_MIN = 5;

    public const RATIO_MAX = 100;

    /**
     * @param  array<string, mixed>  $tile  view data for the tile: openable, single, coverId
     */
    public static function configure(Table $table, array $tile = [], int $perPage = 50): Table
    {
        return $table
            ->columns([
                Stack::make([
                    ViewColumn::make('pick')
                        ->view('filament.tables.media-tile')
                        ->viewData(['compact' => true, ...$tile]),
                    // The title is shown on the tile itself; still searchable.
                    TextColumn::make('title')
                        ->searchable()
                        ->extraAttributes(['class' => 'og-sr-only']),
                ]),
            ])
            // The grid markup is Filament's; the admin styles lay it out in justified rows (theme-styles).
            ->contentGrid(['default' => 2, 'sm' => 3, 'md' => 4, 'lg' => 5])
            ->recordClasses(fn (Photo $record): string => self::ratioClass($record))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption($perPage);
    }

    /** e.g. "og-r-30" for a 3:2 photo; the nearest step, within the range the styles define. */
    public static function ratioClass(Photo $photo): string
    {
        $step = (int) round($photo->aspectRatio() * self::RATIO_STEPS);

        return 'og-r-'.max(self::RATIO_MIN, min(self::RATIO_MAX, $step));
    }

    /**
     * One selection control: "Select all" (every photo on this page, or with
     * $acrossPages every photo matching the filters), then "3 selected ·
     * Select all · ×" once photos are selected. It replaces Filament's
     * selection row under the toolbar. Tiles have no checkboxes.
     *
     * @return list<Action>
     */
    public static function selectionActions(bool $acrossPages = false): array
    {
        return [
            Action::make('selectAll')
                ->label(__('Select all'))
                ->view('filament.tables.selection-control', ['acrossPages' => $acrossPages]),
        ];
    }
}
