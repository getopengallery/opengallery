{{-- Block: auth-shell, layout "Form on an image": the form on a card over a full-screen photo. --}}
@php
    $image = \App\Support\Theme::image('auth_image');
    $caption = \App\Support\Theme::option('auth_caption');
@endphp

<div class="relative isolate flex min-h-screen flex-col bg-[#1A1A1A]">
    @if ($image)
        <img src="{{ $image }}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover">
    @endif
    <div class="absolute inset-0 -z-10 bg-black/35" aria-hidden="true"></div>

    <div class="flex flex-1 items-center justify-center px-4 py-10">
        <div class="w-full max-w-md rounded-og bg-canvas p-8 shadow-2xl shadow-black/30 sm:p-10">
            <a href="{{ route('home') }}" class="mb-8 inline-block rounded-og"><x-application-logo /></a>
            {{ $slot }}
        </div>
    </div>

    @if ($caption)
        <p class="px-6 pb-6 text-center text-sm text-white/85">{{ $caption }}</p>
    @endif
</div>
