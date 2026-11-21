{{-- Block: auth-shell, layout "Form and image": form left, photo right on wide screens. --}}
@php
    $image = \App\Support\Theme::image('auth_image');
    $caption = \App\Support\Theme::option('auth_caption');
@endphp

<div class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
    <div class="flex flex-col">
        <div class="flex h-16 items-center px-4 sm:px-8">
            <a href="{{ route('home') }}" class="rounded-og"><x-application-logo /></a>
        </div>
        <div class="flex flex-1 items-start justify-center px-4 pt-[6vh] sm:items-center sm:pt-0">
            <div class="w-full max-w-sm pb-24">
                {{ $slot }}
            </div>
        </div>
    </div>

    <div class="relative hidden overflow-hidden bg-surface lg:block">
        @if ($image)
            <img src="{{ $image }}" alt="" class="absolute inset-0 h-full w-full object-cover">
        @endif
        @if ($caption)
            <p class="absolute bottom-6 start-6 end-6 text-sm text-white drop-shadow-[0_1px_2px_rgb(0_0_0/0.6)]">{{ $caption }}</p>
        @endif
    </div>
</div>
