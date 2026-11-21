{{-- Block: site-footer (Scrapbook theme). Torn zig-zag edge, the site name scribbled on, page links as tags. --}}
@php
    $pages = \App\Support\Theme::pages('footer_pages');
    $text = \App\Support\Theme::option('footer_text', __('theme-scrapbook::site.footer_tagline'));
@endphp

<footer class="sb-footer sb-doodled">
    @include('blocks.partials.scrapbook-doodles', ['flip' => true])

    <div class="og-container sb-footer-inner">
        <div>
            <p class="sb-footer-name">{{ config('app.name') }}</p>
            <p class="sb-footer-text">{{ $text }}</p>
        </div>

        @if ($pages->isNotEmpty())
            <nav aria-label="{{ __('Footer') }}">
                <ul role="list" class="sb-footer-links">
                    @foreach ($pages as $page)
                        <li><a href="{{ route('pages.show', $page) }}" class="sb-tag">{{ $page->title }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
</footer>
