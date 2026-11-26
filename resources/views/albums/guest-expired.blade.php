<x-guest-layout>
    <x-slot:title>{{ __('Link expired') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('This link no longer works') }}</h1>
    <p class="mt-2 text-muted">
        {{ __('Your access to ":album" has ended. Ask the person who shared it with you for access.', ['album' => $album->title]) }}
    </p>

    <a href="{{ route('home') }}" class="og-btn-secondary mt-8">{{ __('Go to the gallery') }}</a>
</x-guest-layout>
