<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\Columns\UserAvatarColumn;
use App\Filament\Tables\EmptyState;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The users linked to a record (group members, role holders): searchable
 * and paginated, so thousands of users stay usable. Users are found by name
 * or e-mail when adding; nothing is preloaded. Subclasses override the wording methods.
 */
abstract class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Users');
    }

    protected function addLabel(): string
    {
        return __('Add users');
    }

    protected function removeHeading(): string
    {
        return __('Remove');
    }

    protected function emptyHeading(): string
    {
        return __('No users yet');
    }

    /** For subclasses that do more when people are added. */
    protected function configureAttachAction(AttachAction $action): AttachAction
    {
        return $action;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->users()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                UserAvatarColumn::make(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->url(fn (User $record): ?string => UserResource::editUrlFor($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('E-mail address'))
                    ->searchable()
                    ->color('gray'),
            ])
            ->paginated([25, 50, 100])
            ->headerActions([
                $this->configureAttachAction(AttachAction::make()
                    ->label($this->addLabel())
                    ->modalHeading($this->addLabel())
                    ->modalSubmitActionLabel(__('Add'))
                    ->attachAnother(false)
                    ->multiple()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelect(fn (Select $select) => $select->placeholder(__('Search people by name or e-mail')))),
            ])
            // Removing only unlinks the person: gray with a minus, never red.
            ->recordActions([
                DetachAction::make()
                    ->label(__('Remove'))
                    ->iconButton()
                    ->modalHeading($this->removeHeading()),
            ])
            ->toolbarActions([
                DetachBulkAction::make()
                    ->label($this->removeHeading()),
            ])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                $this->emptyHeading(),
                __('Use ":action" to find people by name or e-mail.', ['action' => $this->addLabel()]),
                Heroicon::OutlinedUsers,
            ));
    }
}
