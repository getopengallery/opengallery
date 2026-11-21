{{-- Block: site-header, layout "Minimal": logo and a menu button on every screen size. --}}
<header x-data="{ open: false }" @keydown.escape.window="open = false" class="og-header">
    <div class="og-container flex h-16 items-center justify-between">
        <a href="{{ route('home') }}" class="rounded-og">
            <x-application-logo />
        </a>

        <div class="flex items-center gap-1">
            <x-notification-bell />
            @if (\App\Support\Theme::enabled('header_mode_toggle'))
                <x-theme-mode-toggle />
            @endif
            @include('blocks.partials.menu-button')
        </div>
    </div>

    @include('blocks.partials.menu-panel')
</header>
