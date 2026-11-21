<x-guest-layout>
    <x-slot:title>{{ __('Link expired') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('This link has expired') }}</h1>
    <p class="mt-2 text-muted">
        {{ __('":album" was shared until :date. Ask the person who sent you the link for access.', ['album' => $album->title, 'date' => \App\Support\DateFormat::dateTime($album->access_expires_at)]) }}
    </p>

    <a href="{{ route('home') }}" class="og-btn-secondary mt-8">{{ __('Go to the gallery') }}</a>
</x-guest-layout>
