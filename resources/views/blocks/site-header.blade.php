{{--
    Block: site-header, layout "Classic": logo left, links and account right.
    Core fallback; themes add layouts as views/blocks/site-header/{variant}
    (the default theme ships "centered" and "minimal") or replace this file.
--}}
@php
    $links = \App\Support\Navigation::links();
    $modeToggle = \App\Support\Theme::enabled('header_mode_toggle');
@endphp

<header x-data="{ open: false }" class="og-header border-b border-line">
    <div class="og-container flex h-16 items-center justify-between gap-6">
        <a href="{{ route('home') }}" class="rounded-og">
            <x-application-logo />
        </a>

        <nav class="hidden items-center gap-1 md:flex" aria-label="{{ __('Main') }}">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" @class([
                    'rounded-og px-3 py-2 text-sm font-medium',
                    'text-ink' => $link['active'],
                    'text-muted hover:text-ink' => ! $link['active'],
                ]) @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
            @endforeach

            @if ($links)
                <span class="mx-2 h-5 w-px bg-line" aria-hidden="true"></span>
            @endif

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
</header>
