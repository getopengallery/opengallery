<?php

namespace App\Filament\Resources\Photos\Pages\Concerns;

use Livewire\Attributes\Session;

/**
 * Grid (default) or list layout of the media table (PhotosTable); the List /
 * Grid switch in the toolbar (MediaLayoutSwitch) calls setLayout(). Media and
 * its trash share the choice for the session.
 */
trait HasMediaLayout
{
    public const LIST = 'list';

    public const GRID = 'grid';

    #[Session(key: 'media-layout')]
    public string $mediaLayout = self::GRID;

    public function usesGridLayout(): bool
    {
        return $this->mediaLayout === self::GRID;
    }

    public function setLayout(string $layout): void
    {
        $this->mediaLayout = $layout === self::GRID ? self::GRID : self::LIST;

        // Rebuild only the table (keeps search and filters), with the page size of the new layout.
        $this->table = $this->table($this->makeTable());
        $this->tableRecordsPerPage = $this->getTable()->getDefaultPaginationPageOption();
        $this->resetPage();
        $this->flushCachedTableRecords();
    }
}
