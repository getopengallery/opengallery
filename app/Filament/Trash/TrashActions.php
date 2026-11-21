<?php

namespace App\Filament\Trash;

use App\Support\RequestMemo;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The admin's trash, the same for every resource whose model soft deletes
 * (albums, media): the list shows only what is not in the trash and offers
 * a "Trash" button (open()) to the resource's trash page
 * (ListTrashedRecords), which offers only Restore and Delete permanently.
 *
 * Every trash action comes from here, so labels, icons and confirmations
 * read the same everywhere: "Move to trash" (can be undone) and "Delete
 * permanently" (cannot). The actions keep Filament's names (delete,
 * restore, forceDelete) and their authorization.
 */
class TrashActions
{
    /** Only records in the trash ($trashed) or only those outside it, with or without the SoftDeletingScope. */
    public static function scope(Builder $query, bool $trashed): Builder
    {
        $column = $query->getModel()->getQualifiedDeletedAtColumn();

        return $query->withoutGlobalScope(SoftDeletingScope::class)
            ->when($trashed, fn (Builder $query) => $query->whereNotNull($column), fn (Builder $query) => $query->whereNull($column));
    }

    /** RequestMemo key prefix of count(); forgotten whenever a record goes to or leaves the trash (AppServiceProvider). */
    public const COUNT_MEMO = 'trash-count.';

    /**
     * How many records the person may see are in the resource's trash.
     * Counted once per request: the "Trash" button asks several times while
     * a list renders.
     *
     * @param  class-string<resource>  $resource
     */
    public static function count(string $resource): int
    {
        return RequestMemo::instance()->remember(
            self::COUNT_MEMO.$resource.'.'.auth()->id(),
            fn (): int => self::trashFor($resource, $resource::getEloquentQuery())->count(),
        );
    }

    /**
     * Only records in the trash, limited by the resource's
     * `modifyTrashQuery(Builder $query)` if it has one (e.g. photos: only
     * those the person may restore). Used by the trash page and its count.
     *
     * @param  class-string<resource>  $resource
     */
    public static function trashFor(string $resource, Builder $query): Builder
    {
        $query = self::scope($query, true);

        if (method_exists($resource, 'modifyTrashQuery')) {
            $resource::modifyTrashQuery($query);
        }

        return $query;
    }

    /**
     * List page header button to the trash page: an icon with the count
     * (the label is its tooltip and accessible name). Shown
     * while the trash holds something the person may see.
     *
     * @param  class-string<resource>  $resource
     */
    public static function open(string $resource): Action
    {
        return Action::make('trash')
            ->label(__('Trash'))
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->tooltip(__('Trash'))
            ->color('gray')
            ->badge(fn (): ?string => ($count = self::count($resource)) > 0 ? (string) $count : null)
            ->badgeColor('gray')
            ->url(fn (): string => $resource::getUrl('trash'))
            ->visible(fn (): bool => self::count($resource) > 0);
    }

    public static function moveToTrash(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('Move to trash'))
            ->icon(Heroicon::OutlinedTrash)
            ->modalHeading(fn (DeleteAction $action): string => filled($title = $action->getRecordTitle())
                ? __('Move ":title" to the trash?', ['title' => $title])
                : __('Move to the trash?'))
            ->modalDescription(__('You can restore it from the trash.'))
            ->modalSubmitActionLabel(__('Move to trash'))
            ->successNotificationTitle(__('Moved to the trash'));
    }

    public static function moveToTrashBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->label(__('Move to trash'))
            ->icon(Heroicon::OutlinedTrash)
            ->modalHeading(fn (DeleteBulkAction $action): string => __('Move the selected :items to the trash?', ['items' => $action->getPluralModelLabel()]))
            ->modalDescription(__('You can restore them from the trash.'))
            ->modalSubmitActionLabel(__('Move to trash'))
            ->successNotificationTitle(__('Moved to the trash'));
    }

    public static function restore(): RestoreAction
    {
        return RestoreAction::make();
    }

    public static function restoreBulk(): RestoreBulkAction
    {
        return RestoreBulkAction::make();
    }

    public static function deletePermanently(): ForceDeleteAction
    {
        return ForceDeleteAction::make()
            ->label(__('Delete permanently'))
            ->modalHeading(fn (ForceDeleteAction $action): string => filled($title = $action->getRecordTitle())
                ? __('Delete ":title" permanently?', ['title' => $title])
                : __('Delete permanently?'))
            ->modalDescription(__('This cannot be undone.'))
            ->modalSubmitActionLabel(__('Delete permanently'))
            ->successNotificationTitle(__('Deleted permanently'));
    }

    public static function deletePermanentlyBulk(): ForceDeleteBulkAction
    {
        return ForceDeleteBulkAction::make()
            ->label(__('Delete permanently'))
            ->modalHeading(fn (ForceDeleteBulkAction $action): string => __('Delete the selected :items permanently?', ['items' => $action->getPluralModelLabel()]))
            ->modalDescription(__('This cannot be undone.'))
            ->modalSubmitActionLabel(__('Delete permanently'))
            ->successNotificationTitle(__('Deleted permanently'));
    }
}
