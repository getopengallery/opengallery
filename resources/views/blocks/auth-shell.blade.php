{{--
    Block: auth-shell, layout "Centered": the frame around login, registration
    and password pages. Core fallback; themes add layouts as
    views/blocks/auth-shell/{variant} (the default theme ships "split" and "cover").
--}}
<div class="flex min-h-screen flex-col">
    <div class="og-container flex h-16 items-center">
        <a href="{{ route('home') }}" class="rounded-og"><x-application-logo /></a>
    </div>

    <div class="flex flex-1 items-start justify-center px-4 pt-[8vh] sm:items-center sm:pt-0">
        <div class="w-full max-w-sm pb-24">
            {{ $slot }}
        </div>
    </div>
</div>
