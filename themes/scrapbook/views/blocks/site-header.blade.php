{{--
    Block: site-header (Scrapbook theme). Bunting along the top, the logo on a
    tilted sticker, links as colourful sticker pills and an optional scrolling
    ticker underneath. Uses the shared account and menu partials from core.
--}}
@php
    $links = \App\Support\Navigation::links();
    $modeToggle = \App\Support\Theme::enabled('header_mode_toggle');
    $ticker = \App\Support\Theme::enabled('ticker');
    $tickerText = \App\Support\Theme::option('ticker_text', __('theme-scrapbook::site.ticker'));
@endphp

<header x-data="{ open: false }" class="og-header sb-header">
    <div class="sb-bunting" aria-hidden="true">
        @for ($flag = 0; $flag < 80; $flag++)
            <span></span>
        @endfor
    </div>

    <div class="og-container sb-header-bar">
        <a href="{{ route('home') }}" class="sb-logo-sticker">
            <x-application-logo />
        </a>

        <nav class="sb-nav hidden md:flex" aria-label="{{ __('Main') }}">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" class="sb-nav-link" @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
            @endforeach

            <x-notification-bell />

            @if ($modeToggle)
                <x-theme-mode-toggle />
            @endif

            @include('blocks.partials.account')
        </nav>

        <div class="flex items-center gap-1 md:hidden">
            <x-notification-bell />
            @if ($modeToggle)
                <x-theme-mode-toggle />
            @endif
            @include('blocks.partials.menu-button')
        </div>
    </div>

    <div class="md:hidden">
        @include('blocks.partials.menu-panel')
    </div>

    @if ($ticker)
        {{-- Decorative, so screen readers skip it; the text is shown twice for a seamless loop. --}}
        <div class="sb-ticker" aria-hidden="true">
            <div class="sb-ticker-track">
                @for ($copy = 0; $copy < 4; $copy++)
                    <span>{{ $tickerText }}</span>
                @endfor
            </div>
        </div>
    @endif
</header>
