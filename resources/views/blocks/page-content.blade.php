{{-- Block: page-content. Props: page. Body is rich text written in the admin. --}}
<article class="og-prose max-w-2xl">
    {!! \Filament\Forms\Components\RichEditor\RichContentRenderer::make($page->body)
        ->customBlocks([
            \App\Filament\RichEditor\PhotoBlock::class => ['page' => $page],
            \App\Filament\RichEditor\GalleryBlock::class => ['page' => $page],
        ])
        ->toHtml() !!}
</article>
