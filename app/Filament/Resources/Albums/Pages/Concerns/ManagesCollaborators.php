<?php

namespace App\Filament\Resources\Albums\Pages\Concerns;

use App\Enums\CollaboratorRole;
use App\Filament\Actions\ActionIcons;
use App\Filament\Forms\Components\PeopleAndGroupsSelect;
use App\Filament\Notifications\EmailResults;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Schemas\AlbumForm;
use App\Models\Album;
use App\Models\User;
use App\Support\AlbumCollaboration;
use App\Support\AlbumCollaborators;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The album edit page's Collaborators card. Its changes apply right away and
 * do not touch unsaved edits in the form around it. Actions live on the page
 * because their modals contain forms, which cannot be nested in the album's.
 */
trait ManagesCollaborators
{
    public string $collaboratorSearch = '';

    public int $collaboratorPage = 1;

    public function updatedCollaboratorSearch(): void
    {
        $this->collaboratorPage = 1;
    }

    public function canManageCollaborators(): bool
    {
        /** @var Album $album */
        $album = $this->getRecord();

        return $album->acceptsContributions() && (auth()->user()?->can('manageCollaborators', $album) ?? false);
    }

    /** The card's rows; null for anyone who may not see the list. */
    public function collaboratorEntries(): ?LengthAwarePaginator
    {
        /** @var Album $album */
        $album = $this->getRecord();

        if ($album->isSystem() || ! auth()->user()?->can('viewCollaborators', $album)) {
            return null;
        }

        $entries = AlbumCollaborators::page($album, $this->collaboratorSearch, max(1, $this->collaboratorPage));

        // The last entry of the last page was removed: show the page before it.
        if ($entries->isEmpty() && $entries->currentPage() > 1) {
            $this->collaboratorPage = $entries->lastPage();

            return AlbumCollaborators::page($album, $this->collaboratorSearch, $this->collaboratorPage);
        }

        return $entries;
    }

    public function addCollaboratorsAction(): Action
    {
        return Action::make('addCollaborators')
            ->label(__('Add people or groups'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->canManageCollaborators())
            ->modalHeading(__('Add people or groups'))
            ->modalSubmitActionLabel(__('Add'))
            ->schema(fn (): array => [
                PeopleAndGroupsSelect::make('invitees')
                    ->label(__('People and groups'))
                    ->exceptKeys(fn (): array => $this->keysNotToOffer())
                    ->required(),
                AlbumForm::rolePicker(),
            ])
            ->action(function (array $data): void {
                abort_unless($this->canManageCollaborators(), 403);

                $role = $data['role'] instanceof CollaboratorRole ? $data['role'] : CollaboratorRole::from((string) $data['role']);

                EmailResults::shareNotices(AlbumCollaborators::add($this->getRecord(), $data['invitees'], $role, auth()->user()));
                $this->confirmCollaboratorChange();
            });
    }

    public function removeCollaboratorAction(): Action
    {
        return ActionIcons::remove(Action::make('removeCollaborator'))
            ->label(__('Remove'))
            ->iconButton()
            ->visible(fn (): bool => $this->canManageCollaborators())
            ->requiresConfirmation()
            ->modalHeading(__('Remove from this album?'))
            ->modalDescription(__('They lose the access this role gave them. Their photos stay in the album.'))
            ->modalSubmitActionLabel(__('Remove'))
            ->action(function (array $arguments): void {
                abort_unless($this->canManageCollaborators(), 403);

                AlbumCollaborators::remove($this->getRecord(), (string) ($arguments['key'] ?? ''));
                $this->confirmCollaboratorChange();
            });
    }

    /** Removes the person's own role. A role through a group or a parent album stays, and they are told. */
    public function leaveAlbumAction(): Action
    {
        return Action::make('leaveAlbum')
            ->label(__('Leave album'))
            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('leave', $this->getRecord()) ?? false)
            ->requiresConfirmation()
            ->modalHeading(__('Leave this album?'))
            ->modalSubmitActionLabel(__('Leave album'))
            ->action(function (): void {
                /** @var Album $album */
                $album = $this->getRecord();
                /** @var User $user */
                $user = auth()->user();
                abort_unless($user->can('leave', $album), 403);

                $album->collaborators()->detach($user->id);

                $message = $this->leftAlbumMessage($album, $user);

                if ($user->can('workOn', $album)) {
                    Notification::make()->title($message)->success()->send();
                    $this->redirect(AlbumResource::getUrl('edit', ['record' => $album]));
                } elseif ($user->opensAdmin()) {
                    Notification::make()->title($message)->success()->send();
                    $this->redirect(AlbumResource::getUrl());
                } else {
                    session()->flash('collaboration_status', $message);
                    $this->redirect(route('dashboard'));
                }
            });
    }

    private function leftAlbumMessage(Album $album, User $user): string
    {
        $remaining = AlbumCollaboration::otherAccess($album, $user);

        return match (true) {
            $remaining['groups'] !== [] => __('You left ":album", but you still have access through the group :groups.', ['album' => $album->title, 'groups' => implode(', ', $remaining['groups'])]),
            $remaining['albums'] !== [] => __('You left ":album", but you still have access through the parent album :albums.', ['album' => $album->title, 'albums' => implode(', ', $remaining['albums'])]),
            default => __('You left ":album".', ['album' => $album->title]),
        };
    }

    /** The role dropdown of a row. */
    public function changeCollaboratorRole(string $key, string $role): void
    {
        abort_unless($this->canManageCollaborators(), 403);

        $role = CollaboratorRole::tryFrom($role);
        abort_if($role === null, 422);

        EmailResults::shareNotices(AlbumCollaborators::changeRole($this->getRecord(), $key, $role, auth()->user()));
        $this->confirmCollaboratorChange();
    }

    /** The card sits in a form with its own Save button, so say that this is already saved. */
    private function confirmCollaboratorChange(): void
    {
        Notification::make()->title(__('Collaborators updated'))->success()->send();
    }

    /** @return list<string> */
    private function keysNotToOffer(): array
    {
        /** @var Album $album */
        $album = $this->getRecord();

        return [
            ...AlbumCollaborators::keys($album),
            ...($album->owner_id ? ['user:'.$album->owner_id] : []),
        ];
    }
}
