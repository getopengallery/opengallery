<?php

namespace App\Filament\Resources\Albums\Tables;

use App\Enums\Permission;
use App\Enums\Visibility;
use App\Filament\Actions\OpenOnSiteAction;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Schemas\AlbumDeletionForm;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\EmptyState;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Filament\Trash\ListTrashedRecords;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Support\AlbumCollaboration;
use App\Support\DateFormat;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * Albums listed as a tree: sub-albums directly under their parent, indented.
 * Sorting by another column switches to a flat list that shows each album's
 * full path instead. On the trash page (ListTrashedAlbums) the list is flat
 * and offers only Restore and Delete permanently.
 */
class AlbumsTable
{
    public static function configure(Table $table): Table
    {
        $trash = $table->getLivewire() instanceof ListTrashedRecords;

        return $table
            ->defaultSort('tree_path')
            // Drag to change the order among albums at the same level (see ListAlbums::reorderTable).
            ->reorderable('tree_path', fn () => ! $trash && auth()->user()->can(Permission::EditAnyAlbum->value))
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering) => $action
                ->button()
                ->label($isReordering ? __('Done') : __('Change order'))
                ->icon($isReordering ? Heroicon::OutlinedCheck : Heroicon::OutlinedArrowsUpDown))
            ->modifyQueryUsing(fn ($query) => $query->with('parent')->withCount(['photos', 'children']))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Album'))
                    ->searchable()
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('title', $direction))
                    ->html()
                    ->formatStateUsing(fn (Album $record, HasTable $livewire) => self::treeTitle($record, self::isTree($livewire)))
                    // In the tree the counts are part of the indented title block.
                    ->description(fn (Album $record, HasTable $livewire) => match (true) {
                        self::isTree($livewire) => null,
                        $record->parent === null => self::counts($record),
                        default => __('In :album, :counts', ['album' => $record->parent->path(), 'counts' => self::counts($record)]),
                    }),
                TextColumn::make('visibility')
                    ->badge()
                    ->icon(fn (Album $record) => $record->hasPassword() ? Heroicon::LockClosed : null)
                    ->tooltip(fn (Album $record) => $record->hasPassword() ? __('Needs a password') : null)
                    ->description(fn (Album $record) => match (true) {
                        $record->access_expires_at === null => null,
                        $record->accessExpired() => __('Sharing ended :date', ['date' => DateFormat::dateTime($record->access_expires_at)]),
                        default => __('Shared until :date', ['date' => DateFormat::dateTime($record->access_expires_at)]),
                    }),
                TextColumn::make('owner.name')
                    ->label(__('Owner'))
                    ->placeholder(__('No owner'))
                    ->url(fn (Album $record): ?string => UserResource::editUrlFor($record->owner))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('collaborator_role')
                    ->label(__('Your role'))
                    ->state(fn (Album $record) => $record->collaboratorRole(auth()->user()))
                    ->visible(fn (): bool => ! $trash && AlbumCollaboration::albumIdsFor(auth()->user()) !== []),
                TextColumn::make('updated_at')
                    ->label(__('Last changed'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->label(__('In the trash since'))
                    ->dateTime()
                    ->sortable()
                    ->visible($trash),
            ])
            ->filters([
                SelectFilter::make('visibility')
                    ->options(Visibility::class)
                    ->multiple(),
                // Others only see their own albums; the filter would just reveal user names.
                SelectFilter::make('owner')
                    ->label(__('Owner'))
                    ->relationship('owner', 'name')
                    ->searchable()
                    ->visible(fn (): bool => Album::managesEveryAlbum(auth()->user())),
                DateRangeFilter::make('changed')
                    ->label(__('Last changed'))
                    ->column('albums.updated_at'),
            ])
            ->recordActions($trash ? [
                // The row opens the album (read-only while it is in the trash, see EditAlbum).
                TrashActions::restore(),
                self::deletePermanently(TrashActions::deletePermanently()),
            ] : [
                OpenOnSiteAction::make()
                    ->url(fn (Album $record) => route('albums.show', $record))
                    ->iconButton(),
                ActionGroup::make([
                    self::addInside(),
                    self::moveToTrash(TrashActions::moveToTrash()),
                ]),
            ])
            ->toolbarActions([
                // Each album is checked on its own (AlbumPolicy), e.g. moderators may edit but not delete.
                BulkActionGroup::make($trash ? [
                    TrashActions::restoreBulk()->authorizeIndividualRecords('restore'),
                    self::deletePermanently(TrashActions::deletePermanentlyBulk())->authorizeIndividualRecords('forceDelete'),
                ] : [
                    self::moveToTrash(TrashActions::moveToTrashBulk())
                        ->authorizeIndividualRecords('delete')
                        // Collaborators with only the Albums section never trash albums in bulk.
                        ->visible(fn (): bool => auth()->user()->can(Permission::AccessAdmin->value)),
                ]),
            ])
            ->when(! $trash, fn (Table $table) => EmptyState::apply(
                $table,
                __('No albums yet'),
                __('Albums hold your photos and decide who can see them.'),
                Heroicon::OutlinedPhoto,
                [CreateAction::make()->icon(Heroicon::OutlinedFolderPlus)->url(AlbumResource::getUrl('create'))->visible(fn (): bool => AlbumResource::canCreate())],
            ));
    }

    /** "Add album inside": the album form with this album as parent (EditAlbum uses it too). */
    public static function addInside(): Action
    {
        return Action::make('addChild')
            ->label(__('Add album inside'))
            ->icon(Heroicon::OutlinedFolderPlus)
            ->color('gray')
            ->url(fn (Album $record) => AlbumResource::getUrl('create', ['parent' => $record->id]))
            ->visible(fn (Album $record): bool => ! $record->trashed()
                && (AlbumResource::canCreate() || auth()->user()->can('createSubAlbum', $record)));
    }

    /** Says what goes to the trash with the albums (EditAlbum uses it too). */
    public static function moveToTrash(DeleteAction|DeleteBulkAction $action): DeleteAction|DeleteBulkAction
    {
        return $action instanceof BulkAction
            ? $action->modalDescription(fn (Collection $records): string => AlbumDeletionForm::trashDescription(array_values($records->modelKeys())))
            : $action->modalDescription(fn (Album $record): string => AlbumDeletionForm::trashDescription([$record->id]));
    }

    /** Asks what happens to photos in no other album (EditAlbum uses it too). */
    public static function deletePermanently(ForceDeleteAction|ForceDeleteBulkAction $action): ForceDeleteAction|ForceDeleteBulkAction
    {
        return $action instanceof BulkAction
            ? $action
                ->schema(fn (Collection $records): array => AlbumDeletionForm::schema(array_values($records->modelKeys())))
                ->using(function (Collection $records, array $data) {
                    // Before the first album goes, while roles inherited through the selection still exist.
                    AlbumCollaboration::keepCollaboratorUploads(array_values($records->modelKeys()));

                    return $records->each(fn (Album $album) => AlbumDeletionForm::delete($album, $data));
                })
            : $action
                ->schema(fn (Album $record): array => AlbumDeletionForm::schema([$record->id]))
                ->using(fn (Album $record, array $data): bool => AlbumDeletionForm::delete($record, $data));
    }

    /** Tree order is the default sort (no other column chosen) without a search. */
    private static function isTree(HasTable $livewire): bool
    {
        return ! $livewire instanceof ListTrashedRecords && in_array($livewire->getTableSortColumn(), [null, 'tree_path'], true) && blank($livewire->getTableSearch());
    }

    private static function treeTitle(Album $album, bool $tree): HtmlString
    {
        $depth = $tree ? $album->depth() : 0;
        $branch = $depth > 0 ? '<span class="og-tree-branch" aria-hidden="true">└</span>' : '';

        $counts = $tree ? '<span class="og-tree-meta">'.e(self::counts($album)).'</span>' : '';

        return new HtmlString(
            '<span class="og-tree" style="--og-depth: '.$depth.'">'
            .'<span class="og-tree-title">'.$branch.'<span>'.e($album->title).'</span></span>'.$counts.'</span>'
        );
    }

    private static function counts(Album $album): string
    {
        return collect([
            trans_choice('{0} No photos|{1} 1 photo|[2,*] :count photos', $album->photos_count ?? 0),
            ($album->children_count ?? 0) > 0 ? trans_choice('1 album inside|:count albums inside', $album->children_count) : null,
        ])->filter()->implode(', ');
    }
}
