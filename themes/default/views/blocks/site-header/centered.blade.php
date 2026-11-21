{{-- Block: site-header, layout "Centered": logo centred above a row of links. --}}
@php
    $links = \App\Support\Navigation::links();
    $modeToggle = \App\Support\Theme::enabled('header_mode_toggle');
@endphp

<header x-data="{ open: false }" class="og-header border-b border-line">
    <div class="og-container relative flex flex-col items-center pt-7 md:pb-2">
        <a href="{{ route('home') }}" class="rounded-og">
            <x-application-logo class="scale-110" />
        </a>

        <div class="absolute end-4 top-6 flex items-center gap-1 sm:end-6 lg:end-8">
            <x-notification-bell />
            @if ($modeToggle)
                <x-theme-mode-toggle />
            @endif
            <div class="hidden md:flex md:items-center">
                @include('blocks.partials.account')
            </div>
            <div class="md:hidden">
                @include('blocks.partials.menu-button')
            </div>
        </div>

        <nav class="mt-4 hidden flex-wrap justify-center gap-1 md:flex" aria-label="{{ __('Main') }}">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" @class([
                    'border-b-2 px-3 pb-3 pt-1 text-sm font-medium',
                    'border-ink text-ink' => $link['active'],
                    'border-transparent text-muted hover:text-ink' => ! $link['active'],
                ]) @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
            @endforeach
        </nav>
    </div>

    <div class="mt-4 md:hidden">
        @include('blocks.partials.menu-panel')
    </div>
</header>
