<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Opens the media pool picker (App\Livewire\MediaPicker) in a modal.
 * Configure per use, e.g.:
 *
 *   PickMediaAction::make('addFromLibrary')
 *       ->pickerKey('album-photos-12')
 *       ->multiple()
 *       ->scopeAlbum($album->id, 'exclude')
 *       ->uploadTo('album:12')
 *       ->confirmLabel(__('Add to album'))
 *       ->onPicked('$wire.call("addPickedPhotos", ids)');
 *
 * When photos are chosen the modal closes and the JavaScript in onPicked()
 * runs with `ids` (photo ids) and `items` ({id, thumb, title}) in scope.
 * Form fields use MediaPickerField, which wires this up for you.
 */
class PickMediaAction extends Action
{
    protected string|Closure|null $pickerKey = null;

    protected bool|Closure $isMultiple = true;

    protected string|Closure|null $confirmLabel = null;

    protected int|Closure|null $scopeAlbumId = null;

    protected string|Closure|null $scope = null;

    protected string|Closure|null $uploadTo = null;

    protected string|Closure|null $onPicked = null;

    protected int|Closure|null $limitToAlbumId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon(Heroicon::OutlinedPhoto)
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Cancel'))
            ->modalDescription(fn () => $this->evaluate($this->isMultiple)
                ? __('Click photos to select them, then choose ":action".', ['action' => $this->getConfirmLabel()])
                : __('Click a photo, then choose ":action".', ['action' => $this->getConfirmLabel()]))
            ->modalContent(fn () => view('filament.media-picker.modal', [
                'key' => $this->getPickerKey(),
                'multiple' => (bool) $this->evaluate($this->isMultiple),
                'confirmLabel' => $this->getConfirmLabel(),
                'scopeAlbumId' => $this->evaluate($this->scopeAlbumId),
                'scope' => $this->evaluate($this->scope),
                'uploadTo' => $this->evaluate($this->uploadTo),
                'onPicked' => $this->evaluate($this->onPicked),
                'limitToAlbumId' => $this->evaluate($this->limitToAlbumId),
            ]));
    }

    public function pickerKey(string|Closure $key): static
    {
        $this->pickerKey = $key;

        return $this;
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function confirmLabel(string|Closure $label): static
    {
        $this->confirmLabel = $label;

        return $this;
    }

    public function getConfirmLabel(): string
    {
        return (string) ($this->evaluate($this->confirmLabel) ?? __('Use selected photos'));
    }

    /** @param 'only'|'exclude' $mode */
    public function scopeAlbum(int|Closure|null $albumId, string|Closure $mode = 'only'): static
    {
        $this->scopeAlbumId = $albumId;
        $this->scope = $mode;

        return $this;
    }

    /** "album:{id}", "inbox" or "page-images"; null disables uploading. */
    public function uploadTo(string|Closure|null $target): static
    {
        $this->uploadTo = $target;

        return $this;
    }

    /** Only this album's photos and the person's own uploads (AlbumCollaboration::pickerAlbumId()). */
    public function limitToAlbum(int|Closure|null $albumId): static
    {
        $this->limitToAlbumId = $albumId;

        return $this;
    }

    /** JavaScript run on the page with `ids` and `items` when photos are chosen. */
    public function onPicked(string|Closure|null $js): static
    {
        $this->onPicked = $js;

        return $this;
    }

    public function getPickerKey(): string
    {
        return (string) ($this->evaluate($this->pickerKey) ?? $this->getName());
    }
}
