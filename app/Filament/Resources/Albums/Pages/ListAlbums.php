<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Support\ActivityLog;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ListAlbums extends ListRecords
{
    protected static string $resource = AlbumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashActions::open(AlbumResource::class),
            CreateAction::make()->icon(Heroicon::OutlinedFolderPlus),
        ];
    }

    /**
     * Drag-and-drop in the albums list changes only the order among albums
     * at the same level (never the parent): each album gets its place among
     * its siblings, following the order in which they were dropped.
     *
     * @param  array<int, int|string>  $order  album ids in their new order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        abort_unless(auth()->user()->can(Permission::EditAnyAlbum->value), 403);

        $albums = Album::withTrashed()->whereKey($order)->get()->keyBy('id');
        $positions = [];
        $after = [];
        // The order each level had on screen before the drag (renumbering alone is no change).
        $before = $albums->sortBy('tree_path')->groupBy(fn (Album $album) => (string) $album->parent_id)
            ->map(fn ($siblings) => $siblings->pluck('id')->all());

        DB::transaction(function () use ($order, $albums, &$positions, &$after) {
            foreach ($order as $id) {
                $album = $albums->get((int) $id);
                if (! $album) {
                    continue;
                }

                $level = (string) $album->parent_id;
                $positions[$level] = ($positions[$level] ?? 0) + 1;

                if ($album->sort_order !== $positions[$level]) {
                    $album->forceFill(['sort_order' => $positions[$level]])->saveQuietly();
                }

                $after[$level][] = $album->id;
            }

            // Recompute tree paths once all positions are known (parents first).
            $albums->sortBy(fn (Album $album) => $album->depth())->each(fn (Album $album) => $album->refreshTreePath());
        });

        // One entry per level whose order changed (positions are saved quietly above).
        foreach ($after as $level => $ids) {
            if ($ids !== ($before[$level] ?? [])) {
                ActivityLog::albumsReordered($level !== '' ? Album::withTrashed()->find((int) $level) : null);
            }
        }

        $this->flushCachedTableRecords();
    }
}
