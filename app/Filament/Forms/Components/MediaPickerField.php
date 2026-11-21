<?php

namespace App\Filament\Forms\Components;

use App\Filament\Actions\PickMediaAction;
use App\Models\Photo;
use Closure;
use Filament\Forms\Components\Field;

/**
 * Form field for choosing photos from the media pool. State: a photo id
 * (single) or a list of photo ids (multiple), in the order chosen.
 *
 *   MediaPickerField::make('cover_photo_id')->chooseLabel(__('Choose cover'))
 *   MediaPickerField::make('photo_ids')->multiple()->uploadTo('inbox')
 *
 * Shows the chosen photos (one: large preview with Change / Remove;
 * several: removable thumbnails) and opens the shared picker
 * (PickMediaAction → App\Livewire\MediaPicker).
 */
class MediaPickerField extends Field
{
    protected string $view = 'filament.forms.media-picker-field';

    protected bool|Closure $isMultiple = false;

    protected string|Closure|null $chooseLabel = null;

    protected string|Closure|null $confirmLabel = null;

    protected string|Closure|null $pickerHeading = null;

    protected string|Closure|null $emptyText = null;

    protected int|Closure|null $scopeAlbumId = null;

    protected string|Closure|null $scope = null;

    protected string|Closure|null $uploadTo = null;

    protected int|Closure|null $limitToAlbumId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default(fn () => $this->isMultiple() ? [] : null);

        $this->dehydrateStateUsing(fn ($state) => $this->isMultiple()
            ? array_values(array_unique(array_map('intval', array_filter((array) $state))))
            : (filled($state) ? (int) $state : null));

        $this->registerActions([
            fn (MediaPickerField $component) => PickMediaAction::make('pick')
                ->label($component->getChooseLabel())
                ->color('gray')
                ->pickerKey($component->getStatePath())
                ->multiple($component->isMultiple())
                ->confirmLabel($component->getConfirmLabel())
                ->modalHeading($component->getPickerHeading())
                ->scopeAlbum($component->evaluate($this->scopeAlbumId), (string) ($component->evaluate($this->scope) ?? 'only'))
                ->uploadTo($component->evaluate($this->uploadTo))
                ->limitToAlbum($component->evaluate($this->limitToAlbumId))
                ->disabled($component->isDisabled()),
        ]);
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function chooseLabel(string|Closure|null $label): static
    {
        $this->chooseLabel = $label;

        return $this;
    }

    public function confirmLabel(string|Closure|null $label): static
    {
        $this->confirmLabel = $label;

        return $this;
    }

    public function pickerHeading(string|Closure|null $heading): static
    {
        $this->pickerHeading = $heading;

        return $this;
    }

    public function emptyText(string|Closure|null $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    /** @param 'only'|'exclude' $mode */
    public function scopeAlbum(int|Closure|null $albumId, string|Closure $mode = 'only'): static
    {
        $this->scopeAlbumId = $albumId;
        $this->scope = $mode;

        return $this;
    }

    /** "album:{id}", "inbox" or "page-images"; null disables uploading in the picker. */
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

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function getChooseLabel(): string
    {
        return $this->evaluate($this->chooseLabel) ?? ($this->isMultiple() ? __('Add photos') : __('Choose photo'));
    }

    public function getConfirmLabel(): string
    {
        return $this->evaluate($this->confirmLabel) ?? ($this->isMultiple() ? __('Use selected photos') : __('Use this photo'));
    }

    public function getPickerHeading(): string
    {
        return $this->evaluate($this->pickerHeading) ?? ($this->isMultiple() ? __('Choose photos') : __('Choose a photo'));
    }

    public function getEmptyText(): string
    {
        return $this->evaluate($this->emptyText) ?? ($this->isMultiple() ? __('No photos chosen yet.') : __('No photo chosen yet.'));
    }

    /** @return list<array{id: int, thumb: ?string, title: ?string}> the chosen photos, in order */
    public function getItems(): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->getState())));

        if ($ids === []) {
            return [];
        }

        return Photo::with('media')->whereKey($ids)->get()
            ->sortBy(fn (Photo $photo) => array_search($photo->id, $ids, true))
            ->map(fn (Photo $photo) => [
                'id' => $photo->id,
                'title' => $photo->title,
                'thumb' => $photo->mediaUrl('thumb'),
                'ratio' => $photo->aspectRatio(),
            ])
            ->values()
            ->all();
    }
}
