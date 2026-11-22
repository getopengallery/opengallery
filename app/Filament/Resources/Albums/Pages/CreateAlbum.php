<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\Visibility;
use App\Filament\Notifications\EmailResults;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use App\Models\Photo;
use App\Support\ActivityLog;
use App\Support\AlbumCollaborators;
use App\Support\AlbumSettings;
use App\Support\ShareNotices;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

/**
 * Create an album together with its first photos and cover, chosen in the
 * media picker (uploads made there wait in "Unsorted uploads" until now).
 *
 * "Add album inside" on an album someone works on through a role opens this
 * page too. Such a sub-album belongs to the parent's owner, so the tree stays
 * with one owner, and starts private.
 */
class CreateAlbum extends CreateRecord
{
    protected static string $resource = AlbumResource::class;

    /** The album this one goes into when the person creates it through a role. */
    #[Locked]
    public ?int $sharedParentId = null;

    /** @var list<int> */
    private array $photoIds = [];

    public function mount(): void
    {
        $parent = Album::find(request()->integer('parent'));

        if ($parent && ! auth()->user()->can('update', $parent) && auth()->user()->can('createSubAlbum', $parent)) {
            $this->sharedParentId = $parent->id;
        }

        parent::mount();
    }

    protected function authorizeAccess(): void
    {
        if ($this->sharedParentId === null) {
            parent::authorizeAccess();

            return;
        }

        abort_unless($this->sharedParent() !== null, 403);
    }

    public function createsInsideSharedAlbum(): bool
    {
        return $this->sharedParentId !== null;
    }

    public function canCreateAnother(): bool
    {
        return ! $this->createsInsideSharedAlbum() && parent::canCreateAnother();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->createsInsideSharedAlbum()
            ? __('New album inside :album', ['album' => $this->sharedParent()?->title])
            : parent::getTitle();
    }

    /** The parent, while the person may still create albums in it through their role. */
    private function sharedParent(): ?Album
    {
        $parent = Album::find($this->sharedParentId);

        return $parent && auth()->user()->can('createSubAlbum', $parent) ? $parent : null;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($this->createsInsideSharedAlbum()) {
            $parent = $this->sharedParent();
            abort_unless($parent !== null, 403);

            return [
                ...Arr::only($data, ['title', 'description', 'photo_order', 'tags_apply_to_sub_albums']),
                'parent_id' => $parent->id,
                'owner_id' => $parent->owner_id,
                'visibility' => Visibility::Private,
            ];
        }

        // Keep only photos this person may place here, in the order they were chosen.
        $visible = function (array $ids): array {
            $allowed = Photo::visibleTo(auth()->user())->reusableBy(auth()->user())->whereKey($ids)->pluck('id')->all();

            return array_values(array_filter($ids, fn (int $id) => in_array($id, $allowed, true)));
        };

        $this->photoIds = $visible(array_map('intval', (array) ($data['photo_ids'] ?? [])));
        $cover = filled($data['cover_photo_id'] ?? null) ? $visible([(int) $data['cover_photo_id']])[0] ?? null : null;

        $data['cover_photo_id'] = $cover;
        unset($data['photo_ids']);

        if (! auth()->user()->can(Permission::EditAnyAlbum->value)) {
            $data['owner_id'] = auth()->id();
        }

        return AlbumSettings::normaliseAccess($data);
    }

    protected function afterCreate(): void
    {
        if ($this->createsInsideSharedAlbum()) {
            return;
        }

        /** @var Album $album */
        $album = $this->getRecord();
        $ids = array_values(array_unique(array_filter([...$this->photoIds, $album->cover_photo_id])));

        // Order as chosen, cover included (it must be in the album to be visible).
        $album->addPhotos($ids);
        ActivityLog::photosAdded($album, count($ids));

        if ($this->data['notify_shared'] ?? false) {
            EmailResults::shareNotices(ShareNotices::notifyNew($album, [], auth()->user()));
        }

        $invitees = (array) ($this->data['invitees'] ?? []);

        if ($invitees !== [] && auth()->user()->can('manageCollaborators', $album)) {
            $role = CollaboratorRole::tryFrom((string) ($this->data['invitee_role'] ?? '')) ?? CollaboratorRole::Contributor;

            EmailResults::shareNotices(AlbumCollaborators::add($album, $invitees, $role, auth()->user()));
        }
    }
}
