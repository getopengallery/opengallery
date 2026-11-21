{{--
    Block: auth-shell (Scrapbook theme). The login, registration and password
    forms on a taped-in sheet of lined notebook paper, with a note underneath.
--}}
@php($caption = \App\Support\Theme::option('auth_caption', __('theme-scrapbook::site.auth_caption')))

<div class="sb-auth sb-doodled">
    @include('blocks.partials.scrapbook-doodles')

    <div class="og-container flex h-16 items-center">
        <a href="{{ route('home') }}" class="sb-logo-sticker"><x-application-logo /></a>
    </div>

    <div class="sb-auth-stage">
        <div class="sb-auth-card">
            <span class="sb-tape" aria-hidden="true"></span>
            {{ $slot }}
        </div>

        <p class="sb-auth-caption">{{ $caption }}</p>
    </div>
</div>
