<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Filament\Actions\OpenOnSiteAction;
use App\Filament\Notifications\EmailResults;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Pages\Concerns\ManagesCollaborators;
use App\Filament\Resources\Albums\Tables\AlbumsTable;
use App\Filament\Trash\TrashActions;
use App\Models\Album;
use App\Support\AlbumCollaboration;
use App\Support\AlbumSettings;
use App\Support\ShareNotices;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Arr;
use Livewire\Attributes\On;

/**
 * An album's settings and photos. While the album is in the trash the page
 * is read-only: only Restore and Delete permanently are offered.
 * Collaborators use this page too: Editors change the settings, everyone else
 * sees them read-only and works in the Photos section.
 */
class EditAlbum extends EditRecord
{
    use ManagesCollaborators;

    protected static string $resource = AlbumResource::class;

    public function getSubheading(): ?string
    {
        /** @var Album $album */
        $album = $this->getRecord();

        if ($album->trashed()) {
            return __('This album is in the trash and hidden on the site. Restore it to change it.');
        }

        $role = $album->collaboratorRole(auth()->user());

        if ($role === null) {
            return null;
        }

        $yourRole = __('Your role: :role', ['role' => $role->getLabel()]);

        if (AlbumCollaboration::hasDirectRole($album, auth()->user())) {
            return $yourRole;
        }

        $through = AlbumCollaboration::otherAccess($album, auth()->user());

        return $yourRole.' · '.($through['groups'] !== []
            ? __('You have this access through the group :groups.', ['groups' => implode(', ', $through['groups'])])
            : __('You have this access through the parent album :albums.', ['albums' => implode(', ', $through['albums'])]));
    }

    /** Owners and staff, and Editors. Everyone else gets the form read-only. */
    private function editsSettings(): bool
    {
        $album = $this->getRecord();

        return ! $album->trashed() && (auth()->user()->can('update', $album) || auth()->user()->can('editSettings', $album));
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->disabled(fn (): bool => ! $this->editsSettings());
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->hidden(fn (): bool => ! $this->editsSettings());
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->hidden(fn (): bool => ! $this->editsSettings());
    }

    protected function getHeaderActions(): array
    {
        return [
            OpenOnSiteAction::make()
                ->url(fn (Album $record) => route('albums.show', $record))
                ->hidden(fn (Album $record) => $record->trashed()),
            AlbumsTable::addInside(),
            $this->leaveAlbumAction(),
            AlbumsTable::moveToTrash(TrashActions::moveToTrash()),
            TrashActions::restore(),
            AlbumsTable::deletePermanently(TrashActions::deletePermanently()),
        ];
    }

    /** @var list<int> Who could see the album through a share before this save (ShareNotices). */
    private array $sharedWithBefore = [];

    protected function beforeSave(): void
    {
        // The form is read-only in the trash; a save sent anyway changes nothing.
        if ($this->getRecord()->trashed()) {
            $this->halt();
        }

        abort_unless($this->editsSettings(), 403);

        $this->sharedWithBefore = ShareNotices::recipientIds($this->getRecord());
    }

    /** Whether this save chose another cover. */
    private bool $coverChanged = false;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // A role never reaches owner, parent or anything else outside this list.
        if (! auth()->user()->can('update', $this->getRecord())) {
            $data = Arr::only($data, AlbumSettings::EDITOR_FIELDS);
        }

        // Only photos this person may use can become the cover.
        if (filled($data['cover_photo_id'] ?? null) && ! AlbumSettings::coverAllowed($this->getRecord(), (int) $data['cover_photo_id'], auth()->user())) {
            $data['cover_photo_id'] = $this->getRecord()->cover_photo_id;
        }

        $this->coverChanged = array_key_exists('cover_photo_id', $data)
            && (int) $data['cover_photo_id'] !== (int) $this->getRecord()->cover_photo_id;

        return AlbumSettings::normaliseAccess($data);
    }

    /** A cover from elsewhere in the library joins the album, so its visitors can see it. */
    protected function afterSave(): void
    {
        /** @var Album $album */
        $album = $this->getRecord();

        // With a role only a newly chosen cover joins the album, never one already set by someone else.
        if ((auth()->user()->can('update', $album) || $this->coverChanged) && AlbumSettings::keepCoverInAlbum($album)) {
            $this->dispatch('album-photos-changed');
        }

        if ($this->data['notify_shared'] ?? false) {
            EmailResults::shareNotices(ShareNotices::notifyNew($album, $this->sharedWithBefore, auth()->user()));
        }
    }

    /** The Photos section changed the cover ("Use as cover"): show it in the form. */
    #[On('album-cover-updated')]
    public function coverUpdated(): void
    {
        $this->refreshFormData(['cover_photo_id']);
    }
}
