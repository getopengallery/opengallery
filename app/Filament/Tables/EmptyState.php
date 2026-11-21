<?php

namespace App\Filament\Tables;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The admin's empty states. While a list is really empty it says what the
 * list is for and offers the action that fills it ("No albums yet" + "New
 * album"). While a search or filter hides every row it says so instead
 * ("Nothing matches") and leaves the action out.
 *
 *   ->tap(fn (Table $table) => EmptyState::apply($table, __('No groups yet'), $description, Heroicon::OutlinedUserGroup, [$create]))
 */
class EmptyState
{
    /** @param  list<Action>  $actions  shown only while the list is really empty */
    public static function apply(Table $table, string $heading, ?string $description, string|BackedEnum $icon, array $actions = []): Table
    {
        return $table
            ->emptyStateIcon($icon)
            ->emptyStateHeading(fn (HasTable $livewire): string => self::isNarrowed($livewire) ? __('Nothing matches') : $heading)
            ->emptyStateDescription(fn (HasTable $livewire): ?string => self::isNarrowed($livewire) ? __('Change the search or the filters.') : $description)
            ->emptyStateActions(array_map(
                fn (Action $action): Action => $action->hidden(fn (HasTable $livewire): bool => self::isNarrowed($livewire)),
                $actions,
            ));
    }

    /** A search or a filter is on, so the list may only look empty. */
    public static function isNarrowed(HasTable $livewire): bool
    {
        return $livewire->hasTableSearch() || $livewire->getTable()->isFiltered();
    }
}
