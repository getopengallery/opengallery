<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\EmptyState;
use App\Models\User;
use App\Models\UserSession;
use App\Support\UserSessions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The browsers where a person is signed in, on their edit page, for people
 * who may edit them (UserResource::canEdit). Signing out tells the person.
 */
class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Sessions');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return UserSessions::available() && $ownerRecord instanceof User && UserResource::canEdit($ownerRecord);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var User $ownerRecord */
        return (string) UserSessions::query($ownerRecord)->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->active())
            ->columns([
                TextColumn::make('user_agent')
                    ->label(__('Device'))
                    ->formatStateUsing(fn (UserSession $record): string => $record->deviceLabel())
                    ->placeholder(__('Unknown device'))
                    ->description(fn (UserSession $record): ?string => $record->isCurrent() ? __('This device') : null),
                TextColumn::make('last_activity')
                    ->label(__('Last active'))
                    ->formatStateUsing(fn (UserSession $record): string => $record->lastActiveAt()->diffForHumans())
                    ->tooltip(fn (UserSession $record): string => $record->lastActiveAt()->isoFormat('LLL'))
                    ->color('gray'),
            ])
            ->paginated([25, 50, 100])
            ->headerActions([
                Action::make('signOutEverywhere')
                    ->label(__('Sign out everywhere'))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->authorize(fn (): bool => UserResource::canEdit($this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => __('Sign :name out everywhere?', ['name' => $this->getOwnerRecord()->name]))
                    ->modalDescription(__('Every browser is signed out, including ones that remember them. They are told about it.'))
                    ->modalSubmitActionLabel(__('Sign out everywhere'))
                    ->action(function (): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();
                        $current = $user->is(auth()->user()) ? request()->session()->getId() : null;
                        UserSessions::signOutAll($user, $current, auth()->user());

                        Notification::make()->success()->title(__('Signed out everywhere.'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('signOut')
                    ->label(__('Sign out'))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->iconButton()
                    ->color('gray')
                    ->authorize(fn (): bool => UserResource::canEdit($this->getOwnerRecord()))
                    ->hidden(fn (UserSession $record): bool => $record->isCurrent())
                    ->requiresConfirmation()
                    ->modalHeading(__('Sign out this device?'))
                    ->modalSubmitActionLabel(__('Sign out'))
                    ->action(function (UserSession $record): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();
                        UserSessions::signOut($user, $record->id, auth()->user());

                        Notification::make()->success()->title(__('Device signed out.'))->send();
                    }),
            ])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                __('Not signed in anywhere'),
                __('Browsers where this person signs in are listed here.'),
                Heroicon::OutlinedComputerDesktop,
            ));
    }
}
