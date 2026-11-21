<?php

namespace App\Filament\Resources\Photos\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Session;

/**
 * Grid (default) or list layout of the media table (PhotosTable), with the
 * "List" / "Grid" header buttons. Media and its trash share the choice for
 * the session.
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

    /** @return list<Action> */
    protected function mediaLayoutActions(): array
    {
        return [
            Action::make('listLayout')
                ->label(__('List'))
                ->icon(Heroicon::OutlinedListBullet)
                ->color(fn () => $this->mediaLayout === self::LIST ? 'primary' : 'gray')
                ->outlined(fn () => $this->mediaLayout !== self::LIST)
                ->extraAttributes(fn () => ['aria-pressed' => $this->mediaLayout === self::LIST ? 'true' : 'false'])
                ->action(fn () => $this->setLayout(self::LIST)),
            Action::make('gridLayout')
                ->label(__('Grid'))
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color(fn () => $this->mediaLayout === self::GRID ? 'primary' : 'gray')
                ->outlined(fn () => $this->mediaLayout !== self::GRID)
                ->extraAttributes(fn () => ['aria-pressed' => $this->mediaLayout === self::GRID ? 'true' : 'false'])
                ->action(fn () => $this->setLayout(self::GRID)),
        ];
    }
}
