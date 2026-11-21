<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Trash\ListTrashedRecords;

/** Albums › Trash: albums moved to the trash, to restore or delete permanently. */
class ListTrashedAlbums extends ListTrashedRecords
{
    protected static string $resource = AlbumResource::class;

    protected function trashExplanation(): string
    {
        return __('Albums in the trash are hidden on the site, and so are photos that are in no other album. Restoring an album brings back the albums inside it that went to the trash with it.');
    }

    protected function emptyTrashText(): string
    {
        return __('Albums you move to the trash appear here until you restore or delete them.');
    }
}
