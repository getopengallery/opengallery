<?php

namespace App\Filament\Resources\Photos\Pages;

use App\Filament\Resources\Photos\Pages\Concerns\HasMediaLayout;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Trash\ListTrashedRecords;

/** Media › Trash: photos moved to the trash, to restore or delete permanently. */
class ListTrashedPhotos extends ListTrashedRecords
{
    use HasMediaLayout;

    protected static string $resource = PhotoResource::class;

    protected function trashExplanation(): string
    {
        return __('Photos in the trash are hidden on the site and removed from their albums. Restoring a photo puts it back in its albums.');
    }

    protected function emptyTrashText(): string
    {
        return __('Photos you move to the trash appear here until you restore or delete them.');
    }
}
