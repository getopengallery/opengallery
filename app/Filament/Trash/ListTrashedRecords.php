<?php

namespace App\Filament\Trash;

use App\Filament\Tables\EmptyState;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A resource's trash page ("Albums › Trash"): the resource's own table,
 * limited to records in the trash and sorted by when they went there. The
 * resource's table checks `$livewire instanceof ListTrashedRecords` to offer
 * only Restore and Delete permanently here (TrashActions).
 *
 * Register it as the resource's "trash" page, before any "/{record}" route:
 *
 *   'trash' => ListTrashedAlbums::route('/trash'),
 */
abstract class ListTrashedRecords extends ListRecords
{
    /** What the trash means for this kind of record, shown under the title. */
    abstract protected function trashExplanation(): string;

    /** Shown when the trash is empty, under "The trash is empty". */
    abstract protected function emptyTrashText(): string;

    public function getSubheading(): ?string
    {
        return $this->trashExplanation();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Trash');
    }

    public function getBreadcrumb(): ?string
    {
        return __('Trash');
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return EmptyState::apply(
            $table
                ->modifyQueryUsing(fn (Builder $query) => TrashActions::trashFor(static::getResource(), $query))
                ->defaultSort((new ($table->getModel()))->getQualifiedDeletedAtColumn(), 'desc'),
            __('The trash is empty'),
            $this->emptyTrashText(),
            Heroicon::OutlinedTrash,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('Back to :items', ['items' => static::getResource()::getNavigationLabel()]))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(static::getResource()::getUrl()),
        ];
    }
}
