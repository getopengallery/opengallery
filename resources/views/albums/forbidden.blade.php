<x-guest-layout>
    <x-slot:title>{{ __('No access') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('You can\'t open this album') }}</h1>
    <p class="mt-2 text-muted">
        {{ __('It has not been shared with you. Ask the person who sent you the link, or the people who run this gallery, to share it with you.') }}
    </p>

    <a href="{{ route('home') }}" class="og-btn-secondary mt-8">{{ __('Go to the gallery') }}</a>
</x-guest-layout>
