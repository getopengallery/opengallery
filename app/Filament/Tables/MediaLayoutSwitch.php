<?php

namespace App\Filament\Tables;

use App\Filament\Resources\Photos\Pages\Concerns\HasMediaLayout;
use Illuminate\Support\HtmlString;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;

/**
 * The media library's List / Grid switch, at the end of the table toolbar
 * (render hook TablesRenderHook::TOOLBAR_END), for pages using HasMediaLayout.
 */
class MediaLayoutSwitch
{
    public static function render(): ?HtmlString
    {
        $page = ExtendBlade::currentRendering();

        if ($page === null || ! in_array(HasMediaLayout::class, class_uses_recursive($page), true)) {
            return null;
        }

        return new HtmlString(view('filament.tables.layout-switch', [
            'current' => $page->mediaLayout,
            'layouts' => [
                $page::LIST => [__('List'), 'heroicon-o-list-bullet'],
                $page::GRID => [__('Grid'), 'heroicon-o-squares-2x2'],
            ],
        ])->render());
    }
}
