<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * "View on site": the record as visitors see it, in a new tab. A gray
 * button in page headers; in table rows add ->iconButton() (the label
 * becomes the tooltip). Set the address with ->url().
 *
 *   OpenOnSiteAction::make()->url(fn (Album $record) => route('albums.show', $record))->iconButton()
 */
class OpenOnSiteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'open';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('View on site'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->openUrlInNewTab();
    }
}
