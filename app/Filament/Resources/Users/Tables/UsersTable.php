<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Actions\ActionIcons;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Schemas\UserDeletionForm;
use App\Filament\Tables\Columns\UserAvatarColumn;
use App\Filament\Tables\EmptyState;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\Group;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountApproval;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                UserAvatarColumn::make(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('E-mail address'))
                    ->searchable()
                    ->description(fn (User $record): ?string => $record->awaiting_approval ? __('Waiting for approval') : null),
                TextColumn::make('roles.name')
                    ->badge()
                    ->url(fn (?Role $relatedRecord): ?string => $relatedRecord !== null && RoleResource::canAccess()
                        ? RoleResource::getUrl('edit', ['record' => $relatedRecord])
                        : null),
                TextColumn::make('groups.name')
                    ->badge()
                    ->color('gray')
                    ->url(fn (?Group $relatedRecord): ?string => $relatedRecord !== null && GroupResource::canAccess()
                        ? GroupResource::getUrl('edit', ['record' => $relatedRecord])
                        : null),
                TextColumn::make('email_verified_at')
                    ->label(__('Verified'))
                    ->dateTime()
                    ->placeholder(__('Not verified'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('roles')
                    ->label(__('Role'))
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('groups')
                    ->label(__('Group'))
                    ->relationship('groups', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Filter::make('awaiting_approval')
                    ->label(__('Waiting for approval'))
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('awaiting_approval', true)),
                TernaryFilter::make('email_verified_at')
                    ->label(__('E-mail address'))
                    ->nullable()
                    ->placeholder(__('All'))
                    ->trueLabel(__('Verified'))
                    ->falseLabel(__('Not verified')),
                DateRangeFilter::make('registered')
                    ->label(__('Registered'))
                    ->column('users.created_at'),
            ])
            ->recordActions([
                // Approving is why people open the list while someone waits, so it is a labelled button.
                self::approveAction(),
                UserDeletionForm::deleteAction()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label(__('Approve'))
                        ->icon(ActionIcons::APPROVE)
                        ->color('success')
                        ->authorizeIndividualRecords('update')
                        ->action(fn (Collection $records) => $records->each(fn (User $record) => AccountApproval::approve($record)))
                        ->deselectRecordsAfterCompletion(),
                    // Skips people the editor may not manage (UserPolicy::delete()).
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->schema(fn (Collection $records): array => UserDeletionForm::schema($records->modelKeys()))
                        ->using(fn (Collection $records, array $data) => $records->each(fn (User $record) => UserDeletionForm::delete($record, $data))),
                ]),
            ])
            ->tap(fn (Table $table) => EmptyState::apply($table, __('No users yet'), null, Heroicon::OutlinedUsers));
    }

    /** Lets an account that signed up while approval is required in (AccountApproval). */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon(ActionIcons::APPROVE)
            ->color('success')
            ->visible(fn (User $record): bool => $record->awaiting_approval && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Approve :name?', ['name' => $record->name]))
            ->modalDescription(__('They can use the gallery right away and are told by e-mail.'))
            ->action(function (User $record): void {
                AccountApproval::approve($record);
                Notification::make()->title(__(':name was approved', ['name' => $record->name]))->success()->send();
            });
    }
}
