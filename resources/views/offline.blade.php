{{--
    Shown by the service worker (public/sw.js) when a page cannot be loaded
    without a connection. Kept on the device, so it must not show anything
    personal. Themes can replace it with views/offline.blade.php.
--}}
<x-guest-layout>
    <x-slot:title>{{ __('You are offline') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('You are offline') }}</h1>
    <p class="mt-2 text-muted">
        {{ __('This page could not be loaded because there is no internet connection. Check your connection and try again.') }}
    </p>

    <button type="button" class="og-btn-primary mt-8" onclick="location.reload()">{{ __('Try again') }}</button>
</x-guest-layout>
