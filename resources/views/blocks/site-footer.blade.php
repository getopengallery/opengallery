{{--
    Block: site-footer, layout "One line": site name and text left, page links right.
    Core fallback; themes add layouts as views/blocks/site-footer/{variant}
    (the default theme ships "columns" and "none").
--}}
@php
    $pages = \App\Support\Theme::pages('footer_pages');
    $text = \App\Support\Theme::option('footer_text');
@endphp

<footer class="border-t border-line">
    <div class="og-container flex flex-col gap-4 py-8 text-caption text-muted sm:flex-row sm:items-start sm:justify-between">
        <p class="max-w-xl whitespace-pre-line">{{ $text ?? config('app.name') }}</p>

        @if ($pages->isNotEmpty())
            <nav aria-label="{{ __('Footer') }}">
                <ul class="flex flex-wrap gap-x-5 gap-y-2">
                    @foreach ($pages as $page)
                        <li><a href="{{ route('pages.show', $page) }}" class="hover:text-ink">{{ $page->title }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
</footer>
