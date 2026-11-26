<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;

/**
 * One icon and colour per kind of action across the admin, always the
 * outlined Heroicon: Edit, View, Delete / Move to trash / Delete
 * permanently (one trash icon, red; the confirmation says which),
 * Restore and Remove (unlinks only: gray, never red).
 *
 * register() gives Filament's own actions these icons everywhere: in table
 * rows, in "…" menus and as page header buttons (Filament itself leaves
 * header buttons without an icon). An ->icon() set on the action itself
 * still wins.
 */
class ActionIcons
{
    public const EDIT = Heroicon::OutlinedPencilSquare;

    public const VIEW = Heroicon::OutlinedEye;

    public const DELETE = Heroicon::OutlinedTrash;

    public const RESTORE = Heroicon::OutlinedArrowUturnLeft;

    public const REMOVE = Heroicon::OutlinedMinusCircle;

    public const APPROVE = Heroicon::OutlinedCheckBadge;

    public static function register(): void
    {
        $defaults = [
            EditAction::class => [self::EDIT, null],
            ViewAction::class => [self::VIEW, null],
            DeleteAction::class => [self::DELETE, 'danger'],
            DeleteBulkAction::class => [self::DELETE, 'danger'],
            ForceDeleteAction::class => [self::DELETE, 'danger'],
            ForceDeleteBulkAction::class => [self::DELETE, 'danger'],
            RestoreAction::class => [self::RESTORE, 'gray'],
            RestoreBulkAction::class => [self::RESTORE, 'gray'],
            DetachAction::class => [self::REMOVE, 'gray'],
            DetachBulkAction::class => [self::REMOVE, 'gray'],
        ];

        foreach ($defaults as $class => [$icon, $color]) {
            $class::configureUsing(function (Action $action) use ($icon, $color): void {
                $action->icon($icon);

                if ($color !== null) {
                    $action->color($color);
                }
            });
        }
    }

    /** Gives a custom "Remove" action (takes something out of a list, deletes nothing) the remove icon and gray. */
    public static function remove(Action $action): Action
    {
        return $action->icon(self::REMOVE)->color('gray');
    }
}
