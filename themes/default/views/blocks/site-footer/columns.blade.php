{{-- Block: site-footer, layout "Columns": logo and text, pages, and gallery links. --}}
@php
    $pages = \App\Support\Theme::pages('footer_pages');
    $text = \App\Support\Theme::option('footer_text');
@endphp

<footer class="border-t border-line bg-surface">
    <div class="og-container grid gap-10 py-12 text-sm sm:grid-cols-[2fr_1fr_1fr]">
        <div class="space-y-4">
            <x-application-logo />
            @if ($text)
                <p class="max-w-sm whitespace-pre-line text-muted">{{ $text }}</p>
            @endif
        </div>

        @if ($pages->isNotEmpty())
            <nav aria-labelledby="footer-pages">
                <h2 id="footer-pages" class="font-semibold text-ink">{{ __('theme-default::site.footer_pages_heading') }}</h2>
                <ul class="mt-3 space-y-2">
                    @foreach ($pages as $page)
                        <li><a href="{{ route('pages.show', $page) }}" class="text-muted hover:text-ink">{{ $page->title }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <nav aria-labelledby="footer-gallery">
            <h2 id="footer-gallery" class="font-semibold text-ink">{{ config('app.name') }}</h2>
            <ul class="mt-3 space-y-2">
                @foreach (\App\Support\Navigation::links('none') as $link)
                    <li><a href="{{ $link['href'] }}" class="text-muted hover:text-ink">{{ $link['label'] }}</a></li>
                @endforeach
                @guest
                    <li><a href="{{ route('login') }}" class="text-muted hover:text-ink">{{ __('Log in') }}</a></li>
                @endguest
            </ul>
        </nav>
    </div>
</footer>
